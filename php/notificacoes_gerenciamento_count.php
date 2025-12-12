<?php
// php/notificacoes_gerenciamento_count.php — badge do inbox (nível 5+)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . "/conn.php";

$role = (int)($_SESSION['role'] ?? 0);
if ($role < 5){ http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'forbidden']); exit; }

$pending_reset = 0;
$pending_msgs  = 0;

$r1 = $conn->query("SELECT COUNT(*) AS c FROM senha_reset_pedidos WHERE status='pending'");
if ($r1) { $pending_reset = (int)($r1->fetch_assoc()['c'] ?? 0); }

$r2 = $conn->query("SELECT COUNT(*) AS c FROM gerenciamento_mensagens WHERE status IN ('open','in_progress')");
if ($r2) { $pending_msgs = (int)($r2->fetch_assoc()['c'] ?? 0); }

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
  'pending_reset' => $pending_reset,
  'pending_msgs'  => $pending_msgs,
  'pending_total' => ($pending_reset + $pending_msgs),
]);
