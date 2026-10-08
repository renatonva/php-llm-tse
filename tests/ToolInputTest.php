<?php

declare(strict_types=1);

namespace Tests;

use App\Agent\Tools\ToolInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolInputTest extends TestCase
{
    public function testValidatesUfYearCargoTurnAndLimit(): void
    {
        self::assertSame('AC', ToolInput::uf('AC'));
        self::assertSame(2022, ToolInput::year(2022));
        self::assertSame([2018, 2022], ToolInput::years([2018, 2022]));
        self::assertSame('Governador', ToolInput::cargo('governador'));
        self::assertSame(1, ToolInput::optionalTurn(['turno' => 1]));
        self::assertSame(10, ToolInput::limit([]));
        self::assertSame(50, ToolInput::limit(['limite' => 50]));
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidArguments(callable $validate, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $validate($value);
    }

    public static function invalidInputs(): iterable
    {
        yield 'invalid UF' => [ToolInput::uf(...), 'ac'];
        yield 'year out of range' => [ToolInput::year(...), 1800];
        yield 'non-numeric year' => [ToolInput::year(...), '20xx'];
        yield 'unknown cargo' => [ToolInput::cargo(...), 'cargo inventado'];
        yield 'invalid turn' => [static fn (mixed $value) => ToolInput::optionalTurn(['turno' => $value]), 3];
        yield 'limit over maximum' => [static fn (mixed $value) => ToolInput::limit(['limite' => $value]), 51];
        yield 'oversized name' => [ToolInput::name(...), str_repeat('a', 101)];
    }
}