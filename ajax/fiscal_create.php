<?php
// /ajax/fiscal_create.php — cadastra fiscal (AJAX) e retorna JSON

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

// --- auth simples (SEM redirect) ---
if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Sessão expirada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
  exit;
}

// --- DB ---
require_once __DIR__ . '/../php/conn.php'; // $conn (mysqli)

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Falha ao conectar no banco.'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $nome = trim((string)($_POST['nome'] ?? ''));
  $nome = preg_replace('/\s+/u', ' ', $nome); // Limpeza de espaços em branco

  $len = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen(utf8_decode($nome));
  if ($nome === '' || $len < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Informe um nome válido (mín. 3 caracteres).'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 1) Verifica se já existe o fiscal
  $idExist = 0;
  $ativoExist = null;

  if ($st = $conn->prepare("SELECT id, ativo FROM emop_fiscais WHERE nome = ? LIMIT 1")) {
    $st->bind_param('s', $nome);
    $st->execute();
    $rs = $st->get_result();
    if ($rs && ($r = $rs->fetch_assoc())) {
      $idExist = (int)($r['id'] ?? 0);
      $ativoExist = isset($r['ativo']) ? (int)$r['ativo'] : null;
    }
    $st->close();
  }

  // Se o fiscal existir, reativa (se estiver inativo)
  if ($idExist > 0) {
    if ($ativoExist === 0) {
      if ($st2 = $conn->prepare("UPDATE emop_fiscais SET ativo = 1 WHERE id = ?")) {
        $st2->bind_param('i', $idExist);
        $st2->execute();
        $st2->close();
      }
    }
    echo json_encode(['ok' => true, 'id' => $idExist, 'nome' => $nome], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 2) Caso o fiscal não exista, insere um novo
  if (!$st3 = $conn->prepare("INSERT INTO emop_fiscais (nome, ativo) VALUES (?, 1)")) {
    throw new RuntimeException('Prepare failed: ' . $conn->error);
  }
  $st3->bind_param('s', $nome);
  $ok = $st3->execute();
  $newId = (int)$conn->insert_id;
  $st3->close();

  if (!$ok || $newId <= 0) {
    throw new RuntimeException('Insert failed: ' . $conn->error);
  }

  echo json_encode(['ok' => true, 'id' => $newId, 'nome' => $nome], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Falha ao cadastrar fiscal: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
