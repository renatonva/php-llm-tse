<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class TotalsBuilder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Reconstrói totais_candidato a partir de resultados_zona, opcionalmente só para um ano/UF. */
    public function rebuild(?int $year = null, ?string $uf = null): int
    {
        $where = ['1 = 1'];
        $params = [];
        if ($year !== null) {
            $where[] = 'rz.ano = :year';
            $params['year'] = $year;
        }
        if ($uf !== null) {
            $where[] = 'rz.uf = :uf';
            $params['uf'] = $uf;
        }
        $condition = implode(' AND ', $where);

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM totais_candidato WHERE ' . str_replace('rz.', '', $condition)
            );
            $delete->execute($params);
            $insert = $this->pdo->prepare(
                'INSERT INTO totais_candidato (ano, turno, uf, cargo_id, candidato_id, partido_id, votos, situacao) '
                . 'SELECT rz.ano, rz.turno, rz.uf, rz.cargo_id, rz.candidato_id, c.partido_id, SUM(rz.votos), '
                . 'COALESCE(MAX(NULLIF(rz.situacao, \'\')), MAX(NULLIF(c.situacao, \'\')), \'indefinida\') '
                . 'FROM resultados_zona rz JOIN candidatos c ON c.id = rz.candidato_id '
                . 'WHERE ' . $condition . ' '
                . 'GROUP BY rz.ano, rz.turno, rz.uf, rz.cargo_id, rz.candidato_id, c.partido_id'
            );
            $insert->execute($params);
            $count = $insert->rowCount();
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $count;
    }
}
