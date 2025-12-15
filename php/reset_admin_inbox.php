<?php
// php/reset_admin_inbox.php — Inbox de pedidos de reset (página completa ou embed via fetch) — AJAX-friendly

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

// Auth/guards (padrão do projeto)
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php'; // $conn (mysqli)

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Detecta embed (modal)
$is_embed =
  !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
  !empty($_SERVER['HTTP_X_FRAGMENT']) ||
  ((string)($_GET['embed'] ?? '') === '1');

// Role (fallbacks do seu projeto)
$role = (int)($_SESSION['role'] ?? $_SESSION['access_level'] ?? $_SESSION['nivel'] ?? $_SESSION['user_level'] ?? 0);

// Permissão
if ($role < 5){
  if ($is_embed) {
    echo '<div class="p-3"><div class="alert alert-danger mb-0">Apenas Administradores (nível 5) podem acessar.</div></div>';
    exit;
  }
  http_response_code(403);
  echo "Apenas Administradores (nível 5) podem acessar.";
  exit;
}

// Base URL correta do diretório atual (funciona em subpasta)
$phpSelf = (string)($_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '/php/reset_admin_inbox.php');
$baseDir = rtrim(str_replace('\\', '/', dirname($phpSelf)), '/'); // ex.: /sigce/php
$action_reset = $baseDir . '/reset_admin_action.php';

// Busca pendências
$rows = [];
$sql = "
  SELECT p.id, p.user_id, p.cpf, p.nome, p.email, p.status, p.created_at
  FROM senha_reset_pedidos p
  WHERE p.status='pending'
  ORDER BY p.created_at ASC
";
if ($rs = $conn->query($sql)) {
  while($r = $rs->fetch_assoc()) $rows[] = $r;
  $rs->free();
}

if (!$is_embed):
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SIGCE • Aprovar Resets de Senha</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="mb-3">Solicitações de Reset de Senha</h1>
  <p class="text-muted">
    Apenas pendentes aparecem aqui. Ao aprovar, a senha do usuário é anulada e ele fará novo cadastro em
    <code>primeiro_acesso.php</code>.
  </p>
<?php
else:
  echo '<div class="p-3">';
endif;
?>

<?php if(empty($rows)): ?>
  <div class="alert alert-info mb-0">Sem solicitações pendentes.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:60px;">#</th>
          <th style="width:140px;">CPF</th>
          <th>Nome</th>
          <th style="width:220px;">E-mail</th>
          <th style="width:170px;">Solicitado em</th>
          <th class="text-end" style="width:260px;">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): $pid = (int)$r['id']; ?>
        <tr>
          <td><?= $pid ?></td>
          <td><?= e($r['cpf']) ?></td>
          <td><?= e($r['nome']) ?></td>
          <td><?= e($r['email']) ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td class="text-end">
            <form method="post" action="<?= e($action_reset) ?>" class="d-inline" data-ajax="1">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <input type="hidden" name="op" value="approve">
              <button class="btn btn-success btn-sm" type="submit">
                <i class="bi bi-check2 me-1"></i>Aprovar
              </button>
            </form>

            <button class="btn btn-outline-danger btn-sm" type="button"
                    data-bs-toggle="collapse" data-bs-target="#deny-<?= $pid ?>">
              <i class="bi bi-x-lg me-1"></i>Negar
            </button>
          </td>
        </tr>

        <tr class="collapse" id="deny-<?= $pid ?>">
          <td colspan="6">
            <form method="post" action="<?= e($action_reset) ?>" class="row g-2" data-ajax="1">
              <input type="hidden" name="ajax" value="1">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <input type="hidden" name="op" value="deny">

              <div class="col-md-10">
                <input type="text" name="motivo" class="form-control form-control-sm"
                       placeholder="Motivo da negativa (opcional)">
              </div>
              <div class="col-md-2 text-end">
                <button class="btn btn-danger btn-sm" type="submit">
                  <i class="bi bi-send me-1"></i>Confirmar
                </button>
              </div>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php
if (!$is_embed):
?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
else:
  echo '</div>';
endif;
?>
