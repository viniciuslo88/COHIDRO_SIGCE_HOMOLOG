<?php
// php/coordenador_rejeitar.php — Rejeitar (motivo obrigatório + opção de solicitar revisão ao Fiscal)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require __DIR__ . '/require_auth.php';
require __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/roles.php';

header('Content-Type: application/json; charset=utf-8');

// ===== Helpers mínimos =====
function column_exists(mysqli $c, string $table, string $col): bool {
  $t = $c->real_escape_string($table);
  $col = $c->real_escape_string($col);
  if (!$rs = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'")) return false;
  $ok = ($rs->num_rows > 0); $rs->free(); return $ok;
}
function esc(mysqli $c, $v): string {
  return $c->real_escape_string(trim((string)$v));
}

// ===== Auth / Role =====
$role    = (int)($_SESSION['role']    ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($role, [ROLE_COORDENADOR, ROLE_DESENVOLVEDOR], true)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Acesso negado']); exit;
}

// ===== Input =====
$id      = (int)($_POST['id']      ?? 0);
$motivo  = trim((string)($_POST['motivo']  ?? ''));
$revisao = (int)($_POST['revisao'] ?? 0); // 1 = solicitar revisão ao Fiscal

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'ID ausente']); exit;
}
if ($motivo === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Motivo é obrigatório']); exit;
}

// ===== Regra de status =====
$status = ($revisao === 1) ? 'REVISAO_SOLICITADA' : 'REJEITADO';

// ===== Atualização na coordenador_inbox =====
$table = 'coordenador_inbox';

$conn->begin_transaction();
try {
  // Detecta colunas opcionais (mantém compatibilidade sem alterar schema aqui)
  $has_review_notes = column_exists($conn, $table, 'review_notes');
  $has_needs_rev    = column_exists($conn, $table, 'needs_revision');
  $has_processed_by = column_exists($conn, $table, 'processed_by');
  $has_processed_at = column_exists($conn, $table, 'processed_at');

  $sets = [];
  $sets[] = "status='{$status}'";
  if ($has_review_notes) $sets[] = "review_notes='" . esc($conn, $motivo) . "'";
  if ($has_needs_rev)    $sets[] = "needs_revision=" . ($revisao === 1 ? 1 : 0);
  if ($has_processed_by) $sets[] = "processed_by={$user_id}";
  if ($has_processed_at) $sets[] = "processed_at=NOW()";

  $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE id={$id}";

  if (!$conn->query($sql)) {
    throw new Exception('Falha ao rejeitar: ' . $conn->error);
  }

  $conn->commit();
  echo json_encode(['ok' => true, 'status' => $status], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
