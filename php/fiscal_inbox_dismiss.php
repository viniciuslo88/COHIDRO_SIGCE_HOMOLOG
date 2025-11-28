<?php
// php/fiscal_inbox_dismiss.php — Remove item rejeitado da inbox do Fiscal, mantendo no histórico
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';

header('Content-Type: application/json; charset=utf-8');

$role = (int)($_SESSION['role'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!in_array($role, [1,6], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Acesso negado']);
  exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'ID inválido']);
  exit;
}

$table = 'coordenador_inbox';

// === 1. Busca registro completo antes de excluir ===
$res = $conn->query("SELECT * FROM {$table} WHERE id={$id} LIMIT 1");
if (!$res || !$res->num_rows) {
  echo json_encode(['ok'=>false,'error'=>'Registro não encontrado']);
  exit;
}
$row = $res->fetch_assoc();
$res->free();

// === 2. Garante que só rejeitados podem ser removidos ===
if (strtoupper((string)$row['status']) !== 'REJEITADO') {
  echo json_encode(['ok'=>false,'error'=>'Apenas itens REJEITADOS podem ser removidos']);
  exit;
}

// === 3. Copia para histórico ===
$contrato_id = (int)($row['contrato_id'] ?? 0);
$usuario_id  = (int)($row['usuario_id'] ?? $user_id);
$dados_json  = json_encode($row, JSON_UNESCAPED_UNICODE);

$stmt = $conn->prepare("
  INSERT INTO historico_alteracoes_contratos
  (contrato_id, usuario_id, acao, dados_json, criado_em)
  VALUES (?, ?, 'REMOVIDO_DA_INBOX', ?, NOW())
");
$stmt->bind_param('iis', $contrato_id, $usuario_id, $dados_json);
$stmt->execute();
$stmt->close();

// === 4. Remove da inbox ===
$del = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
$del->bind_param('i', $id);
if (!$del->execute()) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Falha ao remover da inbox: '.$conn->error]);
  exit;
}
$del->close();

// === 5. Retorna sucesso ===
echo json_encode(['ok'=>true, 'msg'=>'Item removido da inbox e mantido no histórico']);
