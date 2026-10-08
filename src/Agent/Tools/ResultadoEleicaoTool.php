<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Repository\ElectionRepository;
use InvalidArgumentException;
use Throwable;

final class ResultadoEleicaoTool implements Tool
{
    public function __construct(private readonly ElectionRepository $repository)
    {
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'resultado_eleicao',
                'description' => 'Lista votos de candidatos em um cargo.',
                'parameters' => self::parameters(),
            ],
        ];
    }

    public function run(array $args): array
    {
        try {
            ToolInput::keys($args, ['ano', 'anos', 'uf', 'cargo', 'turno', 'limite'], ['uf', 'cargo']);
            if (array_key_exists('ano', $args) === array_key_exists('anos', $args)) {
                throw new InvalidArgumentException('informe ano ou anos');
            }
            $years = array_key_exists('anos', $args)
                ? ToolInput::years($args['anos'])
                : [ToolInput::year($args['ano'])];
            $uf = ToolInput::uf($args['uf']);
            $cargo = ToolInput::cargo($args['cargo']);
            $turn = ToolInput::optionalTurn($args);
            $limit = ToolInput::limit($args);
            $results = [];
            foreach ($years as $year) {
                $results[$year] = $this->repository->ranking($year, $uf, $cargo, $turn, $limit);
            }
            return array_key_exists('anos', $args)
                ? ['comparacao' => $results]
                : $results[$years[0]];
        } catch (InvalidArgumentException $exception) {
            return ['erro' => $exception->getMessage()];
        } catch (Throwable) {
            return ['erro' => 'falha na consulta'];
        }
    }

    private static function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ano' => ['type' => 'integer', 'minimum' => 1900, 'maximum' => 2100],
                'anos' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 10,
                    'uniqueItems' => true,
                    'items' => ['type' => 'integer', 'minimum' => 1900, 'maximum' => 2100],
                ],
                'uf' => ['type' => 'string', 'enum' => ToolInput::UFS],
                'cargo' => ['type' => 'string', 'enum' => ['governador', 'senador', 'deputado federal', 'deputado estadual', 'presidente', 'prefeito', 'vereador']],
                'turno' => ['type' => 'integer', 'enum' => [1, 2]],
                'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ],
            'required' => ['uf', 'cargo'],
            'oneOf' => [['required' => ['ano']], ['required' => ['anos']]],
            'additionalProperties' => false,
        ];
    }
}