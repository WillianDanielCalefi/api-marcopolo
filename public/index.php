<?php
declare(strict_types=1);

function loadLocalEnv(string $filePath): void
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            continue;
        }
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && $value[strlen($value) - 1] === '"')
                || ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $currentValue = getenv($name);
        if ($currentValue === false || $currentValue === '') {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

loadLocalEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

function respondJson(int $status, array $payload, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    $body = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($body === false) {
        http_response_code(500);
        echo '{"error":true,"code":"JSON_ENCODING_ERROR","message":"Falha ao montar a resposta."}';
        exit;
    }

    echo $body;
    exit;
}

function corsHeaders(): void
{
    $defaultOrigins = [
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://localhost:5500',
        'http://127.0.0.1:5500',
    ];
    $configuredOrigins = getenv('CORS_ALLOWED_ORIGINS');
    $allowedOrigins = $configuredOrigins === false || trim($configuredOrigins) === ''
        ? $defaultOrigins
        : array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))));

    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Expose-Headers: Retry-After');
    header('Access-Control-Max-Age: 600');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    if (!in_array($origin, $allowedOrigins, true)) {
        respondJson(403, [
            'error' => true,
            'code' => 'ORIGIN_NOT_ALLOWED',
            'message' => 'A origem desta pagina nao esta autorizada.',
        ]);
    }

    header('Access-Control-Allow-Origin: ' . $origin);
}

function isValidRetryAfter(?string $value): bool
{
    if ($value === null) {
        return false;
    }

    return preg_match('/^\d+$/', $value) === 1
        || strtotime($value) !== false;
}

corsHeaders();
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    respondJson(405, [
        'error' => true,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido.',
    ], ['Allow' => 'GET, OPTIONS']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/api/market/quote') {
    $operation = 'quote';
} elseif ($path === '/api/market/historical') {
    $operation = 'historical';
} else {
    respondJson(404, [
        'error' => true,
        'code' => 'NOT_FOUND',
        'message' => 'Rota da API nao encontrada.',
    ]);
}

$requestedSymbols = $_GET['symbols'] ?? '';
if (!is_string($requestedSymbols) || trim($requestedSymbols) === '') {
    respondJson(400, [
        'error' => true,
        'code' => 'BAD_REQUEST',
        'message' => 'Informe os ativos no parametro symbols.',
    ]);
}

$symbols = [];
foreach (explode(',', strtoupper($requestedSymbols)) as $symbol) {
    $symbol = trim($symbol);
    if (preg_match('/^[A-Z0-9][A-Z0-9.-]{0,14}$/D', $symbol) !== 1) {
        respondJson(400, [
            'error' => true,
            'code' => 'BAD_REQUEST',
            'message' => 'O parametro symbols contem um ativo invalido.',
        ]);
    }
    $symbols[] = $symbol;
}
$symbols = array_values(array_unique($symbols));
if (count($symbols) > 10) {
    respondJson(400, [
        'error' => true,
        'code' => 'BAD_REQUEST',
        'message' => 'O limite local e de 10 ativos por chamada.',
    ]);
}

$query = ['symbols' => implode(',', $symbols)];
if ($operation === 'historical') {
    $range = $_GET['range'] ?? '1mo';
    if (!is_string($range) || !in_array($range, ['1mo', '3mo'], true)) {
        respondJson(400, [
            'error' => true,
            'code' => 'BAD_REQUEST',
            'message' => 'O periodo deve ser 1mo ou 3mo.',
        ]);
    }
    $query['range'] = $range;
    $query['interval'] = '1d';
    $query['sortOrder'] = 'asc';
}

$token = getenv('BRAPI_TOKEN');
$token = $token === false ? '' : trim($token);
$publicTestSymbols = ['PETR4', 'VALE3', 'MGLU3', 'ITUB4'];
if ($token === '') {
    $unauthorizedSymbols = array_diff($symbols, $publicTestSymbols);
    if ($unauthorizedSymbols !== []) {
        respondJson(401, [
            'error' => true,
            'code' => 'BRAPI_TOKEN_MISSING',
            'message' => 'Configure BRAPI_TOKEN no ambiente do servidor para consultar esses ativos.',
        ]);
    }
}

if (!function_exists('curl_init')) {
    respondJson(500, [
        'error' => true,
        'code' => 'CURL_EXTENSION_MISSING',
        'message' => 'A extensao cURL do PHP nao esta habilitada no servidor.',
    ]);
}

$endpoint = 'https://brapi.dev/api/v2/stocks/' . $operation;
$url = $endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
$headers = ['Accept: application/json'];
if ($token !== '') {
    $headers[] = 'Authorization: Bearer ' . $token;
}

$retryAfter = null;
$curl = curl_init($url);
if ($curl === false) {
    respondJson(502, [
        'error' => true,
        'code' => 'UPSTREAM_ERROR',
        'message' => 'Nao foi possivel iniciar a consulta a brapi.',
    ]);
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$retryAfter): int {
        if (stripos($headerLine, 'Retry-After:') === 0) {
            $retryAfter = trim(substr($headerLine, strlen('Retry-After:')));
        }
        return strlen($headerLine);
    },
]);

$body = curl_exec($curl);
$curlError = curl_error($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($body === false) {
    error_log('Brapi request failed: ' . $curlError);
    respondJson(502, [
        'error' => true,
        'code' => 'UPSTREAM_ERROR',
        'message' => 'Nao foi possivel consultar a brapi. Tente novamente mais tarde.',
    ]);
}

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    respondJson(502, [
        'error' => true,
        'code' => 'INVALID_UPSTREAM_RESPONSE',
        'message' => 'A brapi retornou uma resposta que nao e JSON valido.',
    ]);
}

if ($status < 200 || $status >= 300) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status === 429 && isValidRetryAfter($retryAfter)) {
        header('Retry-After: ' . $retryAfter);
    }
    echo $body;
    exit;
}

if (!isset($decoded['results']) || !is_array($decoded['results'])) {
    respondJson(502, [
        'error' => true,
        'code' => 'INVALID_UPSTREAM_RESPONSE',
        'message' => 'A resposta da brapi nao contem a lista esperada de resultados.',
    ]);
}

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo $body;
