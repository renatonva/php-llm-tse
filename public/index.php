<?php

declare(strict_types=1);

use App\Agent\Agent;
use App\Support\ChatHistory;
use App\Support\ChatInput;
use App\Support\RateLimiter;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($method === 'GET' && $path === '/') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
    return;
}

if ($method === 'GET' && $path === '/chat.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/chat.html');
    return;
}

if ($method === 'POST' && $path === '/chat') {
    handleChatRequest();
    return;
}

http_response_code($method === 'GET' || $method === 'POST' ? 404 : 405);
header('Content-Type: text/plain; charset=utf-8');
echo 'Não encontrado.';

function handleChatRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');

    try {
        $rawBody = file_get_contents('php://input', false, null, 0, 65536);
        if ($rawBody === false) {
            respondJson(400, ['error' => 'Não foi possível ler a solicitação.']);
            return;
        }
        try {
            $payload = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            respondJson(400, ['error' => 'JSON inválido.']);
            return;
        }

        $input = ChatInput::validate($payload);
        $pdo = createPdo();
        $maxRequests = max(1, (int) environment('RATE_LIMIT_MAX_REQUESTS', '20'));
        $windowSeconds = max(1, (int) environment('RATE_LIMIT_WINDOW_SECONDS', '60'));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!(new RateLimiter($pdo))->allow($ip, $maxRequests, $windowSeconds)) {
            header('Retry-After: ' . $windowSeconds);
            respondJson(429, ['error' => 'Muitas requisições. Tente novamente em instantes.']);
            return;
        }

        $historyStore = new ChatHistory($pdo);
        $historyStore->deleteExpired();
        $historyLimit = max(0, (int) environment('OPENROUTER_HISTORY_MESSAGES', '6'));
        $history = $historyStore->recent($input['session_id'], $historyLimit);
        $result = (new Agent($pdo))->answerWithMetadata($input['message'], $history);
        $historyStore->append($input['session_id'], $input['message'], $result['answer']);
        respondJson(200, ['answer' => $result['answer'], 'origin' => $result['origin']]);
    } catch (InvalidArgumentException $exception) {
        respondJson(400, ['error' => $exception->getMessage()]);
    } catch (Throwable $exception) {
        error_log('POST /chat failed: ' . $exception->getMessage());
        respondJson(500, ['error' => 'Erro interno ao processar a solicitação.']);
    }
}

function createPdo(): PDO
{
    $port = filter_var(environment('DB_PORT', '3306'), FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid DB_PORT.');
    }

    return new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            environment('DB_HOST', 'mysql'),
            $port,
            environment('DB_DATABASE', 'elections')
        ),
        environment('DB_USERNAME', 'elections'),
        environment('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function environment(string $key, string $default): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return $value === false || $value === null ? $default : (string) $value;
}

function respondJson(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}