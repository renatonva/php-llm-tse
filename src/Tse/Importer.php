<?php

declare(strict_types=1);

namespace App\Tse;

use App\Support\TotalsBuilder;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Importer
{
    private const BATCH_SIZE = 500;

    private const CANDIDATE_COLUMNS = [
        'ANO_ELEICAO', 'NR_TURNO', 'NM_TIPO_ELEICAO', 'CD_CARGO', 'DS_CARGO', 'SG_PARTIDO',
        'NM_PARTIDO', 'SQ_CANDIDATO', 'NR_CANDIDATO', 'NM_CANDIDATO', 'NM_URNA_CANDIDATO',
        'SG_UF', 'DS_SITUACAO_CANDIDATURA',
    ];

    private const VOTE_COLUMNS = [
        'ANO_ELEICAO', 'NR_TURNO', 'NM_TIPO_ELEICAO', 'CD_CARGO', 'SQ_CANDIDATO', 'SG_UF',
        'CD_MUNICIPIO', 'NM_MUNICIPIO', 'NR_ZONA', 'QT_VOTOS_NOMINAIS_VALIDOS', 'DS_SIT_TOT_TURNO',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CsvReader $reader,
        private readonly string $rejectedLogPath
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return array{read: int, inserted: int, updated: int, rejected: int} */
    public function importCandidates(string $zipPath, int $year, ?string $uf = null): array
    {
        return $this->importFiles($zipPath, $year, $uf, 'candidatos');
    }

    /** @return array{read: int, inserted: int, updated: int, rejected: int} */
    public function importVoting(string $zipPath, int $year, ?string $uf = null): array
    {
        return $this->importFiles($zipPath, $year, $uf, 'votacao');
    }

    /** @return array{read: int, inserted: int, updated: int, rejected: int} */
    private function importFiles(string $zipPath, int $year, ?string $uf, string $kind): array
    {
        $stats = ['read' => 0, 'inserted' => 0, 'updated' => 0, 'rejected' => 0];
        $ufs = $uf === null ? $this->reader->availableUfs($zipPath) : [$uf];
        $columns = $kind === 'candidatos' ? self::CANDIDATE_COLUMNS : self::VOTE_COLUMNS;

        foreach ($ufs as $currentUf) {
            $batch = [];
            foreach ($this->reader->rows($zipPath, $currentUf, $columns) as $row) {
                $stats['read']++;
                $batch[] = $row;
                if (count($batch) === self::BATCH_SIZE) {
                    $this->processBatch($batch, $kind, $currentUf, $year, $stats);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->processBatch($batch, $kind, $currentUf, $year, $stats);
            }
            if ($kind === 'votacao') {
                (new TotalsBuilder($this->pdo))->rebuild($year, $currentUf);
            }
        }

        return $stats;
    }

    /** @param list<array<string, string>> $batch
     *  @param array{read: int, inserted: int, updated: int, rejected: int} $stats
     */
    private function processBatch(array $batch, string $kind, string $uf, int $year, array &$stats): void
    {
        $rejected = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($batch as $index => $row) {
                try {
                    $affected = $kind === 'candidatos'
                        ? $this->upsertCandidate($row, $uf, $year)
                        : $this->upsertVote($row, $uf, $year);
                    if ($affected === 1) {
                        $stats['inserted']++;
                    } else {
                        $stats['updated']++;
                    }
                } catch (InvalidArgumentException $exception) {
                    $stats['rejected']++;
                    $rejected[] = [
                        'tipo' => $kind,
                        'uf' => $uf,
                        'linha' => $stats['read'] - count($batch) + $index + 2,
                        'motivo' => $exception->getMessage(),
                    ];
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($rejected !== []) {
            $this->appendRejected($rejected);
        }
    }

    private function upsertCandidate(array $row, string $uf, int $year): int
    {
        $sourceYear = $this->positiveInt($row['ANO_ELEICAO'] ?? '', 'ANO_ELEICAO');
        if ($sourceYear !== $year) {
            throw new InvalidArgumentException('ano divergente');
        }
        $turn = $this->positiveInt($row['NR_TURNO'] ?? '', 'NR_TURNO');
        $cargoCode = $this->positiveInt($row['CD_CARGO'] ?? '', 'CD_CARGO');
        $candidateCode = $this->positiveInt($row['SQ_CANDIDATO'] ?? '', 'SQ_CANDIDATO');
        $candidateName = $this->requiredText($row['NM_CANDIDATO'] ?? '', 'NM_CANDIDATO');
        $ballotName = $this->requiredText($row['NM_URNA_CANDIDATO'] ?? '', 'NM_URNA_CANDIDATO');
        $state = strtoupper($this->requiredText($row['SG_UF'] ?? $uf, 'SG_UF'));
        if ($state !== $uf || !in_array($state, CsvReader::supportedUfs(), true)) {
            throw new InvalidArgumentException('UF inválida ou divergente');
        }

        $electionId = $this->upsertElection($year, $turn, $this->requiredText($row['NM_TIPO_ELEICAO'] ?? '', 'NM_TIPO_ELEICAO'));
        $cargoId = $this->upsertCargo($cargoCode, $this->requiredText($row['DS_CARGO'] ?? '', 'DS_CARGO'));
        $partyId = null;
        $partySigla = trim($row['SG_PARTIDO'] ?? '');
        if ($partySigla !== '') {
            $partyId = $this->upsertParty($partySigla, $this->requiredText($row['NM_PARTIDO'] ?? '', 'NM_PARTIDO'));
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO candidatos '
            . '(eleicao_id, partido_id, cargo_id, sq_candidato, numero, uf, situacao, nome, nome_normalizado) '
            . 'VALUES (:eleicao_id, :partido_id, :cargo_id, :sq_candidato, :numero, :uf, :situacao, :nome, :nome_normalizado) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), partido_id = VALUES(partido_id), '
            . 'cargo_id = VALUES(cargo_id), numero = VALUES(numero), uf = VALUES(uf), situacao = VALUES(situacao), '
            . 'nome = VALUES(nome), nome_normalizado = VALUES(nome_normalizado)'
        );
        $statement->execute([
            'eleicao_id' => $electionId,
            'partido_id' => $partyId,
            'cargo_id' => $cargoId,
            'sq_candidato' => $candidateCode,
            'numero' => trim($row['NR_CANDIDATO'] ?? ''),
            'uf' => $state,
            'situacao' => mb_substr(trim($row['DS_SITUACAO_CANDIDATURA'] ?? ''), 0, 128),
            'nome' => $candidateName,
            'nome_normalizado' => $ballotName,
        ]);
        return $statement->rowCount();
    }

    private function upsertVote(array $row, string $uf, int $year): int
    {
        foreach (['CD_MUNICIPIO', 'NR_ZONA', 'SQ_CANDIDATO'] as $required) {
            if (trim($row[$required] ?? '') === '') {
                throw new InvalidArgumentException('campo obrigatório ausente: ' . $required);
            }
        }
        if ($this->positiveInt($row['ANO_ELEICAO'] ?? '', 'ANO_ELEICAO') !== $year) {
            throw new InvalidArgumentException('ano divergente');
        }
        $turn = $this->positiveInt($row['NR_TURNO'] ?? '', 'NR_TURNO');
        $candidateCode = $this->positiveInt($row['SQ_CANDIDATO'], 'SQ_CANDIDATO');
        $cargoCode = $this->positiveInt($row['CD_CARGO'] ?? '', 'CD_CARGO');
        $municipalityCode = $this->requiredText($row['CD_MUNICIPIO'], 'CD_MUNICIPIO');
        $zone = $this->positiveInt($row['NR_ZONA'], 'NR_ZONA');
        $state = strtoupper($this->requiredText($row['SG_UF'] ?? $uf, 'SG_UF'));
        if ($state !== $uf || !in_array($state, CsvReader::supportedUfs(), true)) {
            throw new InvalidArgumentException('UF inválida ou divergente');
        }

        $electionId = $this->upsertElection($year, $turn, $this->requiredText($row['NM_TIPO_ELEICAO'] ?? '', 'NM_TIPO_ELEICAO'));
        $cargoId = $this->upsertCargo($cargoCode, $this->requiredText($row['DS_CARGO'] ?? '', 'DS_CARGO'));
        $candidateQuery = $this->pdo->prepare(
            'SELECT id FROM candidatos WHERE eleicao_id = :eleicao_id AND sq_candidato = :sq_candidato'
        );
        $candidateQuery->execute(['eleicao_id' => $electionId, 'sq_candidato' => $candidateCode]);
        $candidateId = $candidateQuery->fetchColumn();
        if ($candidateId === false) {
            throw new InvalidArgumentException('candidato não importado');
        }

        $municipalityId = $this->upsertMunicipality(
            $state,
            $municipalityCode,
            $this->requiredText($row['NM_MUNICIPIO'] ?? '', 'NM_MUNICIPIO')
        );
        $votes = $this->nonNegativeInt($row['QT_VOTOS_NOMINAIS_VALIDOS'] ?? '', 'QT_VOTOS_NOMINAIS_VALIDOS');
        $statement = $this->pdo->prepare(
            'INSERT INTO resultados_zona '
            . '(eleicao_id, candidato_id, cargo_id, ano, uf, municipio_id, turno, nr_zona, votos, situacao) '
            . 'VALUES (:eleicao_id, :candidato_id, :cargo_id, :ano, :uf, :municipio_id, :turno, :nr_zona, :votos, :situacao) '
            . 'ON DUPLICATE KEY UPDATE votos = VALUES(votos), situacao = VALUES(situacao)'
        );
        $statement->execute([
            'eleicao_id' => $electionId,
            'candidato_id' => $candidateId,
            'cargo_id' => $cargoId,
            'ano' => $year,
            'uf' => $state,
            'municipio_id' => $municipalityId,
            'turno' => $turn,
            'nr_zona' => $zone,
            'votos' => $votes,
            'situacao' => mb_substr(trim($row['DS_SIT_TOT_TURNO'] ?? ''), 0, 128),
        ]);
        return $statement->rowCount();
    }

    private function upsertElection(int $year, int $turn, string $type): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO eleicoes (ano, turno, tipo) VALUES (:ano, :turno, :tipo) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), tipo = VALUES(tipo)'
        );
        $statement->execute(['ano' => $year, 'turno' => $turn, 'tipo' => $type]);
        return (int) $this->pdo->lastInsertId();
    }

    private function upsertParty(string $sigla, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO partidos (sigla, nome) VALUES (:sigla, :nome) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nome = VALUES(nome)'
        );
        $statement->execute(['sigla' => $sigla, 'nome' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function upsertCargo(int $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cargos (codigo_tse, nome) VALUES (:codigo_tse, :nome) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nome = VALUES(nome)'
        );
        $statement->execute(['codigo_tse' => $code, 'nome' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function upsertMunicipality(string $uf, string $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO municipios (codigo_tse, nome, uf) VALUES (:codigo_tse, :nome, :uf) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), nome = VALUES(nome)'
        );
        $statement->execute(['codigo_tse' => $code, 'nome' => $name, 'uf' => $uf]);
        return (int) $this->pdo->lastInsertId();
    }

    private function appendRejected(array $entries): void
    {
        $directory = dirname($this->rejectedLogPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de logs/.');
        }
        $handle = fopen($this->rejectedLogPath, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível escrever logs/import_rejeitados.log.');
        }
        try {
            foreach ($entries as $entry) {
                fwrite($handle, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
            }
        } finally {
            fclose($handle);
        }
    }

    private function requiredText(string $value, string $column): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('campo obrigatório vazio: ' . $column);
        }
        return $value;
    }

    private function positiveInt(string $value, string $column): int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('valor inválido: ' . $column);
        }
        return (int) $value;
    }

    private function nonNegativeInt(string $value, string $column): int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            throw new InvalidArgumentException('valor inválido: ' . $column);
        }
        return (int) $value;
    }
}