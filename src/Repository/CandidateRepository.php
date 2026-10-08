<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CandidateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $name, ?string $uf, ?int $year, ?string $cargo): array
    {
        $conditions = [
            '(LOCATE(:name_in_candidate, c.nome COLLATE utf8mb4_unicode_ci) > 0 '
            . 'OR LOCATE(:name_on_ballot, c.nome_normalizado COLLATE utf8mb4_unicode_ci) > 0)',
        ];
        $parameters = ['name_in_candidate' => $name, 'name_on_ballot' => $name];
        if ($uf !== null) {
            $conditions[] = 'c.uf = :uf';
            $parameters['uf'] = $uf;
        }
        if ($year !== null) {
            $conditions[] = 'e.ano = :year';
            $parameters['year'] = $year;
        }
        if ($cargo !== null) {
            $conditions[] = 'g.nome = :cargo';
            $parameters['cargo'] = $cargo;
        }

        $sql = 'SELECT DISTINCT c.nome_normalizado AS nome_urna, g.nome AS cargo, '
            . 'NULLIF(c.uf, \'\') AS uf, e.ano, p.sigla AS partido, c.numero, c.situacao '
            . 'FROM candidatos c '
            . 'JOIN eleicoes e ON e.id = c.eleicao_id '
            . 'JOIN cargos g ON g.id = c.cargo_id '
            . 'LEFT JOIN partidos p ON p.id = c.partido_id '
            . 'WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY e.ano DESC, c.nome_normalizado LIMIT 10';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}