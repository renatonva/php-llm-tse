<?php

declare(strict_types=1);

use App\Tse\Client;
use App\Tse\CsvReader;
use App\Tse\Importer;
use App\Support\Cache;
use App\Support\TotalsBuilder;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

function optionValue(array $arguments, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
}

try {
    $arguments = array_slice($argv, 1);
    foreach ($arguments as $argument) {
        if (!preg_match('/^--(?:ano|uf|tipo)=.+$/', $argument)) {
            throw new RuntimeException('Parâmetros inválidos. Use --ano=AAAA [--uf=UF] [--tipo=candidatos|votacao|tudo].');
        }
    }
    $yearValue = optionValue($arguments, 'ano');
    if ($yearValue === null || !preg_match('/^\d{4}$/', $yearValue)) {
        throw new RuntimeException('Informe --ano com quatro dígitos.');
    }
    $year = (int) $yearValue;
    $uf = optionValue($arguments, 'uf');
    if ($uf !== null && (!preg_match('/^[A-Z]{2}$/', $uf) || !in_array($uf, CsvReader::supportedUfs(), true))) {
        throw new RuntimeException('--uf deve ser uma UF válida com duas letras maiúsculas.');
    }
    $type = optionValue($arguments, 'tipo') ?? 'tudo';
    if (!in_array($type, ['candidatos', 'votacao', 'tudo'], true)) {
        throw new RuntimeException('--tipo deve ser candidatos, votacao ou tudo.');
    }

    $environment = static function (string $key, ?string $default = null): string {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException('Variável de ambiente ausente: ' . $key);
        }
        return (string) $value;
    };
    $port = filter_var($environment('DB_PORT', '3306'), FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException('DB_PORT inválida.');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $environment('DB_HOST', 'mysql'),
        $port,
        $environment('DB_DATABASE')
    );
    $pdo = new PDO($dsn, $environment('DB_USERNAME'), $environment('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $root = dirname(__DIR__);
    $client = new Client($root . '/data');
    $reader = new CsvReader();
    $importer = new Importer($pdo, $reader, $root . '/logs/import_rejeitados.log');
    $totals = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'rejected' => 0];
    $startedAt = microtime(true);

    if ($type === 'candidatos' || $type === 'tudo') {
        $candidateZip = $client->getZipPath('consulta_cand', $year);
        $result = $importer->importCandidates($candidateZip, $year, $uf);
        foreach ($totals as $key => $_) {
            $totals[$key] += $result[$key];
        }
    }
    if ($type === 'votacao' || $type === 'tudo') {
        $voteZip = $client->getZipPath('votacao_candidato_munzona', $year);
        $result = $importer->importVoting($voteZip, $year, $uf);
        foreach ($totals as $key => $_) {
            $totals[$key] += $result[$key];
        }
    }

    if ($type === 'candidatos') {
        (new TotalsBuilder($pdo))->rebuild($year, $uf);
    }
    (new Cache(
        $pdo,
        (int) $environment('CACHE_TTL_SECONDS', '3600'),
        (int) $environment('CACHE_PAST_ELECTION_TTL_SECONDS', '31536000'),
        (int) $environment('CACHE_ACTIVE_ELECTION_TTL_SECONDS', '300')
    ))->clearYear($year);

    printf(
        "Linhas lidas: %d | inseridas: %d | atualizadas: %d | rejeitadas: %d | tempo: %.2fs\n",
        $totals['read'],
        $totals['inserted'],
        $totals['updated'],
        $totals['rejected'],
        microtime(true) - $startedAt
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}