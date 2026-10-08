<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class ChatHistory
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function deleteExpired(): void
    {
        $this->pdo->exec('DELETE FROM chat_history WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)');
    }

    /** @return list<array{role: string, content: string}> */
    public function recent(string $sessionId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $limit = min($limit, 100);
        $statement = $this->pdo->prepare(
            'SELECT role, content FROM (' .
            'SELECT id, role, content FROM chat_history WHERE session_id = :session_id ' .
            'ORDER BY id DESC LIMIT ' . $limit .
            ') AS recent_messages ORDER BY id ASC'
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map(
            static fn (array $row): array => ['role' => (string) $row['role'], 'content' => (string) $row['content']],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function append(string $sessionId, string $message, string $answer): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO chat_history (session_id, role, content) VALUES (:session_id, :role, :content)'
        );
        foreach ([['user', $message], ['assistant', $answer]] as [$role, $content]) {
            $statement->execute(['session_id' => $sessionId, 'role' => $role, 'content' => $content]);
        }
    }
}