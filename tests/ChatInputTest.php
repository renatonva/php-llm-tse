<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ChatInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatInputTest extends TestCase
{
    public function testAcceptsValidMessageAndSessionId(): void
    {
        self::assertSame(
            ['message' => 'Quem venceu?', 'session_id' => 'session123'],
            ChatInput::validate(['message' => 'Quem venceu?', 'session_id' => 'session123'])
        );
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidInput(mixed $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        ChatInput::validate($payload);
    }

    public static function invalidPayloads(): iterable
    {
        yield 'not an object' => [[]];
        yield 'missing message' => [['session_id' => 'abc']];
        yield 'empty message' => [['message' => ' ', 'session_id' => 'abc']];
        yield 'message too long' => [['message' => str_repeat('a', 501), 'session_id' => 'abc']];
        yield 'missing session' => [['message' => 'Olá']];
        yield 'session is not alphanumeric' => [['message' => 'Olá', 'session_id' => 'abc-123']];
        yield 'session too long' => [['message' => 'Olá', 'session_id' => str_repeat('a', 65)]];
    }
}