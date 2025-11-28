<?php
// php/coordenador_actions.php — ações de aprovação (usando emop_change_requests)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conn.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$role = (int)($_SESSION['role'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$nome = $_SESSION['nome'] ?? '—';
if ($role < 3){ http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Acesso negado']); exit; }

$table = "emop_change_requests";
$action = $_POST['action'] ?? null;
$id = (int)($_POST['id'] ?? 0);
if (!$action || !$id){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Parâmetros inválidos']); exit; }

// Busca solicitação
$sql = "SELECT * FROM `$table` WHERE id=$id AND status='pendente' LIMIT 1";
$res = $conn->query($sql);
$row = $res ? $res->fetch_assoc() : null;
if (!$row){ http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'Solicitação não encontrada']); exit; }

if ($action === 'reject'){
  $stmt = $conn->prepare("UPDATE `$table` SET status='rejeitado', reviewed_at=NOW(), reviewed_by=?, reviewed_by_nome=? WHERE id=?");
  $stmt->bind_param("isi", $user_id, $nome, $id);
  $ok = $stmt->execute();
  echo json_encode(['ok'=>$ok?true:false, 'msg'=>$ok?'Solicitação rejeitada.':'Falha ao rejeitar.']);
  exit;
}

if ($action === 'approve'){
  // Aplica alteração no campo especificado (tabela esperada: emop_contratos)
  $contrato_id = (int)$row['contrato_id'];
  $tabela = $row['tabela'] ?: 'emop_contratos';
  $campo = $row['campo'];
  $valor_para = $row['valor_para'];

  $permitidos = [
    'Diretoria','Secretaria','Municipio','Objeto','Empresa','Valor_Do_Contrato','Valor_Liquidado_Acumulado',
    'Percentual_Executado','Saldo_Contratual_Com_Reajuste_RS','Prazo_Inicio','Prazo_Termino','Observacoes',
    'Numero_Contrato'
  ];
  if (!in_array($campo, $permitidos, true)){
    echo json_encode(['ok'=>false,'msg'=>'Campo não permitido para alteração automática. Abra o contrato e edite manualmente.']);
    exit;
  }

  $sql_upd = "UPDATE `".$conn->real_escape_string($tabela)."` SET `".$conn->real_escape_string($campo)."` = ? WHERE id = ? LIMIT 1";
  $stmt = $conn->prepare($sql_upd);
  $stmt->bind_param("si", $valor_para, $contrato_id);
  $ok1 = $stmt->execute();

  if ($ok1){
    $stmt2 = $conn->prepare("UPDATE `$table` SET status='aprovado', reviewed_at=NOW(), reviewed_by=?, reviewed_by_nome=? WHERE id=?");
    $stmt2->bind_param("isi", $user_id, $nome, $id);
    $ok2 = $stmt2->execute();
    echo json_encode(['ok'=>($ok1 && $ok2), 'msg'=>'Alteração aplicada e aprovada.']);
  } else {
    echo json_encode(['ok'=>false,'msg'=>'Falha ao aplicar alteração.']);
  }
  exit;
}

echo json_encode(['ok'=>false,'msg'=>'Ação inválida']);
