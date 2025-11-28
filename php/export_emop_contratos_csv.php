<?php
// export_emop_contratos_csv.php
// Exporta CSV de emop_contratos (todas as colunas), com filtro por Diretoria,
// respeitando permissões: roles 2/3 limitados à própria diretoria; roles 4/5 podem exportar "todas".

require __DIR__ . '/require_auth.php';
require __DIR__ . '/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
    header('Location: /login_senha.php');
    exit;
}

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/conn.php';

// *** Força UTF-8 na conexão ***
if (method_exists($conn, 'set_charset')) {
    $conn->set_charset('utf8mb4');
}
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// ===== Sessão / Permissão =====
$role    = (int)($_SESSION['role'] ?? 0);
$userDir = trim((string)($_SESSION['diretoria'] ?? ''));
$canAll  = in_array($role, [4,5], true); // 4=Presidente, 5=Administrador

// ===== Entrada =====
$diretoria = isset($_GET['diretoria']) ? trim((string)$_GET['diretoria']) : '';

// Regra: se NÃO pode tudo (2/3), força diretoria da sessão
if (!$canAll) {
    if ($userDir === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Exportação não autorizada: diretoria do usuário não definida.";
        exit;
    }
    $diretoria = $userDir;
}

// ===== Obter colunas dinamicamente =====
$cols = [];
$colRes = $conn->query("SHOW COLUMNS FROM `emop_contratos`");
if (!$colRes) {
    http_response_code(500);
    echo "Erro ao obter colunas: " . $conn->error;
    exit;
}
while ($c = $colRes->fetch_assoc()) {
    $cols[] = $c['Field'];
}
$colRes->free();

if (empty($cols)) {
    http_response_code(500);
    echo "Nenhuma coluna encontrada na tabela emop_contratos.";
    exit;
}

// ===== Montar SQL =====
$escaped_cols = array_map(function ($c) use ($conn) {
    return "`" . $conn->real_escape_string($c) . "`";
}, $cols);

$sql = "SELECT " . implode(",", $escaped_cols) . " FROM `emop_contratos`";

$params = [];
$types  = '';

if ($diretoria !== '') {
    $sql .= " WHERE `Diretoria` = ?";
    $params[] = $diretoria;
    $types .= 's';
}

$sql .= " ORDER BY `id`";

// ===== Executar =====
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo "Erro prepare: " . $conn->error;
        exit;
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo "Erro execute: " . $stmt->error;
        exit;
    }
    $res = $stmt->get_result();
} else {
    $res = $conn->query($sql);
    if (!$res) {
        http_response_code(500);
        echo "Erro query: " . $conn->error;
        exit;
    }
}

// ===== Cabeçalhos do arquivo =====
$tag = $diretoria !== '' ? preg_replace('/\W+/', '_', $diretoria) : 'todas';
$filename = "emop_contratos_{$tag}_" . date('Ymd_His') . ".csv";

// Garante que não existe nenhuma saída antes dos headers/BOM
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Encoding: UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ===== BOM para Excel =====
echo "\xEF\xBB\xBF";

$delim = ';';
$encl  = '"';

// ===== Cabeçalho =====
echo implode($delim, array_map(function ($v) use ($encl) {
    $v = str_replace($encl, $encl . $encl, $v);
    return $encl . $v . $encl;
}, $cols)) . "\r\n";

// ===== Linhas =====
while ($row = $res->fetch_assoc()) {
    $out = [];
    foreach ($cols as $c) {
        $v = isset($row[$c]) ? $row[$c] : '';
        if ($v === null) { $v = ''; }

        // Normaliza quebras de linha
        if (is_string($v)) {
            $v = str_replace(["\r\n", "\n", "\r"], ' ', $v);
        }

        // Fallback para dados legados ISO-8859-1/Windows-1252 -> UTF-8
        if (is_string($v) && function_exists('mb_check_encoding')) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
            }
        }

        // Escapa aspas do CSV
        $v = (string)$v;
        $v = str_replace($encl, $encl . $encl, $v);

        $out[] = $encl . $v . $encl;
    }
    echo implode($delim, $out) . "\r\n";
}

// ===== Limpeza =====
if (isset($stmt) && $stmt) { $stmt->close(); }
if ($res instanceof mysqli_result) { $res->free(); }
exit;
