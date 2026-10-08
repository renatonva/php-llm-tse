<?php

declare(strict_types=1);

namespace Tests;

use App\Support\RateLimiter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    #[DataProvider('requestCounts')]
    public function testAllowsOnlyRequestsWithinConfiguredLimit(int $count, bool $allowed): void
    {
        $write = $this->createMock(PDOStatement::class);
        $write->expects(self::once())->method('execute')->with([
            'ip' => '192.0.2.1', 'window_a' => 60, 'window_b' => 60,
        ]);
        $read = $this->createMock(PDOStatement::class);
        $read->expects(self::once())->method('execute')->with(['ip' => '192.0.2.1']);
        $read->method('fetchColumn')->willReturn($count);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('prepare')->willReturnOnConsecutiveCalls($write, $read);

        self::assertSame($allowed, (new RateLimiter($pdo))->allow('192.0.2.1', 20, 60));
    }

    public static function requestCounts(): iterable
    {
        yield 'limit not reached' => [20, true];
        yield 'limit exceeded' => [21, false];
    }
}