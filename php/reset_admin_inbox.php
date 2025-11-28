<?php
// php/reset_admin_inbox.php — Inbox de pedidos de reset (página completa ou embed via fetch) — versão AJAX-friendly
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . "/conn.php";

$role = (int)($_SESSION['role'] ?? 0);
if ($role < 5){
  http_response_code(403); echo "Apenas Administradores (nível 5) podem acessar."; exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rows = [];
$sql = "SELECT p.id, p.user_id, p.cpf, p.nome, p.email, p.status, p.created_at
          FROM senha_reset_pedidos p
         WHERE p.status='pending'
         ORDER BY p.created_at ASC";
if ($rs = $conn->query($sql)) {
  while($r = $rs->fetch_assoc()) $rows[] = $r;
}

$is_embed = isset($_SERVER['HTTP_X_REQUESTED_WITH']);
if (!$is_embed):
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SIGCE • Aprovar Resets de Senha</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="mb-3">Solicitações de Reset de Senha</h1>
  <p class="text-muted">Apenas pendentes aparecem aqui. Ao aprovar, a senha do usuário é anulada e ele fará novo cadastro em <code>primeiro_acesso.php</code>.</p>
<?php endif; ?>

  <?php if(empty($rows)): ?>
    <div class="alert alert-info m-3">Sem solicitações pendentes.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th><th>CPF</th><th>Nome</th><th>E-mail</th><th>Solicitado em</th><th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($r['cpf']) ?></td>
            <td><?= e($r['nome']) ?></td>
            <td><?= e($r['email']) ?></td>
            <td><?= e($r['created_at']) ?></td>
            <td class="text-end">
              <form method="post" action="/php/reset_admin_action.php" class="d-inline" data-ajax="1">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="op" value="approve">
                <button class="btn btn-success btn-sm" type="submit">Aprovar</button>
              </form>
              <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#deny-<?= (int)$r['id'] ?>">Negar</button>
            </td>
          </tr>
          <tr class="collapse" id="deny-<?= (int)$r['id'] ?>">
            <td colspan="6">
              <form method="post" action="/php/reset_admin_action.php" class="row g-2" data-ajax="1">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="op" value="deny">
                <div class="col-md-10">
                  <input type="text" name="motivo" class="form-control form-control-sm" placeholder="Motivo da negativa (opcional)">
                </div>
                <div class="col-md-2 text-end">
                  <button class="btn btn-danger btn-sm" type="submit">Confirmar</button>
                </div>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php if (!$is_embed): ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php endif; ?>
