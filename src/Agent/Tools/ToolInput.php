<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use InvalidArgumentException;

final class ToolInput
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
        'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    private const CARGOS = [
        'governador' => 'Governador',
        'senador' => 'Senador',
        'deputado federal' => 'Deputado Federal',
        'deputado estadual' => 'Deputado Estadual',
        'presidente' => 'Presidente',
        'prefeito' => 'Prefeito',
        'vereador' => 'Vereador',
    ];

    public static function keys(array $args, array $allowed, array $required): void
    {
        if (array_diff(array_keys($args), $allowed) !== [] || array_diff($required, array_keys($args)) !== []) {
            throw new InvalidArgumentException('argumentos inválidos');
        }
    }

    public static function name(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('nome inválido');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 100) {
            throw new InvalidArgumentException('nome inválido');
        }
        return $value;
    }

    public static function uf(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::UFS, true)) {
            throw new InvalidArgumentException('UF inválida');
        }
        return $value;
    }

    public static function optionalUf(array $args): ?string
    {
        return array_key_exists('uf', $args) ? self::uf($args['uf']) : null;
    }

    public static function year(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int) $value < 1900 || (int) $value > 2100
        ) {
            throw new InvalidArgumentException('ano inválido');
        }
        return (int) $value;
    }

    /** @return list<int> */
    public static function years(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 10) {
            throw new InvalidArgumentException('anos inválidos');
        }

        $years = array_map(self::year(...), $value);
        if (count(array_unique($years)) !== count($years)) {
            throw new InvalidArgumentException('anos duplicados');
        }

        return $years;
    }

    public static function optionalYear(array $args): ?int
    {
        return array_key_exists('ano', $args) ? self::year($args['ano']) : null;
    }

    public static function cargo(mixed $value): string
    {
        if (!is_string($value) || mb_strlen(trim($value), 'UTF-8') > 50) {
            throw new InvalidArgumentException('cargo inválido');
        }
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        if (!isset(self::CARGOS[$normalized])) {
            throw new InvalidArgumentException('cargo inválido');
        }
        return self::CARGOS[$normalized];
    }

    public static function optionalCargo(array $args): ?string
    {
        return array_key_exists('cargo', $args) ? self::cargo($args['cargo']) : null;
    }

    public static function optionalTurn(array $args): ?int
    {
        if (!array_key_exists('turno', $args)) {
            return null;
        }
        $value = $args['turno'];
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || !in_array((int) $value, [1, 2], true)) {
            throw new InvalidArgumentException('turno inválido');
        }
        return (int) $value;
    }

    public static function limit(array $args): int
    {
        if (!array_key_exists('limite', $args)) {
            return 10;
        }
        $value = $args['limite'];
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 50) {
            throw new InvalidArgumentException('limite deve estar entre 1 e 50');
        }
        return (int) $value;
    }
}