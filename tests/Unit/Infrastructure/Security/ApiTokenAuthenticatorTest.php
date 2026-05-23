<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Api\ApiTokenManagerInterface;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Company;
use App\Infrastructure\Security\ApiTokenAuthenticator;
use App\Infrastructure\Security\ApiUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

#[CoversClass(ApiTokenAuthenticator::class)]
#[CoversClass(ApiUser::class)]
#[AllowMockObjectsWithoutExpectations]
final class ApiTokenAuthenticatorTest extends TestCase
{
    private ApiTokenManagerInterface&MockObject $tokenManager;
    private ApiTokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(ApiTokenManagerInterface::class);
        $this->authenticator = new ApiTokenAuthenticator($this->tokenManager);
    }

    #[Test]
    public function supportsApiPathWithAuthorizationHeader(): void
    {
        $request = Request::create('/api/v1/employees');
        $request->headers->set('Authorization', 'Bearer sometoken');

        self::assertTrue($this->authenticator->supports($request));
    }

    #[Test]
    public function doesNotSupportApiPathWithoutAuthorizationHeader(): void
    {
        $request = Request::create('/api/v1/employees');

        self::assertFalse($this->authenticator->supports($request));
    }

    #[Test]
    public function doesNotSupportNonApiPath(): void
    {
        $request = Request::create('/admin/employees');
        $request->headers->set('Authorization', 'Bearer sometoken');

        self::assertFalse($this->authenticator->supports($request));
    }

    #[Test]
    public function authenticateThrowsOnNonBearerScheme(): void
    {
        $request = Request::create('/api/v1/employees');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Bearer scheme');

        $this->authenticator->authenticate($request);
    }

    #[Test]
    public function authenticateThrowsOnEmptyToken(): void
    {
        $request = Request::create('/api/v1/employees');
        $request->headers->set('Authorization', 'Bearer   ');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->authenticator->authenticate($request);
    }

    #[Test]
    public function userBadgeLoaderThrowsForInvalidToken(): void
    {
        $this->tokenManager->method('findActiveByRawToken')->willReturn(null);

        $request = Request::create('/api/v1/employees');
        $request->headers->set('Authorization', 'Bearer invalidtoken');

        $passport = $this->authenticator->authenticate($request);
        $badge = $passport->getBadge(UserBadge::class);
        self::assertNotNull($badge);
        $loader = $badge->getUserLoader();
        self::assertNotNull($loader);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired');

        $loader('invalidtoken');
    }

    #[Test]
    public function userBadgeLoaderReturnsApiUserForValidToken(): void
    {
        $company = new Company('Acme GmbH');
        $apiToken = new ApiToken(
            company: $company,
            name: 'HR System',
            tokenHash: hash('sha256', 'validtoken'),
            createdAt: new \DateTimeImmutable(),
        );

        $this->tokenManager->method('findActiveByRawToken')->willReturn($apiToken);

        $request = Request::create('/api/v1/employees');
        $request->headers->set('Authorization', 'Bearer validtoken');

        $passport = $this->authenticator->authenticate($request);
        $badge = $passport->getBadge(UserBadge::class);
        self::assertNotNull($badge);
        $loader = $badge->getUserLoader();
        self::assertNotNull($loader);

        $apiUser = $loader('validtoken');

        self::assertInstanceOf(ApiUser::class, $apiUser);
        self::assertSame(['ROLE_API'], $apiUser->getRoles());
        self::assertSame('Acme GmbH', $apiUser->getCompanyName());
        self::assertSame(0, $apiUser->getCompanyId()); // unsaved entity → getId() = null → cast 0
    }

    #[Test]
    public function onAuthenticationFailureReturns401Json(): void
    {
        $request = Request::create('/api/v1/employees');
        $exception = new CustomUserMessageAuthenticationException('Invalid token.');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Unauthorized', (string) $response->getContent());
        self::assertTrue($response->headers->has('WWW-Authenticate'));
    }
}
