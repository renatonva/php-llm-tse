<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class ElectionCalendar
{
    private const FIRST_ROUND = '2026-10-04';
    private const SECOND_ROUND = '2026-10-25';

    public static function isActiveYear(int $year): bool
    {
        if ($year !== 2026) {
            return false;
        }

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $runoffEnd = (new DateTimeImmutable(self::SECOND_ROUND, $timezone))->modify('+1 day');
        return new DateTimeImmutable('now', $timezone) < $runoffEnd;
    }

    /** @return array{first_round: string, second_round: string} */
    public static function dates(): array
    {
        return ['first_round' => self::FIRST_ROUND, 'second_round' => self::SECOND_ROUND];
    }
}
