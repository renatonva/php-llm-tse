<?php

declare(strict_types=1);

namespace Tests;

use App\Repository\ElectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ResultadoEleicaoIntegrationTest extends TestCase
{
    public function testGovernorRankingForAc2022TurnOne(): void
    {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: 'mysql',
                getenv('DB_PORT') ?: '3306',
                getenv('DB_DATABASE') ?: 'elections'
            ),
            getenv('DB_USERNAME') ?: 'elections',
            getenv('DB_PASSWORD') ?: 'elections_dev_only',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $result = (new ElectionRepository($pdo))->ranking(2022, 'AC', 'Governador', 1);

        self::assertArrayHasKey('resultados', $result);
        self::assertSame('GLADSON CAMELI', $result['resultados'][0]['candidato']);
        self::assertSame('242100', (string) $result['resultados'][0]['votos']);
        self::assertSame('JORGE VIANA', $result['resultados'][1]['candidato']);
        self::assertSame('103265', (string) $result['resultados'][1]['votos']);
        self::assertSame('MARA ROCHA', $result['resultados'][2]['candidato']);
        self::assertSame('47173', (string) $result['resultados'][2]['votos']);
    }
}