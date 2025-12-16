<?php
// /ajax/fiscal_rename.php — renomeia fiscal e atualiza referências (JSON)

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Sessão expirada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ✅ Permissão: apenas nível 1 (Fiscal) e 5 (Admin/Gerente)
$user_level = (int)($_SESSION['access_level'] ?? $_SESSION['nivel'] ?? $_SESSION['user_level'] ?? 0);
if (!in_array($user_level, [1, 5], true)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Você não tem permissão para renomear fiscais.'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/../php/conn.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Falha ao conectar no banco.'], JSON_UNESCAPED_UNICODE);
  exit;
}

function coh_norm_spaces(string $s): string {
  $s = trim($s);
  return preg_replace('/\s+/u', ' ', $s);
}

try {
  $id       = (int)($_POST['id'] ?? 0);
  $old_nome = coh_norm_spaces((string)($_POST['old_nome'] ?? ''));
  $new_nome = coh_norm_spaces((string)($_POST['new_nome'] ?? ''));

  $len = function_exists('mb_strlen') ? mb_strlen($new_nome, 'UTF-8') : strlen(utf8_decode($new_nome));
  if ($new_nome === '' || $len < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Informe um nome válido (mín. 3 caracteres).'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Resolve fiscal atual (id e nome) com segurança
  if ($id > 0) {
    $cur = null;
    if ($st = $conn->prepare("SELECT nome FROM emop_fiscais WHERE id = ? LIMIT 1")) {
      $st->bind_param('i', $id);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($r = $rs->fetch_assoc())) $cur = coh_norm_spaces((string)($r['nome'] ?? ''));
      $st->close();
    }
    if (!$cur) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'message' => 'Fiscal não encontrado.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $old_nome = $cur;
  } else {
    if ($old_nome === '') {
      http_response_code(422);
      echo json_encode(['ok' => false, 'message' => 'Informe o fiscal atual para renomear.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if ($st = $conn->prepare("SELECT id, nome FROM emop_fiscais WHERE nome = ? LIMIT 1")) {
      $st->bind_param('s', $old_nome);
      $st->execute();
      $rs = $st->get_result();
      if ($rs && ($r = $rs->fetch_assoc())) {
        $id = (int)($r['id'] ?? 0);
        $old_nome = coh_norm_spaces((string)($r['nome'] ?? $old_nome));
      }
      $st->close();
    }
    if ($id <= 0) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'message' => 'Fiscal atual não encontrado.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  // Se não mudou de fato, não faz nada
  if (mb_strtolower($old_nome, 'UTF-8') === mb_strtolower($new_nome, 'UTF-8')) {
    echo json_encode(['ok' => true, 'id' => $id, 'nome' => $old_nome, 'unchanged' => true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Se new já existir, vai fazer "merge"
  $newId = 0;
  $newAtivo = null;
  if ($st = $conn->prepare("SELECT id, ativo FROM emop_fiscais WHERE nome = ? LIMIT 1")) {
    $st->bind_param('s', $new_nome);
    $st->execute();
    $rs = $st->get_result();
    if ($rs && ($r = $rs->fetch_assoc())) {
      $newId = (int)($r['id'] ?? 0);
      $newAtivo = isset($r['ativo']) ? (int)$r['ativo'] : null;
    }
    $st->close();
  }

  $conn->begin_transaction();

  // Atualiza referências em contratos
  if ($st = $conn->prepare("UPDATE emop_contratos SET Responsavel_Fiscal = ? WHERE Responsavel_Fiscal = ?")) {
    $st->bind_param('ss', $new_nome, $old_nome);
    $st->execute();
    $st->close();
  }
  if ($st = $conn->prepare("UPDATE emop_contratos SET Fiscal_2 = ? WHERE Fiscal_2 = ?")) {
    $st->bind_param('ss', $new_nome, $old_nome);
    $st->execute();
    $st->close();
  }

  // JSON em texto (troca somente o item exato "old")
  $oldJson = '"' . $old_nome . '"';
  $newJson = '"' . $new_nome . '"';
  if ($st = $conn->prepare("
      UPDATE emop_contratos
      SET Fiscais_Extras = REPLACE(Fiscais_Extras, ?, ?)
      WHERE Fiscais_Extras IS NOT NULL
        AND Fiscais_Extras <> ''
        AND Fiscais_Extras LIKE CONCAT('%', ?, '%')
  ")) {
    $st->bind_param('sss', $oldJson, $newJson, $oldJson);
    $st->execute();
    $st->close();
  }

  // Se já existe outro fiscal com o novo nome -> MESCLA
  if ($newId > 0 && $newId !== $id) {

    // reativa o “destino” se estiver inativo
    if ($newAtivo === 0) {
      if ($st = $conn->prepare("UPDATE emop_fiscais SET ativo = 1 WHERE id = ?")) {
        $st->bind_param('i', $newId);
        $st->execute();
        $st->close();
      }
    }

    // inativa o “antigo”
    if ($st = $conn->prepare("UPDATE emop_fiscais SET ativo = 0 WHERE id = ?")) {
      $st->bind_param('i', $id);
      $st->execute();
      $st->close();
    }

    $conn->commit();
    echo json_encode([
      'ok' => true,
      'id' => $newId,
      'nome' => $new_nome,
      'merged' => true,
      'from' => $old_nome
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Renomeia o registro original
  if ($st = $conn->prepare("UPDATE emop_fiscais SET nome = ?, ativo = 1 WHERE id = ?")) {
    $st->bind_param('si', $new_nome, $id);
    $st->execute();
    $st->close();
  }

  $conn->commit();
  echo json_encode([
    'ok' => true,
    'id' => $id,
    'nome' => $new_nome,
    'from' => $old_nome
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if (isset($conn) && $conn instanceof mysqli) {
    try { $conn->rollback(); } catch (Throwable $x) {}
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Falha ao renomear fiscal: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
