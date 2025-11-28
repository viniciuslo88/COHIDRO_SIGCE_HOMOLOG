<?php
// php/reset_admin_action.php — processa aprovação/negação; responde JSON quando for AJAX
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . "/conn.php";

function j($arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr); exit; }

$role = (int)($_SESSION['role'] ?? 0);
$admin_id = (int)($_SESSION['user_id'] ?? 0);
$is_ajax = (($_POST['ajax'] ?? '') === '1') || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if ($role < 5){ if ($is_ajax) j(['ok'=>false,'error'=>'forbidden']); http_response_code(403); exit('Apenas nível 5.'); }

$id = (int)($_POST['id'] ?? 0);
$op = $_POST['op'] ?? '';
if ($id<=0 || !in_array($op, ['approve','deny'], true)){
  if ($is_ajax) j(['ok'=>false,'error'=>'bad_request']);
  $_SESSION['flash_err']="Requisição inválida."; header("Location: /php/reset_admin_inbox.php"); exit;
}

// Carrega pedido
$st = $conn->prepare("SELECT id, user_id, status FROM senha_reset_pedidos WHERE id=? LIMIT 1");
$st->bind_param("i",$id); $st->execute();
$ped = $st->get_result()->fetch_assoc();
$st->close();
if(!$ped){ if($is_ajax) j(['ok'=>false,'error'=>'not_found']); $_SESSION['flash_err']="Pedido não encontrado."; header("Location: /php/reset_admin_inbox.php"); exit; }
if($ped['status']!=='pending'){ if($is_ajax) j(['ok'=>false,'error'=>'already_processed']); $_SESSION['flash_err']="Pedido já processado."; header("Location: /php/reset_admin_inbox.php"); exit; }

$uid = (int)$ped['user_id'];

if ($op === 'approve'){
  // anula senha para forçar primeiro acesso
  $up = $conn->prepare("UPDATE usuarios_cohidro_sigce SET senha=NULL WHERE id=? LIMIT 1");
  $up->bind_param("i",$uid); $ok1=$up->execute(); $up->close();
  if(!$ok1){ if($is_ajax) j(['ok'=>false,'error'=>'update_user_failed']); $_SESSION['flash_err']="Falha ao aprovar."; header("Location: /php/reset_admin_inbox.php"); exit; }

  // marca aprovado
  $mk = $conn->prepare("UPDATE senha_reset_pedidos SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? LIMIT 1");
  $mk->bind_param("ii",$admin_id,$id); $ok2=$mk->execute(); $mk->close();

  if($ok2){
    if($is_ajax) j(['ok'=>true,'status'=>'approved']);
    $_SESSION['flash_ok']="Reset aprovado e senha anulada."; header("Location: /php/reset_admin_inbox.php"); exit;
  }
  if($is_ajax) j(['ok'=>false,'error'=>'mark_approved_failed']);
  $_SESSION['flash_err']="Não foi possível concluir a aprovação."; header("Location: /php/reset_admin_inbox.php"); exit;
}

if ($op === 'deny'){
  $motivo = trim($_POST['motivo'] ?? '');
  $mk = $conn->prepare("UPDATE senha_reset_pedidos SET status='denied', motivo_negativa=?, denied_by=?, denied_at=NOW() WHERE id=? LIMIT 1");
  $mk->bind_param("sii",$motivo,$admin_id,$id); $ok=$mk->execute(); $mk->close();

  if($ok){
    if($is_ajax) j(['ok'=>true,'status'=>'denied']);
    $_SESSION['flash_ok']="Solicitação negada."; header("Location: /php/reset_admin_inbox.php"); exit;
  }
  if($is_ajax) j(['ok'=>false,'error'=>'deny_failed']);
  $_SESSION['flash_err']="Não foi possível negar a solicitação."; header("Location: /php/reset_admin_inbox.php"); exit;
}
