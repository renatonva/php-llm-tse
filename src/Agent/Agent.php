<?php

declare(strict_types=1);

namespace App\Agent;

use App\Support\Cache;
use App\Support\TokenLogger;
use App\Agent\Tools\BuscarCandidatoTool;
use App\Agent\Tools\RankingPartidosTool;
use App\Agent\Tools\ResultadoEleicaoTool;
use App\Agent\Tools\Tool;
use App\Repository\CandidateRepository;
use App\Repository\ElectionRepository;
use App\Repository\PartyRankingRepository;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PDO;
use RuntimeException;

final class Agent
{
    private const SYSTEM_PROMPT = 'Responda em pt-BR e com concisão. Use somente dados retornados pelas ferramentas; nunca invente números. Se a ferramenta indicar eleição ainda não encerrada, explique que os resultados podem estar incompletos. Se indicar ausência de dados, diga claramente que os dados de votação não foram importados para o ano, UF e cargo consultados. Os dados cobrem apenas o que foi importado.';

    private readonly ClientInterface $httpClient;
    private readonly Cache $cache;
    private readonly array $config;
    private readonly TokenLogger $tokenLogger;
    private readonly Router $router;
    private readonly \Closure $sleeper;
    private bool $cacheableAnswer = true;
    private int $toolCallsExecuted = 0;
    /** @var list<Tool> */
    private readonly array $toolInstances;

