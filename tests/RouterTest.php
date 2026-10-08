<?php

declare(strict_types=1);

namespace Tests;

use App\Agent\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testRoutesGovernorWinnerByStateName(): void
    {
        $router = new Router();
        $answer = $router->resolve('Quem ganhou para governador do Acre em 2022?', static function (string $name, array $args): array {
            self::assertSame('resultado_eleicao', $name);
            self::assertSame(['ano' => 2022, 'uf' => 'AC', 'cargo' => 'governador', 'limite' => 1], $args);
            return ['resultados' => [['candidato' => 'Candidato', 'partido' => 'ABC', 'votos' => 1234]]];
        });

        self::assertSame('Candidato (ABC) venceu para governador de AC em 2022, com 1.234 votos.', $answer);
    }

    public function testRoutesCandidateVoteCountThroughRegisteredTools(): void
    {
        $calls = [];
        $router = new Router();
        $answer = $router->resolve('Quantos votos teve Gladson Cameli em 2022?', static function (string $name, array $args) use (&$calls): array {
            $calls[] = [$name, $args];
            if ($name === 'buscar_candidato') {
                return ['candidatos' => [['nome_urna' => 'Gladson Cameli', 'cargo' => 'Governador', 'uf' => 'AC']]];
            }
            return ['resultados' => [['candidato' => 'Gladson Cameli', 'votos' => 45678]]];
        });

        self::assertSame('Gladson Cameli teve 45.678 votos para governador em AC em 2022.', $answer);
        self::assertSame(['buscar_candidato', 'resultado_eleicao'], array_column($calls, 0));
    }

    public function testReportsActiveElectionStatusFromResultsTool(): void
    {
        $answer = (new Router())->resolve(
            'Quem venceu para governador de Minas Gerais em 2026?',
            static fn (): array => ['erro' => 'eleição 2026 ainda não encerrada; votação de Governador em MG pode estar incompleta']
        );

        self::assertSame('Eleição 2026 ainda não encerrada; votação de Governador em MG pode estar incompleta.', $answer);
    }

    public function testRoutesPartyRankingAndDoesNotRouteComparison(): void
    {
        $router = new Router();
        $answer = $router->resolve('Ranking de partidos para deputado federal em SP 2022', static function (string $name, array $args): array {
            self::assertSame('ranking_partidos', $name);
            self::assertSame(['ano' => 2022, 'uf' => 'SP', 'cargo' => 'deputado federal', 'limite' => 10], $args);
            return ['partidos' => [['partido' => 'XYZ', 'votos' => 9000, 'percentual' => 12.5]]];
        });

        self::assertSame('Ranking de partidos para deputado federal em SP em 2022: 1. XYZ: 9.000 votos (12.50%)', $answer);
        self::assertNull($router->resolve('Compare os votos de Gladson Cameli e Jorge Viana no Acre em 2022', static fn (): array => []));
    }

    public function testRoutesYearComparisonWithOneToolCall(): void
    {
        $calls = [];
        $answer = (new Router())->resolve(
            'Compare o resultado para governador de Minas Gerais em 2018 e 2022',
            static function (string $name, array $args) use (&$calls): array {
                $calls[] = [$name, $args];
                return ['comparacao' => [
                    2018 => ['resultados' => [['candidato' => 'Candidato A', 'votos' => 100]]],
                    2022 => ['resultados' => [['candidato' => 'Candidato B', 'votos' => 200]]],
                ]];
            }
        );

        self::assertSame('Comparação de governador em MG — 2018: Candidato A (100 votos); 2022: Candidato B (200 votos).', $answer);
        self::assertCount(1, $calls);
        self::assertSame(['anos' => [2018, 2022], 'uf' => 'MG', 'cargo' => 'governador', 'limite' => 1], $calls[0][1]);
    }
}