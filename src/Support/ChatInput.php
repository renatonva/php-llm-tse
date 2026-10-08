<?php

declare(strict_types=1);

namespace App\Support;

final class ChatInput
{
    /** @return array{message: string, session_id: string} */
    public static function validate(mixed $payload): array
    {
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \InvalidArgumentException('O corpo deve ser um objeto JSON.');
        }

        $message = $payload['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            throw new \InvalidArgumentException('Informe uma mensagem válida.');
        }
        if (mb_strlen($message, 'UTF-8') > 500) {
            throw new \InvalidArgumentException('A mensagem deve ter até 500 caracteres.');
        }

        $sessionId = $payload['session_id'] ?? null;
        if (!is_string($sessionId) || preg_match('/^[A-Za-z0-9]{1,64}$/', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Identificador de sessão inválido.');
        }

        return ['message' => $message, 'session_id' => $sessionId];
    }
}