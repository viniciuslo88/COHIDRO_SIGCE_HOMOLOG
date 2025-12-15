<?php
// /php/fale_conosco.php — Endpoint do "Fale Conosco" (usuário -> Inbox do Gerenciamento)
// Responde SEMPRE JSON (sem redirects/HTML), para não quebrar o fetch.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/conn.php'; // $conn (mysqli)

function j(bool $ok, array $extra = [], int $http = 200): void {
  // limpa qualquer saída acidental (warnings, espaços, etc.)
  while (ob_get_level() > 0) { @ob_end_clean(); }
  http_response_code($http);
  echo json_encode(
    array_merge(['ok' => $ok], $extra),
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
  );
  exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  j(false, ['error' => 'method_not_allowed', 'message' => 'Use POST.'], 405);
}

// ===== Sessão / identidade (SEM redirects) =====
$user_id = (int)($_SESSION['user_id'] ?? 0);
$cpf     = (string)($_SESSION['cpf'] ?? '');
$nome    = (string)($_SESSION['nome'] ?? $_SESSION['user_name'] ?? $_SESSION['usuario_nome'] ?? '');
$role    = (int)($_SESSION['role'] ?? $_SESSION['access_level'] ?? $_SESSION['nivel'] ?? $_SESSION['user_level'] ?? 0);
$diretoria = (string)($_SESSION['diretoria'] ?? $_SESSION['Diretoria'] ?? '');

if ($user_id <= 0 && $cpf === '') {
  j(false, ['error' => 'not_authenticated', 'message' => 'Sessão expirada. Faça login novamente.'], 403);
}

// ===== Entrada =====
$categoria = strtoupper(trim((string)($_POST['categoria'] ?? 'DUVIDA')));
$assunto   = trim((string)($_POST['assunto'] ?? ''));
$mensagem  = trim((string)($_POST['mensagem'] ?? ''));

$contrato_id_raw = trim((string)($_POST['contrato_id'] ?? ''));
$contrato_id_num = preg_replace('/\D/', '', $contrato_id_raw);
$contrato_id     = (strlen($contrato_id_num) > 0) ? (int)$contrato_id_num : null;

$numero_contrato = trim((string)($_POST['numero_contrato'] ?? ''));
$pagina          = trim((string)($_POST['pagina'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));

$validCats = ['DUVIDA','SOLICITACAO','ERRO','SUGESTAO','ACESSO','OUTROS'];
if (!in_array($categoria, $validCats, true)) $categoria = 'DUVIDA';

// ===== Validações =====
if (mb_strlen($assunto, 'UTF-8') < 3) {
  j(false, ['error'=>'assunto', 'message'=>'Informe um assunto (mín. 3 caracteres).'], 422);
}
if (mb_strlen($mensagem, 'UTF-8') < 10) {
  j(false, ['error'=>'mensagem', 'message'=>'Descreva melhor a mensagem (mín. 10 caracteres).'], 422);
}

$assunto  = mb_substr($assunto, 0, 200, 'UTF-8');
$mensagem = mb_substr($mensagem, 0, 8000, 'UTF-8');
$numero_contrato = mb_substr($numero_contrato, 0, 80, 'UTF-8');
$pagina = mb_substr($pagina, 0, 255, 'UTF-8');

// ===== Anti-spam 30s (por user_id) =====
if ($user_id > 0) {
  if ($st = $conn->prepare("SELECT created_at FROM gerenciamento_mensagens WHERE user_id=? ORDER BY created_at DESC LIMIT 1")) {
    $st->bind_param("i", $user_id);
    $st->execute();
    $rs = $st->get_result();
    $last = $rs ? $rs->fetch_assoc() : null;
    $st->close();

    if ($last && !empty($last['created_at'])) {
      $dt = strtotime($last['created_at']);
      if ($dt && (time() - $dt) < 30) {
        j(false, ['error'=>'rate_limit', 'message'=>'Aguarde alguns segundos antes de enviar outra mensagem.'], 429);
      }
    }
  }
}

// ===== Metadados =====
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

// ===== Insert =====
$sql = "
  INSERT INTO gerenciamento_mensagens
    (user_id, cpf, nome, role, diretoria, categoria, assunto, mensagem, contrato_id, numero_contrato, pagina, user_agent, created_ip)
  VALUES
    (?,?,?,?,?,?,?,?,?,?,?,?,?)
";

$ins = $conn->prepare($sql);
if (!$ins) {
  j(false, [
    'error' => 'prepare_failed',
    'message' => 'Falha ao preparar INSERT. Verifique se a tabela gerenciamento_mensagens existe.',
    'mysql' => $conn->error
  ], 500);
}

// 13 params:
$types = "ississssissss";

// Se vier vazio, grava NULL no banco (melhor que 0)
$cid = $contrato_id; // int|null

$ins->bind_param(
  $types,
  $user_id,
  $cpf,
  $nome,
  $role,
  $diretoria,
  $categoria,
  $assunto,
  $mensagem,
  $cid,
  $numero_contrato,
  $pagina,
  $ua,
  $ip
);

$ok = $ins->execute();
$err = $ins->error;
$new_id = (int)$conn->insert_id;
$ins->close();

if (!$ok) {
  j(false, ['error'=>'insert_failed', 'message'=>'Não foi possível registrar sua mensagem.', 'mysql'=>$err], 500);
}

j(true, ['id'=>$new_id, 'status'=>'open', 'message'=>'Mensagem enviada ao Gerenciamento.'], 200);
