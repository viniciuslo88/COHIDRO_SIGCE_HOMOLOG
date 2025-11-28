<?php
/**
 * php/conn.php — conexão MySQL segura (mysqli) com carregamento robusto de .env
 * Tenta múltiplos caminhos para o .env e expõe $conn (mysqli) em utf8mb4.
 */

mysqli_report(MYSQLI_REPORT_OFF);

// ---- localizar .env ----
$envCandidates = [
    __DIR__ . '/../.env',                       // /php/../.env (raiz do site)
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/.env',
    dirname(__DIR__, 2) . '/.env',              // caso a estrutura difira
    __DIR__ . '/.env',                          // fallback dentro de /php
];
$loadedEnv = null;
foreach ($envCandidates as $p) {
    if ($p && is_file($p) && is_readable($p)) {
        foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(ltrim($line), '#') === 0) continue;
            $parts = explode('=', $line, 2);
            $k = trim($parts[0] ?? '');
            $v = trim($parts[1] ?? '');
            if ($k !== '') $_ENV[$k] = $v;
        }
        $loadedEnv = $p;
        break;
    }
}

// ---- configs (padrões seguros) ----
$DB_HOST   = $_ENV['DB_HOST']   ?? 'localhost';
$DB_USER   = $_ENV['DB_USER']   ?? 'user';
$DB_PASS   = $_ENV['DB_PASS']   ?? 'pass';
$DB_NAME   = $_ENV['DB_NAME']   ?? 'db';
$DB_PORT   = (int)($_ENV['DB_PORT'] ?? 3306);
$DB_SOCKET = $_ENV['DB_SOCKET'] ?? '';
$APP_ENV   = $_ENV['APP_ENV']   ?? 'prod';  // 'dev' mostra erros na tela

// ---- conecta (prioriza SOCKET se definido) ----
if ($DB_SOCKET) {
    $mysqli = new mysqli(null, $DB_USER, $DB_PASS, $DB_NAME, null, $DB_SOCKET);
} else {
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
}

// ---- erro de conexão ----
if ($mysqli->connect_errno) {
    if ($APP_ENV === 'dev') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "[DEV] Falha MySQL: {$mysqli->connect_errno} - {$mysqli->connect_error}\n";
        echo "Host: $DB_HOST  Port: $DB_PORT  Socket: $DB_SOCKET\n";
        echo "User: $DB_USER  DB: $DB_NAME\n";
        echo "ENV file lido: ".$loadedEnv."\n";
        exit;
    } else {
        error_log('[MySQL] Falha de conexão ('.$mysqli->connect_errno.'): '.$mysqli->connect_error);
        http_response_code(500);
        die('Serviço temporariamente indisponível.');
    }
}

// ---- charset ----
$mysqli->set_charset('utf8mb4');

// exporta compatível
$conn = $mysqli;
