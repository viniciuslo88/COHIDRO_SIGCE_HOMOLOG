<?php
// /php/gerenciamento_inbox.php — Inbox do Gerenciamento (nível 5)

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php'; // $conn

function j(bool $ok, array $extra = [], int $http = 200): void {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  http_response_code($http);
  echo json_encode(array_merge(['ok'=>$ok], $extra), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$role    = (int)($_SESSION['role'] ?? $_SESSION['access_level'] ?? $_SESSION['nivel'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$cpf     = (string)($_SESSION['cpf'] ?? '');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$mode   = (string)($_GET['mode'] ?? '');
$embed  = (int)($_GET['embed'] ?? 0);

// ===== COUNT (badge) — apenas nível 5
if ($mode === 'count') {
  if ($role < 5) j(false, ['count'=>0], 200);

  $st = $conn->prepare("SELECT COUNT(*) c FROM gerenciamento_mensagens WHERE status IN ('open','in_progress')");
  $n = 0;
  if ($st) {
    $st->execute();
    $rs = $st->get_result();
    if ($rs) { $row = $rs->fetch_assoc(); $n = (int)($row['c'] ?? 0); }
    $st->close();
  }
  j(true, ['count'=>$n], 200);
}

// ===== MY (para o usuário ver status/resposta)
if ($mode === 'my') {
  if ($user_id <= 0 && $cpf === '') j(true, ['items'=>[]], 200);

  if ($user_id > 0) {
    $st = $conn->prepare("SELECT id, assunto, mensagem, status, resposta, created_at FROM gerenciamento_mensagens WHERE user_id=? ORDER BY id DESC LIMIT 10");
    $st->bind_param("i", $user_id);
  } else {
    $st = $conn->prepare("SELECT id, assunto, mensagem, status, resposta, created_at FROM gerenciamento_mensagens WHERE cpf=? ORDER BY id DESC LIMIT 10");
    $st->bind_param("s", $cpf);
  }

  $items = [];
  if ($st) {
    $st->execute();
    $rs = $st->get_result();
    while ($rs && ($r = $rs->fetch_assoc())) $items[] = $r;
    $st->close();
  }

  j(true, ['items'=>$items], 200);
}

// ===== AÇÕES (POST) — somente nível 5
if ($method === 'POST') {
  if ($role < 5) j(false, ['message'=>'Sem permissão.'], 403);

  $action = (string)($_POST['action'] ?? '');
  $id     = (int)($_POST['id'] ?? 0);
  if ($id <= 0) j(false, ['message'=>'ID inválido.'], 422);

  if ($action === 'set_status') {
    // botão "Em análise"
    $newStatus = (string)($_POST['status'] ?? 'in_progress');
    if (!in_array($newStatus, ['open','in_progress','answered','closed'], true)) $newStatus = 'in_progress';

    $st = $conn->prepare("
      UPDATE gerenciamento_mensagens
      SET status=?,
          handled_by=?,
          handled_at=IFNULL(handled_at, NOW())
      WHERE id=?
    ");
    if (!$st) j(false, ['message'=>'Falha ao preparar UPDATE.', 'mysql'=>$conn->error], 500);

    $st->bind_param("sii", $newStatus, $user_id, $id);
    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    if (!$ok) j(false, ['message'=>'Não foi possível atualizar status.', 'mysql'=>$err], 500);
    j(true, ['message'=>'Status atualizado.'], 200);
  }

  if ($action === 'reply') {
    $resp = trim((string)($_POST['resposta'] ?? ''));
    if (mb_strlen($resp, 'UTF-8') < 3) j(false, ['message'=>'Informe uma resposta (mín. 3 caracteres).'], 422);
    $resp = mb_substr($resp, 0, 8000, 'UTF-8');

    $st = $conn->prepare("
      UPDATE gerenciamento_mensagens
      SET status='answered',
          resposta=?,
          handled_by=?,
          handled_at=NOW()
      WHERE id=?
    ");
    if (!$st) j(false, ['message'=>'Falha ao preparar UPDATE.', 'mysql'=>$conn->error], 500);

    $st->bind_param("sii", $resp, $user_id, $id);
    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    if (!$ok) j(false, ['message'=>'Não foi possível salvar a resposta.', 'mysql'=>$err], 500);
    j(true, ['message'=>'Resposta enviada ao usuário.'], 200);
  }

  j(false, ['message'=>'Ação inválida.'], 422);
}

// ===== HTML EMBED (modal) — gerenciamento
if ($embed === 1) {
  if ($role < 5) { echo '<div class="p-3"><div class="alert alert-danger mb-0">Sem permissão.</div></div>'; exit; }

  $rs = $conn->query("
    SELECT id, created_at, categoria, assunto, mensagem,
           contrato_id, numero_contrato, nome, cpf, diretoria, role,
           status, resposta
    FROM gerenciamento_mensagens
    ORDER BY created_at DESC
    LIMIT 200
  ");
  $rows = [];
  if ($rs) { while ($r = $rs->fetch_assoc()) $rows[] = $r; $rs->free(); }

  ?>
  <div class="p-3">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <div class="fw-semibold"><i class="bi bi-chat-dots me-2"></i>Mensagens do Fale Conosco</div>
      <div class="small text-muted">Mostrando até 200 itens</div>
    </div>

    <?php if (!$rows): ?>
      <div class="alert alert-secondary mb-0">Nenhuma mensagem.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:140px;">Data</th>
              <th style="width:130px;">Status</th>
              <th style="width:120px;">Categoria</th>
              <th>Assunto</th>
              <th style="width:220px;">Usuário</th>
              <th style="width:170px;">Contrato</th>
              <th style="width:240px;">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $id = (int)$r['id'];
              $status = (string)($r['status'] ?? 'open');

              if ($status === 'answered') $badge = '<span class="badge bg-success">Respondida</span>';
              else if ($status === 'in_progress') $badge = '<span class="badge bg-warning text-dark">Em análise</span>';
              else if ($status === 'closed') $badge = '<span class="badge bg-dark">Encerrada</span>';
              else $badge = '<span class="badge bg-secondary">Aberta</span>';
            ?>
              <tr>
                <td class="text-muted small"><?=h($r['created_at'] ?? '')?></td>
                <td><?= $badge ?></td>
                <td><span class="badge bg-light text-dark border"><?=h($r['categoria'] ?? '')?></span></td>

                <td>
                  <div class="fw-semibold"><?=h($r['assunto'] ?? '')?></div>
                  <div class="small text-muted"><?=nl2br(h($r['mensagem'] ?? ''))?></div>

                  <?php if (!empty($r['resposta'])): ?>
                    <div class="mt-2 p-2 bg-light border rounded small">
                      <b>Resposta:</b><br><?=nl2br(h($r['resposta']))?>
                    </div>
                  <?php endif; ?>
                </td>

                <td class="small">
                  <div class="fw-semibold"><?=h($r['nome'] ?? '')?></div>
                  <div class="text-muted"><?=h($r['cpf'] ?? '')?></div>
                  <div class="text-muted"><?=h($r['diretoria'] ?? '')?> • nível <?=h($r['role'] ?? '')?></div>
                </td>

                <td class="small">
                  <div>ID: <?=h($r['contrato_id'] ?? '')?></div>
                  <div>Nº: <?=h($r['numero_contrato'] ?? '')?></div>
                </td>

                <td>
                  <div class="d-flex gap-2 flex-wrap">
                    <form method="post" action="/php/gerenciamento_inbox.php" data-ajax="1" class="m-0">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="status" value="in_progress">
                      <button type="submit" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-hourglass-split me-1"></i> Em análise
                      </button>
                    </form>

                    <button class="btn btn-sm btn-outline-primary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#reply<?= $id ?>">
                      <i class="bi bi-reply me-1"></i> Responder
                    </button>
                  </div>

                  <div class="collapse mt-2" id="reply<?= $id ?>">
                    <form method="post" action="/php/gerenciamento_inbox.php" data-ajax="1">
                      <input type="hidden" name="action" value="reply">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <textarea class="form-control form-control-sm" name="resposta" rows="3" placeholder="Digite a resposta ao usuário..." required></textarea>
                      <div class="d-flex justify-content-end mt-2">
                        <button class="btn btn-sm btn-primary" type="submit">
                          <i class="bi bi-send me-1"></i> Enviar resposta
                        </button>
                      </div>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php
  exit;
}

j(false, ['message'=>'Parâmetros inválidos.'], 400);
