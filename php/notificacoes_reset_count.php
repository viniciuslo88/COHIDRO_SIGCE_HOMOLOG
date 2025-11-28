<?php
// php/notificacoes_reset_count.php — retorna JSON {pending: N} para admins (role >=5)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . "/conn.php";

$role = (int)($_SESSION['role'] ?? 0);
if ($role < 5) { echo json_encode(['pending'=>0]); exit; }

$res = $conn->query("SELECT COUNT(*) AS c FROM senha_reset_pedidos WHERE status='pending'");
$row = $res ? $res->fetch_assoc() : ['c'=>0];
echo json_encode(['pending' => (int)$row['c']]);
