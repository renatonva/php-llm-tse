<?php

declare(strict_types=1);

namespace App\Tse;

use Generator;
use RuntimeException;
use ZipArchive;

final class CsvReader
{
    private const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
        'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** @return list<string> */
    public function availableUfs(string $zipPath): array
    {
        $zip = $this->openZip($zipPath);
        $ufs = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name !== false && preg_match('/_([A-Z]{2})\.csv$/i', $name, $matches) === 1) {
                $uf = strtoupper($matches[1]);
                if (in_array($uf, self::UFS, true)) {
                    $ufs[$uf] = true;
                }
            }
        }
        $zip->close();

        $result = array_keys($ufs);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param list<string> $requiredColumns
     *  @return Generator<int, array<string, string>>
     */
    public function rows(string $zipPath, string $uf, array $requiredColumns): Generator
    {
        $zip = $this->openZip($zipPath);
        $entry = $this->findEntry($zip, $uf);
        if ($entry === null) {
            $zip->close();
            throw new RuntimeException(sprintf('O ZIP não contém CSV para a UF %s.', $uf));
        }

        $stream = $zip->getStream($entry);
        if ($stream === false) {
            $zip->close();
            throw new RuntimeException(sprintf('Não foi possível abrir %s dentro do ZIP.', basename($entry)));
        }

        try {
            $header = fgetcsv($stream, null, ';', '"', '');
            if ($header === false) {
                throw new RuntimeException(sprintf('CSV vazio: %s.', basename($entry)));
            }
            $columns = array_map(fn (string|array|null $value): string => $this->toUtf8((string) $value), $header);
            if (isset($columns[0])) {
                $columns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $columns[0]) ?? $columns[0];
            }
            $missing = array_values(array_diff($requiredColumns, $columns));
            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'CSV %s sem colunas esperadas: %s.',
                    basename($entry),
                    implode(', ', $missing)
                ));
            }

            while (($values = fgetcsv($stream, null, ';', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                $row = [];
                foreach ($columns as $index => $column) {
                    $row[$column] = $this->toUtf8((string) ($values[$index] ?? ''));
                }
                yield $row;
            }
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    /** @return list<string> */
    public static function supportedUfs(): array
    {
        return self::UFS;
    }

    private function openZip(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(sprintf('Não foi possível abrir o ZIP %s.', basename($zipPath)));
        }
        return $zip;
    }

    private function findEntry(ZipArchive $zip, string $uf): ?string
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name !== false && preg_match('/_' . preg_quote($uf, '/') . '\.csv$/i', $name) === 1) {
                return $name;
            }
        }
        return null;
    }

    private function toUtf8(string $value): string
    {
        $converted = iconv('ISO-8859-1', 'UTF-8', $value);
        if ($converted === false) {
            throw new RuntimeException('Não foi possível converter o CSV de ISO-8859-1 para UTF-8.');
        }
        return $converted;
    }
}