    public function __construct(
        private readonly PDO $pdo,
        ?ClientInterface $httpClient = null,
        ?Cache $cache = null,
        array $config = [],
        ?array $tools = null,
        ?TokenLogger $tokenLogger = null,
        ?Router $router = null,
        ?callable $sleeper = null
    ) {
        $this->config = array_merge(self::environmentConfig(), $config);
        $this->httpClient = $httpClient ?? new Client(['timeout' => 30]);
        $this->cache = $cache ?? new Cache(
            $pdo,
            (int) $this->config['cache_ttl_seconds'],
            (int) $this->config['cache_past_election_ttl_seconds'],
            (int) $this->config['cache_active_election_ttl_seconds']
        );
        $this->tokenLogger = $tokenLogger ?? new TokenLogger(
            $pdo,
            (float) $this->config['input_cost_per_million'],
            (float) $this->config['output_cost_per_million']
        );
        $this->router = $router ?? new Router();
        $this->sleeper = $sleeper === null
            ? static fn (int $microseconds) => usleep($microseconds)
            : \Closure::fromCallable($sleeper);
        $this->toolInstances = $tools ?? [
            new BuscarCandidatoTool(new CandidateRepository($pdo)),
            new ResultadoEleicaoTool(new ElectionRepository($pdo)),
            new RankingPartidosTool(new PartyRankingRepository($pdo)),
        ];
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    public function answer(string $question, array $history = []): string
    {
        return $this->answerWithMetadata($question, $history)['answer'];
    }

    /** @return array{answer: string, input_tokens: int, output_tokens: int, cost: float, origin: string, duration_ms: int, tool_calls: int} */
    public function answerWithMetadata(string $question, array $history = []): array
    {
        if (trim($question) === '') {
            throw new \InvalidArgumentException('Question must not be empty.');
        }
        if (mb_strlen($question, 'UTF-8') > 500) {
            throw new \InvalidArgumentException('A pergunta deve ter no máximo 500 caracteres.');
        }

        $startedAt = microtime(true);
        $inputTokens = 0;
        $outputTokens = 0;
        $origin = 'llm';
        $answer = '';
        $finishReason = null;
        $this->cacheableAnswer = true;
        $this->toolCallsExecuted = 0;

        try {
            $cachedAnswer = $this->cache->get($question);
            if ($cachedAnswer !== null) {
                $origin = 'cache';
                $answer = $cachedAnswer;
            } else {
                $routedAnswer = $this->router->resolve(
                    $question,
                    fn (string $name, array $arguments): array => $this->executeCachedTool($name, $arguments)
                );
                if ($routedAnswer !== null) {
                    $origin = 'router';
                    $answer = $routedAnswer;
                    if (
                        str_starts_with($answer, 'Sem dados importados')
                        || str_starts_with($answer, 'Não há dados')
                        || str_contains($answer, 'ainda não encerrada')
                        || str_starts_with($answer, 'Não foi possível')
                    ) {
                        $this->cacheableAnswer = false;
                    }
                } else {
                    $answer = $this->runToolLoop($question, $history, $inputTokens, $outputTokens, $finishReason);
                }
                if ($this->cacheableAnswer) {
                    $this->cache->put($question, $answer);
                }
            }
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $cost = $this->tokenLogger->record(
                $inputTokens,
                $outputTokens,
                (string) $this->config['model'],
                $origin,
                $durationMs,
                $finishReason
            );
        }

        return [
            'answer' => $answer,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'origin' => $origin,
            'duration_ms' => $durationMs,
            'tool_calls' => $this->toolCallsExecuted,
        ];
    }

    private function runToolLoop(
        string $question,
        array $history,
        int &$inputTokens,
        int &$outputTokens,
        ?string &$finishReason
    ): string
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        $historyLimit = max(0, (int) $this->config['history_messages']);
        $validHistory = [];
        foreach ($history as $message) {
            if (
                is_array($message)
                && in_array($message['role'] ?? null, ['user', 'assistant', 'tool'], true)
                && is_string($message['content'] ?? null)
            ) {
                $content = $message['content'];
                if ($message['role'] === 'tool' && mb_strlen($content, 'UTF-8') > 700) {
                    $content = mb_substr($content, 0, 700, 'UTF-8') . ' [truncado]';
                }
                $historyMessage = ['role' => $message['role'], 'content' => $content];
                if ($message['role'] === 'tool' && is_string($message['tool_call_id'] ?? null)) {
                    $historyMessage['tool_call_id'] = $message['tool_call_id'];
                }
                $validHistory[] = $historyMessage;
            }
        }
        $limitedHistory = $historyLimit === 0 ? [] : array_slice($validHistory, -$historyLimit);
        $messages = array_merge($messages, $limitedHistory);
        $messages[] = ['role' => 'user', 'content' => $question];

        for ($step = 0; $step < max(1, (int) $this->config['max_steps']); $step++) {
            for ($attempt = 0; ; $attempt++) {
                $response = $this->httpClient->post(
                    rtrim((string) $this->config['base_url'], '/') . '/chat/completions',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->requiredApiKey(),
                            'Content-Type' => 'application/json',
                        ],
                        'http_errors' => false,
                        'json' => [
                            'model' => $this->config['model'],
                            'max_tokens' => (int) $this->config['max_tokens'],
                            'temperature' => (float) $this->config['temperature'],
                            'messages' => $messages,
                            'tools' => $this->tools(),
                            'tool_choice' => 'auto',
                        ],
                    ]
                );
                $statusCode = $response->getStatusCode();
                $payload = json_decode((string) $response->getBody(), true);
                $payload = is_array($payload) ? $payload : [];
                $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $inputTokens += max(0, (int) ($usage['prompt_tokens'] ?? 0));
                $outputTokens += max(0, (int) ($usage['completion_tokens'] ?? 0));
                $choiceError = $payload['choices'][0]['error'] ?? null;
                if ($statusCode < 400 && isset($payload['choices'][0]) && !is_array($choiceError)) {
                    break;
                }

                $error = is_array($payload['error'] ?? null)
                    ? $payload['error']
                    : (is_array($choiceError) ? $choiceError : []);
                $errorMessage = is_string($error['message'] ?? null) ? $error['message'] : '';
                $errorCode = is_numeric($error['code'] ?? null) ? (int) $error['code'] : 0;
                $retryable = $statusCode === 429
                    || ($statusCode >= 500 && $statusCode < 600)
                    || $errorCode === 429
                    || ($errorCode >= 500 && $errorCode < 600)
                    || preg_match('/\b(?:429|5\d{2})\b/', $errorMessage) === 1;
                if (!$retryable) {
                    $this->cacheableAnswer = false;
                    return 'Não foi possível processar a resposta do serviço de IA. Tente novamente.';
                }
                if ($attempt >= 2) {
                    $this->cacheableAnswer = false;
                    return 'O serviço de IA está indisponível no momento, tente novamente';
                }

                ($this->sleeper)($attempt === 0 ? 1000000 : 3000000);
            }

            $message = $payload['choices'][0]['message'] ?? null;
            if (!is_array($message)) {
                throw new RuntimeException('OpenRouter returned an invalid chat completion.');
            }

            $finishReason = is_string($payload['choices'][0]['finish_reason'] ?? null)
                ? $payload['choices'][0]['finish_reason']
                : null;
            if ($finishReason === 'length') {
                $this->cacheableAnswer = false;
                return 'A resposta ficou muito longa. Por favor, reformule a pergunta.';
            }

