<?php
// php/lgpd_accept.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conn.php';

function onlyDigits($s){ return preg_replace('/\D/', '', $s ?? ''); }
function jexit($ok, $msg='', $extra=[]){
  echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$agree        = $_POST['agree'] ?? '';
$cpf_post     = onlyDigits($_POST['cpf'] ?? '');
$nome_post    = trim($_POST['nome'] ?? '');
$diretoria    = trim($_POST['diretoria'] ?? '');
$version_hash = preg_replace('/[^a-f0-9]/i','', $_POST['version_hash'] ?? '');
$termo_text   = $_POST['termo_text'] ?? '';

if ($agree !== '1')            jexit(false, 'É necessário marcar a concordância.');
if (empty($cpf_post))          jexit(false, 'CPF ausente.');
if (empty($version_hash) || strlen($version_hash)!==40) jexit(false, 'Versão inválida do termo.');

$cpf_session = onlyDigits($_SESSION['cpf'] ?? '');
if ($cpf_session && $cpf_session !== $cpf_post) {
  jexit(false, 'Sessão não corresponde ao CPF informado.');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$sql = "INSERT INTO lgpd_aceites_sigce (cpf, nome, diretoria, ip, user_agent, version_hash, termo_text)
        VALUES (?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssss", $cpf_post, $nome_post, $diretoria, $ip, $ua, $version_hash, $termo_text);
$ok = $stmt->execute();
if (!$ok) {
  if ($conn->errno == 1062) { jexit(true, 'Aceite já registrado anteriormente.'); }
  jexit(false, 'Falha ao registrar aceite: '.$conn->error);
}
jexit(true, 'Aceite registrado com sucesso.');
