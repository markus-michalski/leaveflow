<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace App\Presentation\Controller\Profile;

use App\Application\TwoFactor\BackupCodeGenerator;
use App\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile/2fa', name: 'app_profile_2fa_')]
#[IsGranted('ROLE_USER')]
final class TwoFactorController extends AbstractController
{
    private const string CODES_SESSION_KEY = '_2fa_backup_codes_plaintext';

    public function __construct(
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
        private readonly BackupCodeGenerator $codeGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Step 1 of the setup flow: show a fresh QR code + manual secret so
     * the user can scan it with their authenticator app. The candidate
     * secret lives only in the session until step 2 confirms it — that
     * way an abandoned setup doesn't leave a half-configured User row.
     */
    #[Route('/setup', name: 'setup', methods: ['GET'])]
    public function setup(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_profile');
        }

        $session = $request->getSession();
        /** @var string|null $candidate */
        $candidate = $session->get('2fa_setup_secret');
        if (null === $candidate) {
            $candidate = $this->totpAuthenticator->generateSecret();
            $session->set('2fa_setup_secret', $candidate);
        }

        $qrSvg = $this->renderQrCode($this->buildOtpauthUrl($user->getEmail(), $candidate));

        return $this->render('profile/2fa/setup.html.twig', [
            'secret' => $candidate,
            'qrSvg' => $qrSvg,
        ]);
    }

    /**
     * Builds the otpauth:// URI that authenticator apps consume. We
     * synthesize it directly rather than asking the TotpAuthenticator
     * service so the candidate secret stays in the session only — the
     * User entity never sees it until the user proves they can read it
     * back from their phone.
     *
     * Parameters mirror config/packages/scheb_2fa.yaml so the QR-issued
     * configuration matches what {@see TotpConfiguration} validates
     * against on login.
     */
    private function buildOtpauthUrl(string $email, string $secretBase32): string
    {
        return \sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode('LeaveFlow'),
            rawurlencode($email),
            $secretBase32,
            rawurlencode('LeaveFlow'),
        );
    }

    /**
     * Step 2: verify the 6-digit code against the in-session candidate
     * secret, persist it on the user, generate backup codes, and show
     * them exactly once. The plaintext is never recoverable after this
     * response — users must download / write down the codes here.
     */
    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_2fa_confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->requireUser();
        $session = $request->getSession();
        /** @var string|null $candidate */
        $candidate = $session->get('2fa_setup_secret');
        if (null === $candidate) {
            return $this->redirectToRoute('app_profile_2fa_setup');
        }

        $code = trim((string) $request->request->get('code'));
        $user->setTotpSecret($candidate);
        if (!$this->totpAuthenticator->checkCode($user, $code)) {
            $user->setTotpSecret(null);
            $this->entityManager->detach($user);
            $this->addFlash('error', $this->translator->trans('profile.2fa.flash.code_invalid'));

            return $this->redirectToRoute('app_profile_2fa_setup');
        }

        $bundle = $this->codeGenerator->generate(10);
        $user->enableTotp($candidate, $bundle->hashedCodes);
        $this->entityManager->flush();
        $session->remove('2fa_setup_secret');

        // The plaintext codes hop through the session to the next page
        // and the codesShown action eats them on first read — so a
        // browser back-button or session restore can't re-fetch them
        // after the user leaves the page.
        $session->set(self::CODES_SESSION_KEY, $bundle->plaintextCodes);

        return $this->redirectToRoute('app_profile_2fa_codes_shown');
    }

    #[Route('/codes', name: 'codes_shown', methods: ['GET'])]
    public function codesShown(Request $request): Response
    {
        $session = $request->getSession();
        $raw = $session->get(self::CODES_SESSION_KEY);
        if (!\is_array($raw) || [] === $raw) {
            return $this->redirectToRoute('app_profile');
        }
        /** @var list<string> $codes */
        $codes = array_values(array_filter($raw, 'is_string'));
        $session->remove(self::CODES_SESSION_KEY);

        return $this->render('profile/2fa/codes_shown.html.twig', [
            'codes' => $codes,
        ]);
    }

    /**
     * Regenerates backup codes. Requires the user to already have 2FA
     * enabled and to confirm with a current TOTP code so a hijacked
     * session can't simply rotate the recovery path away from them.
     */
    #[Route('/regenerate-codes', name: 'regenerate_codes', methods: ['POST'])]
    public function regenerateCodes(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_2fa_regenerate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->requireUser();
        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_profile');
        }

        $code = trim((string) $request->request->get('code'));
        if (!$this->totpAuthenticator->checkCode($user, $code)) {
            $this->addFlash('error', $this->translator->trans('profile.2fa.flash.code_invalid'));

            return $this->redirectToRoute('app_profile');
        }

        $bundle = $this->codeGenerator->generate(10);
        $user->replaceBackupCodes($bundle->hashedCodes);
        $this->entityManager->flush();

        $request->getSession()->set(self::CODES_SESSION_KEY, $bundle->plaintextCodes);

        return $this->redirectToRoute('app_profile_2fa_codes_shown');
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_2fa_disable', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->requireUser();
        if (!$user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_profile');
        }

        $code = trim((string) $request->request->get('code'));
        if (!$this->totpAuthenticator->checkCode($user, $code)) {
            $this->addFlash('error', $this->translator->trans('profile.2fa.flash.code_invalid'));

            return $this->redirectToRoute('app_profile');
        }

        $user->disableTotp();
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('profile.2fa.flash.disabled'));

        return $this->redirectToRoute('app_profile');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function renderQrCode(string $content): string
    {
        // endroid/qr-code v6 dropped the fluent Builder::create() in favor
        // of named constructor args — every option is passed up front.
        $builder = new Builder(
            writer: new SvgWriter(),
            data: $content,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        );

        return $builder->build()->getString();
    }
}
