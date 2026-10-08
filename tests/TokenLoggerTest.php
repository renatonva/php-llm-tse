<?php

declare(strict_types=1);

namespace Tests;

use App\Support\TokenLogger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class TokenLoggerTest extends TestCase
{
    public function testRecordsUsageOriginDurationAndEstimatedCost(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(self::callback(static function (array $params): bool {
                self::assertSame('openrouter', $params['provider']);
                self::assertSame('google/gemma-4-26b-a4b-it:free', $params['model']);
                self::assertSame(500, $params['input_tokens']);
                self::assertSame(100, $params['output_tokens']);
                self::assertSame('llm', $params['origin']);
                self::assertSame(123, $params['duration_ms']);
                self::assertSame('length', $params['finish_reason']);
                self::assertEqualsWithDelta(0.0014, $params['estimated_cost'], 0.00000001);
                return true;
            }));
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $cost = (new TokenLogger($pdo, 2.0, 4.0))->record(500, 100, 'google/gemma-4-26b-a4b-it:free', 'llm', 123, 'length');

        self::assertEqualsWithDelta(0.0014, $cost, 0.00000001);
    }

    public function testZeroConfiguredCostsRemainZero(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(self::callback(static fn (array $params): bool => $params['estimated_cost'] === 0.0));
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        self::assertSame(0.0, (new TokenLogger($pdo))->record(99, 10, 'model', 'cache', 5));
    }
}