<?php
// php/reset_admin_action.php — processa aprovação/negação; responde JSON quando for AJAX

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . "/conn.php"; // $conn (mysqli)

function j(array $arr, int $http = 200): void {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  http_response_code($http);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

$role     = (int)($_SESSION['role'] ?? $_SESSION['access_level'] ?? $_SESSION['nivel'] ?? $_SESSION['user_level'] ?? 0);
$admin_id = (int)($_SESSION['user_id'] ?? 0);

// Detecta AJAX/fragment (modal)
$is_ajax =
  (($_POST['ajax'] ?? '') === '1') ||
  !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
  !empty($_SERVER['HTTP_X_FRAGMENT']);

if ($role < 5){
  if ($is_ajax) j(['ok'=>false,'error'=>'forbidden','message'=>'Apenas Administradores (nível 5).'], 403);
  http_response_code(403);
  exit('Apenas nível 5.');
}

$id = (int)($_POST['id'] ?? 0);
$op = (string)($_POST['op'] ?? '');

if ($id <= 0 || !in_array($op, ['approve','deny'], true)){
  if ($is_ajax) j(['ok'=>false,'error'=>'bad_request','message'=>'Requisição inválida.'], 422);
  $_SESSION['flash_err'] = "Requisição inválida.";
  header("Location: /php/reset_admin_inbox.php");
  exit;
}

// Carrega pedido
$st = $conn->prepare("SELECT id, user_id, status FROM senha_reset_pedidos WHERE id=? LIMIT 1");
if (!$st){
  if ($is_ajax) j(['ok'=>false,'error'=>'prepare_failed','message'=>'Falha ao preparar consulta.','mysql'=>$conn->error], 500);
  $_SESSION['flash_err'] = "Falha interna.";
  header("Location: /php/reset_admin_inbox.php");
  exit;
}
$st->bind_param("i",$id);
$st->execute();
$ped = $st->get_result()->fetch_assoc();
$st->close();

if (!$ped){
  if ($is_ajax) j(['ok'=>false,'error'=>'not_found','message'=>'Pedido não encontrado.'], 404);
  $_SESSION['flash_err'] = "Pedido não encontrado.";
  header("Location: /php/reset_admin_inbox.php");
  exit;
}

if (($ped['status'] ?? '') !== 'pending'){
  if ($is_ajax) j(['ok'=>false,'error'=>'already_processed','message'=>'Pedido já foi processado.'], 409);
  $_SESSION['flash_err'] = "Pedido já processado.";
  header("Location: /php/reset_admin_inbox.php");
  exit;
}

$uid = (int)$ped['user_id'];

if ($op === 'approve'){
  // anula senha para forçar primeiro acesso
  $up = $conn->prepare("UPDATE usuarios_cohidro_sigce SET senha=NULL WHERE id=? LIMIT 1");
  if (!$up){
    if ($is_ajax) j(['ok'=>false,'error'=>'prepare_failed','message'=>'Falha ao preparar update do usuário.','mysql'=>$conn->error], 500);
    $_SESSION['flash_err'] = "Falha ao aprovar.";
    header("Location: /php/reset_admin_inbox.php");
    exit;
  }
  $up->bind_param("i",$uid);
  $ok1 = $up->execute();
  $err1 = $up->error;
  $up->close();

  if (!$ok1){
    if ($is_ajax) j(['ok'=>false,'error'=>'update_user_failed','message'=>'Falha ao anular a senha do usuário.','mysql'=>$err1], 500);
    $_SESSION['flash_err'] = "Falha ao aprovar.";
    header("Location: /php/reset_admin_inbox.php");
    exit;
  }

  // marca aprovado
  $mk = $conn->prepare("UPDATE senha_reset_pedidos SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? LIMIT 1");
  if (!$mk){
    if ($is_ajax) j(['ok'=>false,'error'=>'prepare_failed','message'=>'Falha ao preparar update do pedido.','mysql'=>$conn->error], 500);
    $_SESSION['flash_err'] = "Falha ao aprovar.";
    header("Location: /php/reset_admin_inbox.php");
    exit;
  }
  $mk->bind_param("ii",$admin_id,$id);
  $ok2 = $mk->execute();
  $err2 = $mk->error;
  $mk->close();

  if ($ok2){
    if ($is_ajax) j(['ok'=>true,'status'=>'approved','message'=>'Reset aprovado e senha anulada.'], 200);
    $_SESSION['flash_ok'] = "Reset aprovado e senha anulada.";
    header("Location: /php/reset_admin_inbox.php");
    exit;
  }

  if ($is_ajax) j(['ok'=>false,'error'=>'mark_approved_failed','message'=>'Não foi possível concluir a aprovação.','mysql'=>$err2], 500);
  $_SESSION['flash_err'] = "Não foi possível concluir a aprovação.";
  header("Location: /php/reset_admin_inbox.php");
  exit;
}

if ($op === 'deny'){
  $motivo = trim((string)($_POST['motivo'] ?? ''));
  if (function_exists('mb_substr')) $motivo = mb_substr($motivo, 0, 255, 'UTF-8');
  else $motivo = substr($motivo, 0, 255);

  $mk = $conn->prepare("UPDATE senha_reset_pedidos SET status='denied', motivo_negativa=?, denied_by=?, denied_at=NOW() WHERE id=? LIMIT 1");
  if (!$mk){
    if ($is_ajax) j(['ok'=>false,'error'=>'prepare_failed','message'=>'Falha ao preparar negativa.','mysql'=>$conn->error], 500);
    $_SESSION['flash_err'] = "Falha ao negar.";
    header("Location: /php/reset_admin_inbox.php");
    exit;
  }
  $mk->bind_param("sii",$motivo,$admin_id,$id);
  $ok = $mk->execute();
  $err = $mk->error;
  $mk->close();

  if ($ok){
    if ($is_ajax) j(['ok'=>true,'status'=>'denied','message'=>'Solicitação negada.'], 200);
    $_SESSION['flash_ok'] = "Solicitação negada.";
    header("Location: /php/reset_admin_inbox.php");
    exit;
  }

  if ($is_ajax) j(['ok'=>false,'error'=>'deny_failed','message'=>'Não foi possível negar a solicitação.','mysql'=>$err], 500);
  $_SESSION['flash_err'] = "Não foi possível negar a solicitação.";
  header("Location: /php/reset_admin_inbox.php");
  exit;
}

// fallback
if ($is_ajax) j(['ok'=>false,'error'=>'invalid_op','message'=>'Operação inválida.'], 422);
http_response_code(422);
echo "Operação inválida.";
