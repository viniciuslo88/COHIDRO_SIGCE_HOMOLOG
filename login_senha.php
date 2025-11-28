<?php
// login_senha.php — Autenticação por CPF + Senha

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . "/php/conn.php"; // $conn (mysqli)

// ---------- Helpers ----------
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function only_digits($s){ return preg_replace('/\D/', '', (string)$s); }

// CPF pode vir de GET (pós-primeiro_acesso), POST (envio do form) ou sessão (último CPF digitado)
$cpf_raw = $_POST['cpf'] ?? ($_GET['cpf'] ?? ($_SESSION['login_candidate_cpf'] ?? ''));
$cpf     = only_digits($cpf_raw);
$cpf     = substr($cpf, 0, 14); // sanidade

$erros = [];
$flash_ok = $_SESSION['flash_ok'] ?? null; unset($_SESSION['flash_ok']);

// ---------- Autenticação ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = $_POST['senha'] ?? '';

    if ($cpf === '') { $erros[] = "Informe o CPF."; }
    if ($senha === '') { $erros[] = "Informe a senha."; }

    if (empty($erros)) {
        $sql = "
            SELECT id, nome, diretoria, funcao, status, celular, cpf, email, access_level, senha
              FROM usuarios_cohidro_sigce
             WHERE LPAD(REPLACE(REPLACE(REPLACE(IFNULL(cpf,''),'.',''),'-',''),' ',''), 11, '0') = LPAD(?, 11, '0')
             LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $erros[] = "Falha ao preparar consulta: ".$conn->error;
        } else {
            $stmt->bind_param("s", $cpf);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$user) {
                $erros[] = "CPF não encontrado.";
            } else {
                if (empty($user['senha'])) {
                    $_SESSION['flash_ok'] = "Digite seu CPF e cadastre sua senha.";
                    header("Location: /primeiro_acesso.php");
                    exit;
                }

                if (!password_verify($senha, $user['senha'])) {
                    $erros[] = "Senha incorreta.";
                } else {
                    if (password_needs_rehash($user['senha'], PASSWORD_DEFAULT)) {
                        $novo_hash = password_hash($senha, PASSWORD_DEFAULT);
                        $up = $conn->prepare("UPDATE usuarios_cohidro_sigce SET senha=? WHERE id=? LIMIT 1");
                        if ($up) { $up->bind_param("si", $novo_hash, $user['id']); $up->execute(); $up->close(); }
                    }

                    if (empty($erros)) {
                        session_regenerate_id(true);
                        $_SESSION['user_id']   = (int)$user['id'];
                        $_SESSION['cpf']       = only_digits($user['cpf'] ?: $cpf);
                        $_SESSION['nome']      = $user['nome'] ?? '';
                        $_SESSION['diretoria'] = $user['diretoria'] ?? '';
                        $_SESSION['role']      = (int)($user['access_level'] ?? 0);
                        $_SESSION['email']     = $user['email'] ?? '';
                        $_SESSION['is_auth']   = true;
                        $_SESSION['last_login']= date('Y-m-d H:i:s');
                        unset($_SESSION['login_candidate_cpf']);
                        $_SESSION['just_logged_in'] = 1;

                        header("Location: /index.php");
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SIGCE • Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Favicon / app icon -->
  <link rel="icon" href="/assets/pwa/icon-cohidro-splash.png" type="image/png">
  <link rel="shortcut icon" href="/assets/pwa/icon-cohidro-splash.png" type="image/png">

  <!-- (opcional, se quiser PWA já na tela de login também) -->
  <link rel="apple-touch-icon" href="/assets/pwa/icon-ios-180.png">
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#004a9f">
  
  <style>
    body{
      background:#f3f5f9;
    }
    .login-wrap{
      max-width: 430px;
      margin: 56px auto;
      padding: 0 16px;
    }
    .login-card{
      background:#fff;
      border-radius:18px;
      box-shadow: 0 15px 35px rgba(0,0,0,.08);
      padding: 28px 26px;
    }
    .brand-logo{
      height: 110px;
      width: auto;
      display:block;
      margin: 6px auto 10px auto;
      object-fit: contain;
    }
    .login-title{
      text-align:center;
      color:#53637a;
      font-weight:600;
      margin-bottom: 6px;
    }
    .form-label{ font-weight:600; color:#344256; }
    .btn-primary{
      background:#1976d2;
      border-color:#1976d2;
      font-weight:700;
    }
    .btn-primary:hover{ background:#1565c0; border-color:#1565c0; }
    .form-text{ color:#7b8aa2; }
  </style>
</head>
<body>
  <div class="login-wrap">

    <?php if ($flash_ok): ?>
      <div class="alert alert-success mb-3"><?= e($flash_ok) ?></div>
    <?php endif; ?>

    <?php if (!empty($erros)): ?>
      <div class="alert alert-danger mb-3">
        <strong>Não foi possível entrar:</strong>
        <ul class="mb-0"><?php foreach($erros as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <div class="login-card">
      <!-- LOGO dentro do card, como no layout -->
      <img src="/assets/emop-cohidro.jpg" alt="COHIDRO" class="brand-logo">

      <!-- Título pequeno “Acessar plataforma” -->
      <div class="login-title">Acessar plataforma</div>

      <form method="post" novalidate id="loginForm">
        <!-- CPF -->
        <div class="mb-3">
          <label class="form-label" for="cpf">CPF</label>
          <input
            id="cpf"
            type="text"
            name="cpf"
            class="form-control"
            value="<?= e($cpf ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($cpf, 11, '0', STR_PAD_LEFT)) : '') ?>"
            placeholder="000.000.000-00"
            inputmode="numeric"
            autocomplete="username">
          <div class="form-text">Digite seu CPF</div>
        </div>

        <!-- SENHA (inclusa mantendo o layout) -->
        <div class="mb-4">
          <label class="form-label" for="senha">Senha</label>
          <input
            id="senha"
            type="password"
            name="senha"
            class="form-control"
            placeholder="Digite sua senha"
            autocomplete="current-password">
        </div>

        <!-- Botão grande e 100% largura como no print -->
        <button class="btn btn-primary w-100 py-2" type="submit">Continuar</button>

        <!-- Links auxiliares, discretos -->
        <div class="mt-3 small">
          Primeiro acesso? <a href="primeiro_acesso.php">Cadastre-se.</a>
        </div>
        <div class="small">
          Esqueceu a senha? <a href="php/solicitar_reset.php<?= $cpf ? ('?cpf='.urlencode($cpf)) : '' ?>">Solicite um reset.</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Máscara simples de CPF (apenas visual). O back-end já normaliza para dígitos. -->
  <script>
    const cpfInput = document.getElementById('cpf');
    function maskCPF(v){
      const n = (v || '').replace(/\D/g,'').slice(0,11);
      const p1 = n.substring(0,3);
      const p2 = n.substring(3,6);
      const p3 = n.substring(6,9);
      const p4 = n.substring(9,11);
      let out = '';
      if(p1){ out = p1; }
      if(p2){ out += '.'+p2; }
      if(p3){ out += '.'+p3; }
      if(p4){ out += '-'+p4; }
      return out;
    }
    cpfInput.addEventListener('input', (e)=>{
      const start = cpfInput.selectionStart;
      const before = cpfInput.value;
      cpfInput.value = maskCPF(cpfInput.value);
      // manutenção aproximada do cursor
      const diff = cpfInput.value.length - before.length;
      cpfInput.setSelectionRange(start + (diff>0?1:0), start + (diff>0?1:0));
    });

    // Ao enviar, garante que o campo vai como dígitos
    document.getElementById('loginForm').addEventListener('submit', ()=>{
      cpfInput.value = (cpfInput.value || '').replace(/\D/g,'').slice(0,11);
    });
  </script>
</body>
</html>
