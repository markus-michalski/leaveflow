<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Api\ApiTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiTokenManagerInterface $tokenManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            && $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('Authorization header must use Bearer scheme.');
        }

        $rawToken = substr($authHeader, 7);

        if ('' === trim($rawToken)) {
            throw new CustomUserMessageAuthenticationException('Bearer token must not be empty.');
        }

        return new SelfValidatingPassport(
            new UserBadge($rawToken, function (string $rawToken): ApiUser {
                $apiToken = $this->tokenManager->findActiveByRawToken($rawToken);

                if (null === $apiToken) {
                    throw new CustomUserMessageAuthenticationException('Invalid or expired API token.');
                }

                return new ApiUser(
                    apiTokenId: (int) $apiToken->getId(),
                    companyId: (int) $apiToken->getCompany()->getId(),
                    companyName: $apiToken->getCompany()->getName(),
                );
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Continue processing the request.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => 'Unauthorized', 'message' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="LeaveFlow API"'],
        );
    }
}
