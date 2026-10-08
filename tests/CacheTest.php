<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Cache;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    public function testNormalizesQueryAndVersionsItsHash(): void
    {
        self::assertSame('quem ganhou em mg', Cache::normalize("  QUEM   GANHOU em MG  "));
        self::assertSame(Cache::hashFor('Eleição em São Paulo'), Cache::hashFor('ELEICAO EM SAO PAULO'));
        self::assertSame(64, strlen(Cache::hashFor('consulta')));
    }

    public function testCacheHitUsesNormalizedVersionedHashAndExpiryPredicate(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(['hash' => Cache::hashFor(' QUEM ganhou em MG? ')]);
        $statement->method('fetchColumn')->willReturn('resposta');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'expira_em > CURRENT_TIMESTAMP')))
            ->willReturn($statement);

        self::assertSame('resposta', (new Cache($pdo))->get(' QUEM ganhou em MG? '));
    }

    public function testPutSetsPastElectionExpiry(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (array $params): bool {
                $expiry = new \DateTimeImmutable($params['expires_at']);
                $delta = $expiry->getTimestamp() - time();
                return $params['hash'] === Cache::hashFor('eleição 2022')
                    && $delta > 31535900
                    && $delta <= 31536000;
            }));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        (new Cache($pdo, 60, 31536000))->put('eleição 2022', 'resposta');
    }

    public function testPutSetsShortExpiryForActiveElectionYear(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (array $params): bool {
                $delta = (new \DateTimeImmutable($params['expires_at']))->getTimestamp() - time();
                return $delta > 299 && $delta <= 300;
            }));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        (new Cache($pdo, 60, 31536000, 300))->put('eleição 2026', 'resposta');
    }
}