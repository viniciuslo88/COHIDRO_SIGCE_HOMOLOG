<?php
// php/notificacoes_reset_count.php — retorna JSON {pending: N} para admins (role >=5)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

while (ob_get_level() > 0) { @ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/conn.php';

$role = (int)($_SESSION['role'] ?? ($_SESSION['access_level'] ?? ($_SESSION['nivel'] ?? 0)));
if ($role < 5) {
  echo json_encode(['pending' => 0], JSON_UNESCAPED_UNICODE);
  exit;
}

$n = 0;
if ($st = $conn->prepare("SELECT COUNT(*) AS c FROM senha_reset_pedidos WHERE status='pending'")) {
  $st->execute();
  $rs = $st->get_result();
  if ($rs && ($row = $rs->fetch_assoc())) $n = (int)($row['c'] ?? 0);
  $st->close();
}

echo json_encode(['pending' => $n], JSON_UNESCAPED_UNICODE);
exit;
