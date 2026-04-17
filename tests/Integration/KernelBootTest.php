<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class KernelBootTest extends KernelTestCase
{
    #[Test]
    public function kernelBootsInTestEnvironment(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('test', $kernel->getEnvironment());
        self::assertInstanceOf(Kernel::class, $kernel);
    }

    #[Test]
    public function databaseConnectionWorks(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        $result = $connection->executeQuery('SELECT 1 AS ok')->fetchAssociative();

        self::assertSame(['ok' => 1], $result);
    }
}
