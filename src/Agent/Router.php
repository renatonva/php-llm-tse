<?php

declare(strict_types=1);

namespace App\Agent;

use App\Support\Cache;

final class Router
{
    private const UF_NAMES = [
        'acre' => 'AC', 'alagoas' => 'AL', 'amapa' => 'AP', 'amazonas' => 'AM',
        'bahia' => 'BA', 'ceara' => 'CE', 'distrito federal' => 'DF', 'espirito santo' => 'ES',
        'goias' => 'GO', 'maranhao' => 'MA', 'mato grosso' => 'MT', 'mato grosso do sul' => 'MS',
        'minas gerais' => 'MG', 'para' => 'PA', 'paraiba' => 'PB', 'parana' => 'PR',
        'pernambuco' => 'PE', 'piaui' => 'PI', 'rio de janeiro' => 'RJ', 'rio grande do norte' => 'RN',
        'rio grande do sul' => 'RS', 'rondonia' => 'RO', 'roraima' => 'RR', 'santa catarina' => 'SC',
        'sao paulo' => 'SP', 'sergipe' => 'SE', 'tocantins' => 'TO',
    ];

    /** @param callable(string, array): array $executeTool */
    public function resolve(string $question, callable $executeTool): ?string
    {
        $query = trim(Cache::normalize($question));
        $query = preg_replace('/[?!.,;:]+$/', '', $query) ?? $query;

        if (preg_match('/\b(?:compare|comparar|comparacao)\b.*?\b(governador|senador|deputado federal|deputado estadual|presidente)\b.*?\b(?:de|do|da|em)\s+(.+?)\s+(?:em\s+)?(20\d{2})\s+(?:e|vs|versus)\s+(20\d{2})\b/', $query, $matches)) {
            $uf = self::resolveUf($matches[2]);
            if ($uf !== null) {
                $years = [(int) $matches[3], (int) $matches[4]];
                $result = $executeTool('resultado_eleicao', [
                    'anos' => $years, 'uf' => $uf, 'cargo' => $matches[1], 'limite' => 1,
                ]);
                return self::comparison($result, $matches[1], $uf, $years);
            }
        }

        if (preg_match('/\bquem\b.*\b(?:ganhou|venceu|foi eleito|foi eleita)\b.*\bgovernador(?:a)?\b.*\b(?:de|do|da|em)\s+(.+?)\s+em\s+(20\d{2})\b/', $query, $matches)) {
            $uf = self::resolveUf($matches[1]);
            if ($uf !== null) {
                return self::winner($executeTool('resultado_eleicao', [
                    'ano' => (int) $matches[2], 'uf' => $uf, 'cargo' => 'governador', 'limite' => 1,
                ]), $uf, (int) $matches[2]);
            }
        }

        if (preg_match('/^ranking de partidos para (governador|senador|deputado federal|deputado estadual|presidente|prefeito|vereador) em (.+?) (?:em )?(20\d{2})$/', $query, $matches)) {
            $uf = self::resolveUf($matches[2]);
            if ($uf !== null) {
                $year = (int) $matches[3];
                $result = $executeTool('ranking_partidos', [
                    'ano' => $year, 'uf' => $uf, 'cargo' => $matches[1], 'limite' => 10,
                ]);
                return self::partyRanking($result, $matches[1], $uf, $year);
            }
        }

        if (preg_match('/^quantos votos teve (.+?) em (20\d{2})$/', $query, $matches)) {
            return self::candidateVotes($executeTool, trim($matches[1]), (int) $matches[2]);
        }

        return null;
    }

