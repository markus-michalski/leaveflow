<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\EncryptionService;
use App\Application\Security\LdapUserData;
use App\Application\Security\LdapUserResolver;
use App\Domain\Enum\AuthSource;
use App\Domain\Repository\CompanyRepository;
use App\Domain\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Exception\LdapException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class LdapAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly UserRepository $userRepository,
        private readonly LdapUserResolver $userResolver,
        private readonly RouterInterface $router,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function supports(Request $request): bool
    {
        if ('app_login' !== $request->attributes->get('_route') || !$request->isMethod('POST')) {
            return false;
        }

        $company = $this->companyRepository->findOneBy([]);
        if (null === $company || !$company->isLdapEnabled()) {
            return false;
        }

        // Local users (e.g. the bootstrapped admin) must always be able to log in
        // regardless of LDAP being enabled — don't intercept them.
        $username = $request->request->getString('_username');
        $existingUser = $this->userRepository->findOneByEmail($username);

        return null === $existingUser || AuthSource::Local !== $existingUser->getAuthSource();
    }

    public function authenticate(Request $request): Passport
    {
        $username = $request->request->getString('_username');
        $password = $request->request->getString('_password');
        $csrfToken = $request->request->getString('_csrf_token');

        $company = $this->companyRepository->findOneBy([]);
        if (null === $company) {
            throw new BadCredentialsException();
        }

        $host = $company->getLdapHost() ?? 'localhost';
        $port = $company->getLdapPort() ?? 389;
        $encryption = $company->getLdapEncryption() ?? 'none';

        $scheme = 'ssl' === $encryption ? 'ldaps' : 'ldap';
        $connectionString = \sprintf('%s://%s:%d', $scheme, $host, $port);

        try {
            $ldap = Ldap::create('ext_ldap', [
                'connection_string' => $connectionString,
                'encryption' => 'tls' === $encryption ? 'tls' : 'none',
            ]);

            // Service-account bind for user search (anonymous if no bind DN configured)
            $bindDn = $company->getLdapBindDn();
            $encryptedPassword = $company->getLdapBindPassword();
            $bindPassword = null !== $encryptedPassword ? $this->encryption->tryDecrypt($encryptedPassword) : null;
            if (null !== $bindDn) {
                $ldap->bind($bindDn, (string) $bindPassword);
            } else {
                $ldap->bind();
            }

            $baseDn = $company->getLdapBaseDn() ?? '';
            $filterTemplate = $company->getLdapUserFilter() ?? '(uid={username})';
            $filter = str_replace('{username}', ldap_escape($username, '', \LDAP_ESCAPE_FILTER), $filterTemplate);

            $query = $ldap->query($baseDn, $filter, ['filter' => ['dn', 'mail', 'displayName', 'memberOf']]);
            $entries = $query->execute()->toArray();

            if (0 === \count($entries)) {
                throw new BadCredentialsException();
            }

            $entry = $entries[0];
            $userDn = $entry->getDn();

            // Verify user credentials by binding with their DN + submitted password
            $ldap->bind($userDn, $password);

            $mailAttr = $entry->hasAttribute('mail') ? $entry->getAttribute('mail') : null;
            $email = (null !== $mailAttr && isset($mailAttr[0])) ? strtolower(trim((string) $mailAttr[0])) : null;
            if (null === $email || '' === $email) {
                throw new BadCredentialsException();
            }

            $displayNameAttr = $entry->hasAttribute('displayName') ? $entry->getAttribute('displayName') : null;
            $displayName = (null !== $displayNameAttr && isset($displayNameAttr[0])) ? (string) $displayNameAttr[0] : null;
            /** @var list<string> $memberOf */
            $memberOf = $entry->hasAttribute('memberOf') ? array_values($entry->getAttribute('memberOf') ?? []) : [];

            $ldapUser = new LdapUserData($userDn, $email, $displayName, $memberOf);
        } catch (InvalidCredentialsException) {
            throw new BadCredentialsException();
        } catch (ConnectionException $e) {
            throw new AuthenticationException('ldap_connection_error', 0, $e);
        } catch (LdapException $e) {
            throw new AuthenticationException('ldap_connection_error', 0, $e);
        }

        return new SelfValidatingPassport(
            new UserBadge($username, fn () => $this->userResolver->resolve($ldapUser)),
            [new CsrfTokenBadge('authenticate', $csrfToken), new RememberMeBadge()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $exception->getMessageKey());
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}
