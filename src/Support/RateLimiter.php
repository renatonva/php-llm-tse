<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allow(string $ip, int $maxRequests, int $windowSeconds): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limits (ip, window_started_at, request_count) ' .
            'VALUES (:ip, CURRENT_TIMESTAMP, 1) ' .
            'ON DUPLICATE KEY UPDATE ' .
            'request_count = IF(window_started_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :window_a SECOND), 1, request_count + 1), ' .
            'window_started_at = IF(window_started_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :window_b SECOND), CURRENT_TIMESTAMP, window_started_at)'
        );
        $statement->execute(['ip' => $ip, 'window_a' => $windowSeconds, 'window_b' => $windowSeconds]);

        $statement = $this->pdo->prepare('SELECT request_count FROM rate_limits WHERE ip = :ip LIMIT 1');
        $statement->execute(['ip' => $ip]);

        return (int) $statement->fetchColumn() <= $maxRequests;
    }
}