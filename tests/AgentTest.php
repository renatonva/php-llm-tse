<?php

declare(strict_types=1);

namespace Tests;

use App\Agent\Agent;
use App\Agent\Tools\Tool;
use App\Support\Cache;
use App\Support\TokenLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function testExecutesRegisteredToolAndReturnsFinalAnswer(): void
    {
        [$agent, $state] = $this->agent([
            self::apiResponse(['tool_calls' => [self::toolCall('buscar_candidato', '{"nome":"Ana"}')]], 'tool_calls', 7, 2),
            self::apiResponse(['content' => 'Ana foi candidata.'], 'stop', 5, 4),
        ]);

        $result = $agent->answerWithMetadata('Quem é Ana?');
        $secondRequest = json_decode((string) $state->requests[1]['request']->getBody(), true);

        self::assertSame('Ana foi candidata.', $result['answer']);
        self::assertSame(12, $result['input_tokens']);
        self::assertSame(6, $result['output_tokens']);
        self::assertSame('llm', $result['origin']);
        self::assertSame('tool', $secondRequest['messages'][3]['role']);
        self::assertSame('call-1', $secondRequest['messages'][3]['tool_call_id']);
        self::assertSame(['candidatos' => [['nome_urna' => 'Ana']]], json_decode($secondRequest['messages'][3]['content'], true));
    }

    public function testInvalidToolArgumentsAreReturnedToModelWithoutExecution(): void
    {
        $tool = new AgentTestTool();
        [$agent, $state] = $this->agent([
            self::apiResponse(['tool_calls' => [self::toolCall('buscar_candidato', '{invalid')]], 'tool_calls'),
            self::apiResponse(['content' => 'Não foi possível consultar.'], 'stop'),
        ], $tool);

        $result = $agent->answerWithMetadata('Busque Ana');
        $secondRequest = json_decode((string) $state->requests[1]['request']->getBody(), true);

        self::assertSame(0, $tool->runs);
        self::assertSame(['erro' => 'argumentos inválidos'], json_decode($secondRequest['messages'][3]['content'], true));
        self::assertSame('Não foi possível consultar.', $result['answer']);
    }

    public function testResponseWithoutChoicesDoesNotExposeApiError(): void
    {
        [$agent] = $this->agent([new Response(200, [], '{"error":{"message":"private provider detail"}}')]);

        $answer = $agent->answer('Pergunta sem rota');

        self::assertSame('Não foi possível processar a resposta do serviço de IA. Tente novamente.', $answer);
        self::assertStringNotContainsString('private provider detail', $answer);
    }

    public function testRetriesRateLimitWithIncreasingDelay(): void
    {
        [$agent, $state] = $this->agent([
            new Response(429, [], '{"error":{"message":"rate limited"}}'),
            self::apiResponse(['content' => 'Resposta pronta.'], 'stop'),
        ]);

        self::assertSame('Resposta pronta.', $agent->answer('Pergunta sem rota'));
        self::assertSame([1000000], $state->delays);
    }

    public function testLengthFinishReasonAsksForMoreSpecificQuestion(): void
    {
        $logger = $this->createMock(TokenLogger::class);
        $logger->expects(self::once())
            ->method('record')
            ->with(8, 3, 'google/gemma-4-26b-a4b-it:free', 'llm', self::anything(), 'length')
            ->willReturn(0.0);
        [$agent] = $this->agent([
            self::apiResponse(['content' => 'incompleta'], 'length', 8, 3),
        ], null, $logger);

        $result = $agent->answerWithMetadata('Pergunta sem rota');

        self::assertSame('A resposta ficou muito longa. Por favor, reformule a pergunta.', $result['answer']);
        self::assertSame(8, $result['input_tokens']);
        self::assertSame(3, $result['output_tokens']);
    }

    private function agent(array $responses, ?AgentTestTool $tool = null, ?TokenLogger $logger = null): array
    {
        $state = (object) ['requests' => [], 'delays' => []];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($state->requests));
        $httpClient = new Client(['handler' => $stack]);
        $cache = $this->createMock(Cache::class);
        $cache->method('get')->willReturn(null);
        if ($logger === null) {
            $logger = $this->createMock(TokenLogger::class);
            $logger->method('record')->willReturn(0.0);
        }
        $agent = new Agent(
            $this->createMock(PDO::class),
            $httpClient,
            $cache,
            [
                'api_key' => 'test-key', 'base_url' => 'https://openrouter.test/api/v1',
                'model' => 'google/gemma-4-26b-a4b-it:free', 'max_tokens' => 100,
                'temperature' => 0.2, 'max_steps' => 3, 'history_messages' => 2,
                'input_cost_per_million' => 0, 'output_cost_per_million' => 0,
            ],
            [$tool ?? new AgentTestTool()],
            $logger,
            null,
            static function (int $microseconds) use ($state): void {
                $state->delays[] = $microseconds;
            }
        );

        return [$agent, $state];
    }

    private static function apiResponse(array $message, string $finishReason, int $inputTokens = 0, int $outputTokens = 0): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => array_merge(['role' => 'assistant'], $message), 'finish_reason' => $finishReason]],
            'usage' => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
        ], JSON_THROW_ON_ERROR));
    }

    private static function toolCall(string $name, string $arguments): array
    {
        return [
            'id' => 'call-1',
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => $arguments],
        ];
    }
}

final class AgentTestTool implements Tool
{
    public int $runs = 0;

    public function definition(): array
    {
        return ['type' => 'function', 'function' => ['name' => 'buscar_candidato', 'description' => 'Busca candidato.', 'parameters' => ['type' => 'object']]];
    }

    public function run(array $args): array
    {
        $this->runs++;
        return ['candidatos' => [['nome_urna' => $args['nome'] ?? 'Ana']]];
    }
}