    private static function candidateVotes(callable $executeTool, string $name, int $year): string
    {
        $search = $executeTool('buscar_candidato', ['nome' => $name, 'ano' => $year]);
        $candidates = $search['candidatos'] ?? [];
        if (isset($search['erro']) || !is_array($candidates) || $candidates === []) {
            return 'Sem dados importados para esse candidato e ano.';
        }

        $matches = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => Cache::normalize((string) ($candidate['nome_urna'] ?? '')) === Cache::normalize($name)
        ));
        if (count($matches) !== 1) {
            return 'Encontrei mais de um candidato com esse nome; informe a UF ou o cargo.';
        }

        $candidate = $matches[0];
        $uf = strtoupper((string) ($candidate['uf'] ?? ''));
        $cargo = (string) ($candidate['cargo'] ?? '');
        if ($uf === '' || $cargo === '') {
            return 'Não foi possível identificar a UF e o cargo; reformule a pergunta.';
        }

        $result = $executeTool('resultado_eleicao', [
            'ano' => $year, 'uf' => $uf, 'cargo' => $cargo, 'limite' => 50,
        ]);
        if (isset($result['erro'])) {
            return 'Sem dados importados para esse candidato e ano.';
        }
        foreach ($result['resultados'] ?? [] as $row) {
            if (Cache::normalize((string) ($row['candidato'] ?? '')) === Cache::normalize($name)) {
                return sprintf(
                    '%s teve %s votos para %s em %s em %d.',
                    $row['candidato'],
                    number_format((int) $row['votos'], 0, ',', '.'),
                    mb_strtolower($cargo, 'UTF-8'),
                    $uf,
                    $year
                );
            }
        }

        return 'Sem dados importados de votação para esse candidato e ano.';
    }

    private static function winner(array $result, string $uf, int $year): string
    {
        if (isset($result['erro'])) {
            return ucfirst((string) $result['erro']) . '.';
        }
        if (empty($result['resultados'][0])) {
            return 'Sem dados importados para governador de ' . $uf . ' em ' . $year . '.';
        }
        $winner = $result['resultados'][0];
        $party = !empty($winner['partido']) ? ' (' . $winner['partido'] . ')' : '';

        return sprintf(
            '%s%s venceu para governador de %s em %d, com %s votos.',
            $winner['candidato'],
            $party,
            $uf,
            $year,
            number_format((int) $winner['votos'], 0, ',', '.')
        );
    }

    private static function partyRanking(array $result, string $cargo, string $uf, int $year): string
    {
        if (isset($result['erro']) || empty($result['partidos'])) {
            return 'Sem dados importados para ranking de partidos em ' . $uf . ' em ' . $year . '.';
        }
        $lines = [];
        foreach ($result['partidos'] as $index => $party) {
            $lines[] = sprintf(
                '%d. %s: %s votos (%.2f%%)',
                $index + 1,
                $party['partido'],
                number_format((int) $party['votos'], 0, ',', '.'),
                (float) $party['percentual']
            );
        }

        return 'Ranking de partidos para ' . $cargo . ' em ' . $uf . ' em ' . $year . ': ' . implode('; ', $lines);
    }

    /** @param list<int> $years */
    private static function comparison(array $result, string $cargo, string $uf, array $years): string
    {
        $comparisons = $result['comparacao'] ?? [];
        $lines = [];
        foreach ($years as $year) {
            $yearResult = $comparisons[$year] ?? $comparisons[(string) $year] ?? ['erro' => 'resultado indisponível'];
            if (isset($yearResult['erro'])) {
                $lines[] = $year . ': ' . $yearResult['erro'];
                continue;
            }
            $winner = $yearResult['resultados'][0] ?? null;
            if (!is_array($winner)) {
                $lines[] = $year . ': não há dados de votação importados';
                continue;
            }
            $lines[] = sprintf(
                '%d: %s (%s votos)',
                $year,
                $winner['candidato'],
                number_format((int) $winner['votos'], 0, ',', '.')
            );
        }

        return sprintf('Comparação de %s em %s — %s.', $cargo, $uf, implode('; ', $lines));
    }

    private static function resolveUf(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^[a-z]{2}$/', $value)) {
            return strtoupper($value);
        }

        return self::UF_NAMES[$value] ?? null;
    }
}