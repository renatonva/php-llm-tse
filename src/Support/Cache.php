<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

class Cache
{
    private const KEY_VERSION = 'v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $defaultTtlSeconds = 3600,
        private readonly int $pastElectionTtlSeconds = 31536000,
        private readonly int $activeElectionTtlSeconds = 300
    ) {
    }

    public function get(string $question): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT resposta FROM cache_respostas ' .
            'WHERE pergunta_hash = UNHEX(:hash) ' .
            'AND (expira_em IS NULL OR expira_em > CURRENT_TIMESTAMP) LIMIT 1'
        );
        $statement->execute(['hash' => self::hashFor($question)]);
        $answer = $statement->fetchColumn();

        return $answer === false ? null : (string) $answer;
    }

    public function put(string $question, string $answer, ?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? $this->ttlFor($question);
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Cache TTL must be greater than zero.');
        }

        $expiresAt = (new \DateTimeImmutable())
            ->modify(sprintf('+%d seconds', $ttl))
            ->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO cache_respostas (pergunta_hash, pergunta, resposta, expira_em) ' .
            'VALUES (UNHEX(:hash), :question, :answer, :expires_at) ' .
            'ON DUPLICATE KEY UPDATE pergunta = VALUES(pergunta), ' .
            'resposta = VALUES(resposta), criado_em = CURRENT_TIMESTAMP, expira_em = VALUES(expira_em)'
        );
        $statement->execute([
            'hash' => self::hashFor($question),
            'question' => $question,
            'answer' => $answer,
            'expires_at' => $expiresAt,
        ]);
    }

    public function clearYear(int $year): int
    {
        $select = $this->pdo->query('SELECT id, pergunta FROM cache_respostas');
        $delete = $this->pdo->prepare('DELETE FROM cache_respostas WHERE id = :id');
        $deleted = 0;
        $pattern = '/(?<!\d)' . preg_quote((string) $year, '/') . '(?!\d)/';

        while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (preg_match($pattern, self::normalize((string) $row['pergunta'])) === 1) {
                $delete->execute(['id' => (int) $row['id']]);
                $deleted += $delete->rowCount();
            }
        }

        return $deleted;
    }

    public static function normalize(string $question): string
    {
        $question = mb_strtolower(trim($question), 'UTF-8');
        $question = strtr($question, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $question = preg_replace('/\s+/u', ' ', $question);

        return $question ?? '';
    }

    public static function hashFor(string $query): string
    {
        return hash('sha256', self::KEY_VERSION . ':' . self::normalize($query));
    }

    private function ttlFor(string $question): int
    {
        if (preg_match_all('/\b(?:19|20)\d{2}\b/', $question, $matches) > 0) {
            foreach ($matches[0] as $year) {
                if (ElectionCalendar::isActiveYear((int) $year)) {
                    return $this->activeElectionTtlSeconds;
                }
            }
            foreach ($matches[0] as $year) {
                if ((int) $year < (int) date('Y')) {
                    return $this->pastElectionTtlSeconds;
                }
            }
        }

        return $this->defaultTtlSeconds;
    }
}