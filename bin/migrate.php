<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$environment = static function (string $key, ?string $default = null): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException(sprintf('Missing required environment variable: %s', $key));
    }

    return (string) $value;
};

try {
    $database = $environment('DB_DATABASE');
    $port = filter_var($environment('DB_PORT', '3306'), FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException('DB_PORT must be an integer between 1 and 65535.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $environment('DB_HOST', 'mysql'),
        $port,
        $database
    );
    $pdo = new PDO($dsn, $environment('DB_USERNAME'), $environment('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (' .
        'version VARCHAR(191) NOT NULL PRIMARY KEY, ' .
        'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $migrationFiles = glob(dirname(__DIR__) . '/migrations/*.sql');
    if ($migrationFiles === false) {
        throw new RuntimeException('Could not list migration files.');
    }
    sort($migrationFiles, SORT_STRING);

    $findMigration = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
    $recordMigration = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
    $appliedCount = 0;

    foreach ($migrationFiles as $migrationFile) {
        $version = pathinfo($migrationFile, PATHINFO_FILENAME);
        $sql = file_get_contents($migrationFile);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException(sprintf('Migration is empty or unreadable: %s', $version));
        }

        $findMigration->execute(['version' => $version]);
        if ($findMigration->fetchColumn() !== false) {
            continue;
        }

        $pdo->exec($sql);
        $recordMigration->execute(['version' => $version]);
        $appliedCount++;
        printf("Applied migration: %s\n", $version);
    }

    if ($appliedCount === 0) {
        fwrite(STDOUT, "No pending migrations.\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Migration failed: %s\n", $exception->getMessage()));
    exit(1);
}