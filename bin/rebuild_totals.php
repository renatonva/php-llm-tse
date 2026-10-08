<?php

declare(strict_types=1);

use App\Support\TotalsBuilder;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$env = static function (string $key, string $default): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false || $value === null ? $default : (string) $value;
};

try {
    $year = null;
    $uf = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--ano=(\d{4})$/', $argument, $m)) {
            $year = (int) $m[1];
        } elseif (preg_match('/^--uf=([A-Z]{2})$/', $argument, $m)) {
            $uf = $m[1];
        } else {
            throw new RuntimeException('Uso: php bin/rebuild_totals.php [--ano=AAAA] [--uf=UF]');
        }
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $env('DB_HOST', 'mysql'), (int) $env('DB_PORT', '3306'), $env('DB_DATABASE', 'elections')),
        $env('DB_USERNAME', 'elections'),
        $env('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $started = microtime(true);
    $rows = (new TotalsBuilder($pdo))->rebuild($year, $uf);
    printf("Totais gravados: %d | tempo: %.2fs\n", $rows, microtime(true) - $started);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
