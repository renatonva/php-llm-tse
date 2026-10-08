<?php

declare(strict_types=1);

namespace App\Tse;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final class Client
{
    //Só um adendo: Colocar LLM para processar o que vem da API é uma volumetria imensa de dados, então optei por download do CSV e alimentar o banco
    private const DOWNLOAD_ERROR = 'TSE indisponível ou bloqueou o download; baixe manualmente pelo navegador para data/';

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $dataDirectory,
        ?ClientInterface $httpClient = null,
        private readonly ?string $downloadUrlTemplate = null
    ) {
        $this->httpClient = $httpClient ?? new GuzzleClient(['timeout' => 30]);
    }

    public function getZipPath(string $type, int $year): string
    {
        if (!in_array($type, ['consulta_cand', 'votacao_candidato_munzona'], true)) {
            throw new RuntimeException('Tipo de arquivo TSE inválido.');
        }

        $filename = sprintf('%s_%d.zip', $type, $year);
        $zipPath = rtrim($this->dataDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (is_file($zipPath)) {
            return $zipPath;
        }

        $environmentTemplate = $_ENV['TSE_DOWNLOAD_URL_TEMPLATE']
            ?? $_SERVER['TSE_DOWNLOAD_URL_TEMPLATE']
            ?? getenv('TSE_DOWNLOAD_URL_TEMPLATE');
        $template = $this->downloadUrlTemplate ?? (is_string($environmentTemplate) ? $environmentTemplate : '');
        if ($template === '') {
            throw new RuntimeException(self::DOWNLOAD_ERROR . ' (status HTTP: não disponível; URL não configurada).');
        }

        if (!is_dir($this->dataDirectory) && !mkdir($this->dataDirectory, 0775, true) && !is_dir($this->dataDirectory)) {
            throw new RuntimeException('Não foi possível criar o diretório data/.');
        }

        $url = strtr($template, [
            '{tipo}' => $type,
            '{ano}' => (string) $year,
            '{filename}' => $filename,
        ]);
        $partPath = $zipPath . '.part';
        $lastStatus = 'sem resposta';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            @unlink($partPath);
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'http_errors' => false,
                    'timeout' => 45,
                    'connect_timeout' => 15,
                    'sink' => $partPath,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0.0.0 Safari/537.36',
                        'Accept' => 'application/zip,application/octet-stream,*/*;q=0.8',
                    ],
                ]);
                $lastStatus = (string) $response->getStatusCode();
                $contentType = strtolower($response->getHeaderLine('Content-Type'));
                $handle = is_file($partPath) ? fopen($partPath, 'rb') : false;
                $signature = $handle === false ? '' : (string) fread($handle, 2);
                if (is_resource($handle)) {
                    fclose($handle);
                }

                $valid = $response->getStatusCode() === 200
                    && !str_contains($contentType, 'text/html')
                    && !str_contains($contentType, 'application/xhtml+xml')
                    && is_file($partPath)
                    && filesize($partPath) > 100 * 1024
                    && $signature === 'PK';

                if ($valid && rename($partPath, $zipPath)) {
                    return $zipPath;
                }
            } catch (\Throwable) {
                $lastStatus = 'sem resposta';
            }

            @unlink($partPath);
            if ($attempt < 2) {
                usleep(($attempt + 1) * 1000000);
            }
        }

        throw new RuntimeException(sprintf('%s (status HTTP: %s).', self::DOWNLOAD_ERROR, $lastStatus));
    }
}