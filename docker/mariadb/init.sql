-- Grant the app user permissions on any database matching `app%`,
-- so PHPUnit can create `app_test` (and `app_test_*` for parallel runs).
CREATE DATABASE IF NOT EXISTS `app_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `app%`.* TO 'app'@'%';
FLUSH PRIVILEGES;
