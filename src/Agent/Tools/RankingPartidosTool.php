<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Repository\PartyRankingRepository;
use InvalidArgumentException;
use Throwable;

final class RankingPartidosTool implements Tool
{
    public function __construct(private readonly PartyRankingRepository $repository)
    {
    }

    /*
     * Ferramenta para listar o ranking de partidos em um determinado cargo e eleição.
     * Você pode especificar o ano, a unidade federativa, o cargo, o turno e o limite de resultados.
     * Pode utilizar promtps como Qual o ranking de mais votados para deputado federal por partido em São Paulo em 2022?
     */

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'ranking_partidos',
                'description' => 'Lista votos por partido em um cargo.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'ano' => ['type' => 'integer', 'minimum' => 1900, 'maximum' => 2100],
                        'uf' => ['type' => 'string', 'enum' => ToolInput::UFS],
                        'cargo' => ['type' => 'string', 'enum' => ['governador', 'senador', 'deputado federal', 'deputado estadual', 'presidente', 'prefeito', 'vereador']],
                        'turno' => ['type' => 'integer', 'enum' => [1, 2]],
                        'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                    ],
                    'required' => ['ano', 'uf', 'cargo'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function run(array $args): array
    {
        try {
            ToolInput::keys($args, ['ano', 'uf', 'cargo', 'turno', 'limite'], ['ano', 'uf', 'cargo']);
            return $this->repository->ranking(
                ToolInput::year($args['ano']),
                ToolInput::uf($args['uf']),
                ToolInput::cargo($args['cargo']),
                ToolInput::optionalTurn($args),
                ToolInput::limit($args)
            );
        } catch (InvalidArgumentException $exception) {
            return ['erro' => $exception->getMessage()];
        } catch (Throwable) {
            return ['erro' => 'falha na consulta'];
        }
    }
}