            $toolCalls = $message['tool_calls'] ?? [];
            if (is_array($toolCalls) && $toolCalls !== []) {
                $messages[] = $message;
                foreach ($toolCalls as $toolCall) {
                    $toolResult = $this->executeTool($toolCall);
                    $toolContent = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    if (mb_strlen($toolContent, 'UTF-8') > 1200) {
                        $toolContent = mb_substr($toolContent, 0, 1200, 'UTF-8') . ' [truncado]';
                    }
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                        'content' => $toolContent,
                    ];
                }
                continue;
            }

            $answer = trim((string) ($message['content'] ?? ''));
            if ($answer === '' && trim((string) ($message['reasoning'] ?? '')) !== '') {
                $answer = 'Não consegui gerar uma resposta. Tente reformular a pergunta.';
                $this->cacheableAnswer = false;
            }
            if ($answer === '') {
                $this->cacheableAnswer = false;
                return 'Não consegui gerar uma resposta. Tente reformular a pergunta.';
            }

            return $answer;
        }

        $this->cacheableAnswer = false;
        return 'Não consegui concluir a consulta dentro do limite de etapas. Reformule a pergunta.';
    }

    private function executeTool(array $toolCall): array
    {
        try {
            $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->cacheableAnswer = false;
            return ['erro' => 'argumentos inválidos'];
        }
        if (!is_array($arguments)) {
            $this->cacheableAnswer = false;
            return ['erro' => 'argumentos inválidos'];
        }

        $name = $toolCall['function']['name'] ?? '';
        foreach ($this->toolInstances as $tool) {
            if (($tool->definition()['function']['name'] ?? null) === $name) {
                $result = $this->executeCachedTool((string) $name, $arguments, $tool);
                if (isset($result['erro'])) {
                    $this->cacheableAnswer = false;
                }
                return $result;
            }
        }

        $this->cacheableAnswer = false;
        return ['erro' => 'ferramenta desconhecida'];
    }

    private function executeCachedTool(string $name, array $arguments, ?Tool $registeredTool = null): array
    {
        $this->toolCallsExecuted++;
        $tool = $registeredTool;
        if ($tool === null) {
            foreach ($this->toolInstances as $candidate) {
                if (($candidate->definition()['function']['name'] ?? null) === $name) {
                    $tool = $candidate;
                    break;
                }
            }
        }
        if ($tool === null) {
            $this->cacheableAnswer = false;
            return ['erro' => 'ferramenta desconhecida'];
        }

        $normalizedArguments = self::normalizeArguments($arguments);
        $argumentsJson = json_encode($normalizedArguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cacheKey = 'tool:' . $name . ':' . $argumentsJson;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $result = json_decode($cached, true);
            if (is_array($result) && !isset($result['erro'])) {
                return $result;
            }
        }

        $result = $tool->run($arguments);
        if (isset($result['erro'])) {
            $this->cacheableAnswer = false;
            return $result;
        }

        $this->cache->put($cacheKey, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $result;
    }

    private function tools(): array
    {
        return array_map(static fn (Tool $tool): array => $tool->definition(), $this->toolInstances);
    }

    private function requiredApiKey(): string
    {
        $apiKey = trim((string) $this->config['api_key']);
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is required for non-deterministic questions.');
        }

        return $apiKey;
    }

    private static function normalizeArguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_string($value)) {
                $arguments[$key] = Cache::normalize($value);
            } elseif (is_array($value)) {
                $arguments[$key] = self::normalizeArguments($value);
            }
        }
        if (!array_is_list($arguments)) {
            ksort($arguments);
        }

        return $arguments;
    }

    private static function environmentConfig(): array
    {
        return [
            'api_key' => self::environment('OPENROUTER_API_KEY', ''),
            'base_url' => self::environment('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'model' => self::environment('OPENROUTER_MODEL', 'google/gemma-4-26b-a4b-it:free'),
            'max_tokens' => self::environment('OPENROUTER_MAX_TOKENS', '800'),
            'temperature' => self::environment('OPENROUTER_TEMPERATURE', '0.2'),
            'max_steps' => self::environment('OPENROUTER_MAX_STEPS', '5'),
            'history_messages' => self::environment('OPENROUTER_HISTORY_MESSAGES', '6'),
            'input_cost_per_million' => self::environment('OPENROUTER_INPUT_COST_PER_MILLION', '0'),
            'output_cost_per_million' => self::environment('OPENROUTER_OUTPUT_COST_PER_MILLION', '0'),
            'cache_ttl_seconds' => self::environment('CACHE_TTL_SECONDS', '3600'),
            'cache_past_election_ttl_seconds' => self::environment('CACHE_PAST_ELECTION_TTL_SECONDS', '31536000'),
            'cache_active_election_ttl_seconds' => self::environment('CACHE_ACTIVE_ELECTION_TTL_SECONDS', '300'),
        ];
    }

    private static function environment(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null ? $default : (string) $value;
    }
}