<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs a series of low-level checks before the first-run wizard
 * allows a user to bootstrap the tenant. Every check returns a
 * RequirementCheck DTO with `label`, `status`, `detail`, and an
 * optional `remedy` string (copy-pasteable commands).
 *
 * Hard-coded thresholds match the project's composer.json
 * (php >= 8.4) and the runtime extensions used by Symfony, Doctrine,
 * and the upload/QR pipelines. Lowering them silently here would
 * not catch real production breakage — keep them in lock-step.
 */
final readonly class SystemRequirementsChecker
{
    private const string REQUIRED_PHP_VERSION = '8.4.0';

    /**
     * @var list<string>
     */
    private const array REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'intl',
        'gd',
        'mbstring',
        'json',
        'curl',
        'ctype',
        'fileinfo',
    ];

    public function __construct(
        private Connection $databaseConnection,
        private DependencyFactory $migrationsFactory,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * @return list<RequirementCheck>
     */
    public function check(): array
    {
        return [
            $this->checkPhpVersion(),
            $this->checkExtensions(),
            $this->checkDatabaseConnection(),
            $this->checkMigrationsCurrent(),
            $this->checkWritablePath('var/', 'var directory'),
            $this->checkWritablePath('public/uploads/', 'public/uploads directory'),
        ];
    }

    public function allPass(): bool
    {
        foreach ($this->check() as $check) {
            if ($check->isBlocking()) {
                return false;
            }
        }

        return true;
    }

    private function checkPhpVersion(): RequirementCheck
    {
        $required = self::REQUIRED_PHP_VERSION;
        $actual = \PHP_VERSION;
        if (version_compare($actual, $required, '>=')) {
            return new RequirementCheck(
                label: 'PHP version',
                status: RequirementStatus::Pass,
                detail: \sprintf('%s (>= %s required)', $actual, $required),
            );
        }

        return new RequirementCheck(
            label: 'PHP version',
            status: RequirementStatus::Fail,
            detail: \sprintf('Found PHP %s, but %s or newer is required.', $actual, $required),
            remedy: 'Upgrade PHP to '.$required.' or newer (or rebuild the Docker image from the latest leaveflow/php base).',
        );
    }

    private function checkExtensions(): RequirementCheck
    {
        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => !\extension_loaded($ext),
        ));

        if ([] === $missing) {
            return new RequirementCheck(
                label: 'PHP extensions',
                status: RequirementStatus::Pass,
                detail: 'All required extensions loaded ('.implode(', ', self::REQUIRED_EXTENSIONS).').',
            );
        }

        return new RequirementCheck(
            label: 'PHP extensions',
            status: RequirementStatus::Fail,
            detail: 'Missing: '.implode(', ', $missing).'.',
            remedy: 'Install the missing extensions on the PHP runtime (Debian: `apt-get install php-'.implode(' php-', $missing).'`).',
        );
    }

    private function checkDatabaseConnection(): RequirementCheck
    {
        try {
            // `connect()` is protected in modern Doctrine DBAL; issue a
            // cheap query through the public API to force a real round-trip
            // without leaking the internal Connection state.
            $this->databaseConnection->executeQuery('SELECT 1')->free();
            $platform = $this->databaseConnection->getDatabasePlatform()::class;
            $shortName = (string) (strrchr($platform, '\\') ?: $platform);

            return new RequirementCheck(
                label: 'Database connection',
                status: RequirementStatus::Pass,
                detail: 'Connected to '.ltrim($shortName, '\\').'.',
            );
        } catch (\Throwable $e) {
            return new RequirementCheck(
                label: 'Database connection',
                status: RequirementStatus::Fail,
                detail: 'Could not connect: '.$e->getMessage(),
                remedy: 'Check the DATABASE_URL env var (Docker: review compose.yaml + .env). Make sure the database service is reachable from the app container.',
            );
        }
    }

    private function checkMigrationsCurrent(): RequirementCheck
    {
        try {
            $aliasResolver = $this->migrationsFactory->getVersionAliasResolver();
            $statusCalculator = $this->migrationsFactory->getMigrationStatusCalculator();

            $latest = $aliasResolver->resolveVersionAlias('latest');
            $current = $aliasResolver->resolveVersionAlias('current');
            $newMigrations = $statusCalculator->getNewMigrations();

            if (0 === \count($newMigrations) && $current->__toString() === $latest->__toString()) {
                return new RequirementCheck(
                    label: 'Database migrations',
                    status: RequirementStatus::Pass,
                    detail: 'All migrations applied (latest: '.$latest->__toString().').',
                );
            }

            return new RequirementCheck(
                label: 'Database migrations',
                status: RequirementStatus::Fail,
                detail: \sprintf(
                    '%d migration(s) pending. Latest applied: %s, available: %s.',
                    \count($newMigrations),
                    $current->__toString() ?: '(none)',
                    $latest->__toString(),
                ),
                remedy: 'Run `bin/console doctrine:migrations:migrate --no-interaction` in the app container.',
            );
        } catch (\Throwable $e) {
            return new RequirementCheck(
                label: 'Database migrations',
                status: RequirementStatus::Fail,
                detail: 'Could not read migration status: '.$e->getMessage(),
                remedy: 'Verify the database is reachable and the migrations table can be created.',
            );
        }
    }

    private function checkWritablePath(string $relativePath, string $label): RequirementCheck
    {
        $absolute = rtrim($this->projectDir, '/').'/'.$relativePath;

        if (!is_dir($absolute)) {
            // Try to create it — uploads/ is allowed to be missing on a fresh clone.
            if (!@mkdir($absolute, 0o755, true) && !is_dir($absolute)) {
                return new RequirementCheck(
                    label: $label,
                    status: RequirementStatus::Fail,
                    detail: 'Directory does not exist and could not be created: '.$relativePath,
                    remedy: \sprintf('Create the directory and make it writable: `mkdir -p %s && chmod u+w %s`.', $relativePath, $relativePath),
                );
            }
        }

        if (!is_writable($absolute)) {
            return new RequirementCheck(
                label: $label,
                status: RequirementStatus::Fail,
                detail: 'Directory exists but is not writable: '.$relativePath,
                remedy: \sprintf('Make it writable for the PHP-FPM user: `chmod -R u+w %s` (Docker: the `app` user owns it).', $relativePath),
            );
        }

        return new RequirementCheck(
            label: $label,
            status: RequirementStatus::Pass,
            detail: 'Writable: '.$relativePath,
        );
    }
}
