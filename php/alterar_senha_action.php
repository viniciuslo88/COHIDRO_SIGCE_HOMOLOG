<?php
session_start();
require_once __DIR__ . '/conn.php';

if (!isset($_SESSION['cpf'])) {
  header("Location: /login_senha.php");
  exit;
}

$cpf             = $_SESSION['cpf'];
$senha_atual     = trim($_POST['senha_atual'] ?? '');
$nova_senha      = trim($_POST['nova_senha'] ?? '');
$confirmar_senha = trim($_POST['confirmar_senha'] ?? '');

// Configurações
$redirectUrl = '/index.php';
$seconds     = 10;

// Função de renderização visual
function render_result($ok, $titulo, $mensagem, $redirectUrl, $seconds = 10) {
  $icon   = $ok ? '✓' : '⚠';
  $bg     = $ok ? '#e8f5e9' : '#ffebee';
  $bd     = $ok ? '#c8e6c9' : '#ffcdd2';
  $fg     = $ok ? '#1b5e20' : '#b71c1c';
  ?>
  <!DOCTYPE html>
  <html lang="pt-br">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo htmlspecialchars($titulo); ?></title>
    <style>
      body {margin:0;display:grid;place-items:center;min-height:100vh;background:#f6f7fb;font-family:sans-serif;}
      .card {width:min(680px,92vw);background:#fff;border:1px solid #e6e8ee;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);}
      .status {display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #eef0f4;background:<?php echo $bg; ?>;color:<?php echo $fg; ?>;border-color:<?php echo $bd; ?>;}
      .status .icon {font-size:24px;}
      .content {padding:28px 26px;}
      h1 {margin:0 0 10px;font-size:22px;}
      p {margin:0 0 14px;line-height:1.55;color:#444;}
      .countdown {margin-top:14px;font-weight:600;}
      .actions {display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;}
      .btn {border:1px solid #d9dce3;background:#fff;color:#111;padding:10px 14px;border-radius:12px;cursor:pointer;text-decoration:none;font-weight:600;}
      .btn.primary {background:#111827;color:#fff;border-color:#111827;}
      .muted {color:#666;font-size:14px;}
    </style>
  </head>
  <body>
    <div class="card">
      <div class="status">
        <div class="icon"><?php echo $icon; ?></div>
        <div class="title"><strong><?php echo htmlspecialchars($titulo); ?></strong></div>
      </div>
      <div class="content">
        <p><?php echo nl2br(htmlspecialchars($mensagem)); ?></p>
        <p class="countdown">Você será redirecionado em <span id="secs"><?php echo (int)$seconds; ?></span> segundos.</p>
        <div class="actions">
          <a class="btn primary" href="<?php echo htmlspecialchars($redirectUrl); ?>">Ir agora</a>
          <a class="btn" href="javascript:history.back()">Voltar</a>
        </div>
        <p class="muted">Se o redirecionamento não ocorrer, clique em “Ir agora”.</p>
      </div>
    </div>

    <script>
      (function(){
        var secs = <?php echo (int)$seconds; ?>;
        var el = document.getElementById('secs');
        var timer = setInterval(function(){
          secs--;
          if (secs <= 0) {
            clearInterval(timer);
            window.location.href = <?php echo json_encode($redirectUrl); ?>;
          } else {
            el.textContent = secs;
          }
        }, 1000);
      })();
    </script>
  </body>
  </html>
  <?php
  exit;
}

// ======================
// Lógica da alteração
// ======================

if ($nova_senha !== $confirmar_senha) {
  render_result(false, 'As senhas não coincidem', "Digite a nova senha e a confirmação exatamente iguais.", $redirectUrl, $seconds);
}

if ($nova_senha === '' || $senha_atual === '') {
  render_result(false, 'Campos obrigatórios', "Preencha a senha atual e a nova senha.", $redirectUrl, $seconds);
}

// Busca senha atual
$stmt = $conn->prepare("SELECT senha FROM usuarios_cohidro WHERE cpf = ? LIMIT 1");
$stmt->bind_param("s", $cpf);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
  render_result(false, 'Usuário não encontrado', "Não foi possível localizar seu cadastro.", $redirectUrl, $seconds);
}

// Verifica senha atual (texto puro)
if ($row['senha'] !== $senha_atual) {
  render_result(false, 'Senha atual incorreta', "A senha atual informada não confere.", $redirectUrl, $seconds);
}

// Atualiza com a nova senha
$stmt = $conn->prepare("UPDATE usuarios_cohidro SET senha = ? WHERE cpf = ?");
$stmt->bind_param("ss", $nova_senha, $cpf);

if ($stmt->execute()) {
  render_result(true, 'Senha alterada com sucesso', "Sua senha foi atualizada. Utilize a nova senha no próximo acesso.", $redirectUrl, $seconds);
} else {
  render_result(false, 'Erro ao alterar senha', "Ocorreu um problema ao salvar a nova senha. Tente novamente.", $redirectUrl, $seconds);
}

$stmt->close();
$conn->close();
