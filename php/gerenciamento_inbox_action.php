<?php
// php/gerenciamento_inbox_action.php — ações no inbox do Gerenciamento (nível 5)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/conn.php';

$role = (int)($_SESSION['role'] ?? 0);
if ($role < 5){ http_response_code(403); die('Apenas nível 5.'); }

function j($arr){ header('Content-Type: application/json; charset=UTF-8'); echo json_encode($arr); exit; }

$is_ajax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH']);

$op = (string)($_POST['op'] ?? '');
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0 || $op === '') {
  if ($is_ajax) j(['ok'=>false,'error'=>'invalid']);
  header('Location: /'); exit;
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);

if ($op === 'set_in_progress') {
  $st = $conn->prepare("UPDATE gerenciamento_mensagens SET status='in_progress', handled_by=?, handled_at=IFNULL(handled_at,NOW()) WHERE id=? LIMIT 1");
  $st->bind_param("ii",$admin_id,$id);
  $ok = $st->execute();
  $st->close();
  if ($is_ajax) j(['ok'=>$ok]);
  header('Location: /'); exit;
}

if ($op === 'close') {
  $st = $conn->prepare("UPDATE gerenciamento_mensagens SET status='closed', handled_by=?, handled_at=NOW() WHERE id=? LIMIT 1");
  $st->bind_param("ii",$admin_id,$id);
  $ok = $st->execute();
  $st->close();
  if ($is_ajax) j(['ok'=>$ok]);
  header('Location: /'); exit;
}

if ($op === 'answer') {
  $resp = trim((string)($_POST['resposta'] ?? ''));
  if (mb_strlen($resp,'UTF-8') < 3) {
    if ($is_ajax) j(['ok'=>false,'error'=>'resposta']);
    header('Location: /'); exit;
  }
  $st = $conn->prepare("UPDATE gerenciamento_mensagens SET status='answered', resposta=?, handled_by=?, handled_at=NOW() WHERE id=? LIMIT 1");
  $st->bind_param("sii",$resp,$admin_id,$id);
  $ok = $st->execute();
  $st->close();
  if ($is_ajax) j(['ok'=>$ok]);
  header('Location: /'); exit;
}

if ($is_ajax) j(['ok'=>false,'error'=>'unknown_op']);
header('Location: /'); exit;
