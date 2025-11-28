<?php
// php/solicitar_reset.php — Solicitar reset de senha (abre sem exigir login)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . "/conn.php";

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function only_digits($s){ return preg_replace('/\D/','',(string)$s); }

$cpf_raw = $_GET['cpf'] ?? ($_POST['cpf'] ?? ($_SESSION['login_candidate_cpf'] ?? ''));
$cpf = only_digits($cpf_raw);
$cpf = substr($cpf, 0, 14);

$flash_ok = $_SESSION['flash_ok'] ?? null; unset($_SESSION['flash_ok']);
$flash_err = $_SESSION['flash_err'] ?? null; unset($_SESSION['flash_err']);

$user = null;
if ($cpf) {
  $sql = "SELECT id, nome, email, cpf 
            FROM usuarios_cohidro_sigce
           WHERE LPAD(REPLACE(REPLACE(REPLACE(IFNULL(cpf,''),'.',''),'-',''),' ',''),11,'0') = LPAD(?,11,'0')
           LIMIT 1";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("s", $cpf);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
  }
}

$exists_pending = false;
if ($user) {
  if ($st = $conn->prepare("SELECT COUNT(*) FROM senha_reset_pedidos WHERE user_id=? AND status='pending'")) {
    $uid = (int)$user['id'];
    $st->bind_param("i", $uid);
    $st->execute();
    $st->bind_result($qty);
    $st->fetch();
    $st->close();
    $exists_pending = ($qty > 0);
  }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SIGCE • Solicitar Reset de Senha</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{ background:#f5f7fa; }
    .page-center{ max-width:560px; margin:60px auto; }
    .card-rounded{ border-radius:12px; }
  </style>
</head>
<body>
<div class="container page-center">
  <div class="text-center mb-3">
    <img src="/logo_emop_cohidro.jpg" alt="EMOP COHIDRO" style="height:90px;">
    <h1 class="mt-3" style="font-weight:700;color:#0d47a1;">Solicitar Reset de Senha</h1>
    <p class="text-muted">Informe/Confirme seu CPF para enviar a solicitação ao Administrador</p>
  </div>

  <?php if($flash_ok): ?>
    <div class="alert alert-success"><?= e($flash_ok) ?></div>
  <?php endif; ?>
  <?php if($flash_err): ?>
    <div class="alert alert-danger"><?= e($flash_err) ?></div>
  <?php endif; ?>

  <div class="card card-rounded shadow-sm">
    <div class="card-body">
      <form method="post" action="solicitar_reset_action.php">
        <div class="mb-3">
          <label class="form-label">CPF</label>
          <input type="text" class="form-control" name="cpf" value="<?= e($cpf) ?>" placeholder="Somente números" inputmode="numeric" autocomplete="username" required>
        </div>

        <?php if ($user): ?>
          <div class="mb-3">
            <label class="form-label">Nome</label>
            <input type="text" class="form-control" value="<?= e($user['nome']) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input type="text" class="form-control" value="<?= e($user['email']) ?>" disabled>
          </div>
          <?php if ($exists_pending): ?>
            <div class="alert alert-info">Você já possui uma solicitação <strong>pendente</strong>. Aguarde a análise do administrador.</div>
          <?php endif; ?>
        <?php elseif($cpf): ?>
          <div class="alert alert-warning">CPF não localizado na base.</div>
        <?php endif; ?>

        <div class="d-flex justify-content-between">
          <a href="/login_senha.php<?= $cpf ? ('?cpf='.urlencode($cpf)) : '' ?>" class="btn btn-outline-secondary">← Voltar ao login</a>
          <button class="btn btn-primary" type="submit" <?= (!$user || $exists_pending) ? 'disabled' : '' ?>>Solicitar Reset</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
