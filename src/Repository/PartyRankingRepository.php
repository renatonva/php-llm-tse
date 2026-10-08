<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PartyRankingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{partidos?: list<array<string, mixed>>, erro?: string} */
    public function ranking(int $year, string $uf, string $cargo, ?int $turn, int $limit = 10): array
    {
        $turn ??= $this->latestTurn($year, $uf, $cargo);
        if ($turn === null) {
            return ['erro' => 'sem dados importados para este ano/UF/cargo'];
        }
        $sql = 'WITH partido_totais AS ('
            . 'SELECT p.id partido_id, COALESCE(p.sigla, \'Sem partido\') partido, '
            . 'SUM(t.votos) votos '
            . 'FROM totais_candidato t '
            . 'JOIN cargos g ON g.id = t.cargo_id '
            . 'LEFT JOIN partidos p ON p.id = t.partido_id '
            . 'WHERE t.ano = :year AND t.uf = :uf AND g.nome = :cargo AND t.turno = :turn '
            . 'GROUP BY p.id, p.sigla'
            . '), ranking AS ('
            . 'SELECT partido_id, partido, votos, '
            . 'ROUND(votos * 100.0 / NULLIF(SUM(votos) OVER (), 0), 2) percentual '
            . 'FROM partido_totais'
            . ') SELECT partido, votos, percentual FROM ranking '
            . 'ORDER BY votos DESC, partido LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':year', $year, PDO::PARAM_INT);
        $statement->bindValue(':uf', $uf, PDO::PARAM_STR);
        $statement->bindValue(':cargo', $cargo, PDO::PARAM_STR);
        $statement->bindValue(':turn', $turn, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows === []
            ? ['erro' => 'sem dados importados para este ano/UF/cargo']
            : ['partidos' => $rows];
    }

    private function latestTurn(int $year, string $uf, string $cargo): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(t.turno) FROM totais_candidato t '
            . 'JOIN cargos g ON g.id = t.cargo_id '
            . 'WHERE t.ano = :year AND t.uf = :uf AND g.nome = :cargo'
        );
        $statement->execute(['year' => $year, 'uf' => $uf, 'cargo' => $cargo]);
        $turn = $statement->fetchColumn();
        return $turn === false || $turn === null ? null : (int) $turn;
    }
}