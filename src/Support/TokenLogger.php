<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

class TokenLogger
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly float $inputCostPerMillion = 0.0,
        private readonly float $outputCostPerMillion = 0.0
    ) {
    }

    public function record(
        int $inputTokens,
        int $outputTokens,
        string $model,
        string $origin,
        int $durationMs,
        ?string $finishReason = null
    ): float {
        $inputTokens = max(0, $inputTokens);
        $outputTokens = max(0, $outputTokens);
        $cost = ($inputTokens * $this->inputCostPerMillion
            + $outputTokens * $this->outputCostPerMillion) / 1000000;
        $statement = $this->pdo->prepare(
            'INSERT INTO log_tokens '
            . '(provedor, modelo, tokens_entrada, tokens_saida, custo_estimado, origem, duracao_ms, finish_reason) '
            . 'VALUES (:provider, :model, :input_tokens, :output_tokens, :estimated_cost, :origin, :duration_ms, :finish_reason)'
        );
        $statement->execute([
            'provider' => 'openrouter',
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost' => $cost,
            'origin' => $origin,
            'duration_ms' => max(0, $durationMs),
            'finish_reason' => $finishReason,
        ]);

        return $cost;
    }
}