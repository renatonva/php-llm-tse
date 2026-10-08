<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ElectionCalendar;
use PDO;

final class ElectionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{resultados?: list<array<string, mixed>>, erro?: string} */
    public function ranking(int $year, string $uf, string $cargo, ?int $turn, int $limit = 10): array
    {
        $turn ??= $this->latestTurn($year, $uf, $cargo);
        if ($turn === null) {
            return ['erro' => $this->missingDataMessage($year, $uf, $cargo)];
        }
        $sql = 'WITH candidato_totais AS ('
            . 'SELECT c.id candidato_id, c.nome_normalizado candidato, p.sigla partido, '
            . 't.votos, t.situacao '
            . 'FROM totais_candidato t '
            . 'JOIN cargos g ON g.id = t.cargo_id '
            . 'JOIN candidatos c ON c.id = t.candidato_id '
            . 'LEFT JOIN partidos p ON p.id = t.partido_id '
            . 'WHERE t.ano = :year AND t.uf = :uf AND g.nome = :cargo AND t.turno = :turn'
            . '), ranking AS ('
            . 'SELECT candidato_id, candidato, partido, votos, situacao, '
            . 'ROUND(votos * 100.0 / NULLIF(SUM(votos) OVER (), 0), 2) percentual '
            . 'FROM candidato_totais'
            . ') SELECT candidato, partido, votos, percentual, situacao '
            . 'FROM ranking ORDER BY votos DESC, candidato_id LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':year', $year, PDO::PARAM_INT);
        $statement->bindValue(':uf', $uf, PDO::PARAM_STR);
        $statement->bindValue(':cargo', $cargo, PDO::PARAM_STR);
        $statement->bindValue(':turn', $turn, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows === []
            ? ['erro' => $this->missingDataMessage($year, $uf, $cargo)]
            : ['resultados' => $rows];
    }

    private function missingDataMessage(int $year, string $uf, string $cargo): string
    {
        if (ElectionCalendar::isActiveYear($year)) {
            return sprintf('eleição %d ainda não encerrada; votação de %s em %s pode estar incompleta', $year, $cargo, $uf);
        }

        return sprintf('não há dados de votação importados para %s em %s no ano %d', $cargo, $uf, $year);
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