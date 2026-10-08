<?php

declare(strict_types=1);

use App\Agent\Agent;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$arguments = array_slice($argv, 1);
$debug = in_array('--debug', $arguments, true);
$arguments = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--debug'));
if ($arguments === []) {
    fwrite(STDERR, "Uso: php bin/ask.php [--debug] \"pergunta\"\n");
    exit(2);
}

$environment = static function (string $key, ?string $default = null): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException('Variável de ambiente ausente: ' . $key);
    }
    return (string) $value;
};

try {
    $port = filter_var($environment('DB_PORT', '3306'), FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException('DB_PORT inválida.');
    }
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $environment('DB_HOST', 'mysql'),
            $port,
            $environment('DB_DATABASE', 'elections')
        ),
        $environment('DB_USERNAME', 'elections'),
        $environment('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $question = implode(' ', $arguments);
    $result = (new Agent($pdo))->answerWithMetadata($question);
    printf("%s\n", $result['answer']);
    printf(
        "tokens_entrada=%d tokens_saida=%d custo=%.8f origem=%s\n",
        $result['input_tokens'],
        $result['output_tokens'],
        $result['cost'],
        $result['origin']
    );
    if ($debug) {
        printf("DEBUG tool_calls=%d duration_ms=%d\n", $result['tool_calls'], $result['duration_ms']);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}