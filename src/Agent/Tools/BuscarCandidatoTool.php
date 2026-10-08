<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Repository\CandidateRepository;
use InvalidArgumentException;
use Throwable;

final class BuscarCandidatoTool implements Tool
{
    public function __construct(private readonly CandidateRepository $repository)
    {
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'buscar_candidato',
                'description' => 'Busca candidatos por nome.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string', 'maxLength' => 100],
                        'uf' => ['type' => 'string', 'enum' => ToolInput::UFS],
                        'ano' => ['type' => 'integer', 'minimum' => 1900, 'maximum' => 2100],
                        'cargo' => ['type' => 'string', 'enum' => array_keys(self::cargoOptions())],
                    ],
                    'required' => ['nome'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function run(array $args): array
    {
        try {
            ToolInput::keys($args, ['nome', 'uf', 'ano', 'cargo'], ['nome']);
            $rows = $this->repository->search(
                ToolInput::name($args['nome']),
                ToolInput::optionalUf($args),
                ToolInput::optionalYear($args),
                ToolInput::optionalCargo($args)
            );
            return ['candidatos' => $rows];
        } catch (InvalidArgumentException $exception) {
            return ['erro' => $exception->getMessage()];
        } catch (Throwable) {
            return ['erro' => 'falha na consulta'];
        }
    }

    private static function cargoOptions(): array
    {
        return [
            'governador' => true,
            'senador' => true,
            'deputado federal' => true,
            'deputado estadual' => true,
            'presidente' => true,
            'prefeito' => false,
            'vereador' => false,
        ];
    }
}