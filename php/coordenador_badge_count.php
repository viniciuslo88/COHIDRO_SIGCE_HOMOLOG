<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/diretoria_guard.php';

$role = (int)($_SESSION['role'] ?? 0);
if ($role < 2) { echo json_encode(['count'=>0]); exit; }

// 1. O alias agora é 'ci' (para coordenador_inbox)
$wdir_raw = (string)diretoria_guard_where($conn, 'ci');

// 2. A lógica do COALESCE deve bater com a do modal (usando ci.diretoria, minúsculo)
$wdir_raw = preg_replace('/ci\.Diretoria/i', 'COALESCE(ci.diretoria, c.Diretoria)', $wdir_raw);
$wdir_raw = preg_replace('/ci\.diretoria/i', 'COALESCE(ci.diretoria, c.Diretoria)', $wdir_raw); // Garantia

$wdir_raw = trim($wdir_raw ?? '');
if ($wdir_raw !== '') {
    $W_DIR_SQL = preg_match('/^\s*AND\b/i', $wdir_raw) ? " $wdir_raw " : " AND $wdir_raw ";
} else {
    $W_DIR_SQL = '';
}

// 3. A tabela agora é 'coordenador_inbox' com o alias 'ci'
$sql = "
    SELECT COUNT(*) AS n
    FROM coordenador_inbox ci
    LEFT JOIN emop_contratos c ON c.id = ci.contrato_id
    WHERE UPPER(TRIM(ci.status)) = 'PENDENTE' $W_DIR_SQL
";

$res = $conn->query($sql);
$n = 0; if ($res && ($row = $res->fetch_assoc())) $n = (int)$row['n'];
echo json_encode(['count'=>$n], JSON_UNESCAPED_UNICODE);