<?php
// /ajax/coord_inbox_action.php
// Aplica alterações aprovadas (Medições, Aditivos, Reajustes) ao contrato alvo.

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../php/require_auth.php';
require_once __DIR__ . '/../php/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php/conn.php';
require_once __DIR__ . '/../php/roles.php';

// Helpers
require_once __DIR__ . '/../php/medicoes_lib.php';
require_once __DIR__ . '/../php/aditivos_lib.php';
require_once __DIR__ . '/../php/reajustes_lib.php';

try {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) { throw new RuntimeException('Payload inválido'); }

  if (empty($data['contrato_id'])) { throw new RuntimeException('contrato_id ausente'); }
  $contrato_id = (int)$data['contrato_id'];
  if ($contrato_id <= 0) { throw new RuntimeException('contrato_id inválido'); }

  // ===== MEDIÇÕES =====
  if (!empty($data['novas_medicoes']) && is_array($data['novas_medicoes'])) {
    coh_insert_medicoes_from_array($conn, $contrato_id, $data['novas_medicoes']);
  }
  if (!empty($data['delete_medicao_id'])) {
    $id_del = (int)$data['delete_medicao_id'];
    if ($id_del > 0) { coh_delete_medicao($conn, $contrato_id, $id_del); }
  }

  // ===== ADITIVOS =====
  if (!empty($data['novos_aditivos']) && is_array($data['novos_aditivos'])) {
    coh_insert_aditivos_from_array($conn, $contrato_id, $data['novos_aditivos']);
  }
  if (!empty($data['delete_aditivo_id'])) {
    $id_del = (int)$data['delete_aditivo_id'];
    if ($id_del > 0) { coh_delete_aditivo($conn, $contrato_id, $id_del); }
  }

  // ===== REAJUSTES =====
  if (!empty($data['novos_reajustes']) && is_array($data['novos_reajustes'])) {
    coh_insert_reajustes_from_array($conn, $contrato_id, $data['novos_reajustes']);
  }
  if (!empty($data['delete_reajuste_id'])) {
    $id_del = (int)$data['delete_reajuste_id'];
    if ($id_del > 0) { coh_delete_reajuste($conn, $contrato_id, $id_del); }
  }

  // ===== Demais campos aprovados (se houver) =====
  // (Aqui fica sua lógica existente de aplicar campos simples do contrato, histórico etc.)

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
