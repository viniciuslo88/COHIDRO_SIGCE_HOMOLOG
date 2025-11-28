<?php
// primeiro_acesso.php — fluxo em 2 etapas (CPF -> cadastro)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/php/conn.php';

// ---------- Helpers ----------
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function only_digits($s){ return preg_replace('/\D/', '', (string)$s); }
function find_user_by_cpf(mysqli $conn, string $cpf){
    $sql = "
      SELECT id, nome, diretoria, funcao, status, celular, cpf, email, access_level, senha
        FROM usuarios_cohidro_sigce
       WHERE LPAD(REPLACE(REPLACE(REPLACE(IFNULL(cpf,''),'.',''),'-',''),' ',''), 11, '0') = LPAD(?, 11, '0')
       LIMIT 1
    ";
    $st = $conn->prepare($sql);
    if(!$st){ throw new Exception("Falha ao preparar SQL: ".$conn->error); }
    $st->bind_param('s', $cpf);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

// ---------- Estado ----------
$erros = [];
$okmsg = $_SESSION['flash_ok'] ?? null; unset($_SESSION['flash_ok']);

$etapa = $_POST['etapa'] ?? 'cpf'; // 'cpf' | 'salvar'
$user  = null;
$cpf_input = '';

// ETAPA 1: usuário digitou o CPF
if ($etapa === 'cpf' && $_SERVER['REQUEST_METHOD']==='POST') {
    $cpf_input = only_digits($_POST['cpf'] ?? '');
    if ($cpf_input === '') {
        $erros[] = "Informe o CPF.";
    } else {
        try{
            $user = find_user_by_cpf($conn, $cpf_input);
            if (!$user) {
                $erros[] = "CPF não encontrado na base.";
            } else if (!empty($user['senha'])) {
                $erros[] = "Este CPF já possui senha. Faça login ou solicite reset.";
            } else {
                // sucesso: apenas muda a etapa; não processar o salvamento neste POST
                $etapa = 'salvar';
            }
        }catch(Throwable $e){
            $erros[] = "Falha ao buscar CPF: ".$e->getMessage();
        }
    }

// ETAPA 2: salvar cadastro/senha — só processa quando vier cpf_hidden
} elseif ($etapa === 'salvar' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cpf_hidden'])) {
    $cpf_hidden = only_digits($_POST['cpf_hidden'] ?? '');
    try{
        $user = find_user_by_cpf($conn, $cpf_hidden);
        if (!$user) {
            $erros[] = "CPF não encontrado.";
        } else if (!empty($user['senha'])) {
            $erros[] = "Este CPF já possui senha. Faça login ou solicite reset.";
        }

        // Dados editáveis
        $nome    = trim($_POST['nome'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $senha1  = $_POST['senha'] ?? '';
        $senha2  = $_POST['senha2'] ?? '';

        if ($nome === '')   $erros[] = "Informe o nome.";
        if ($email === '')  $erros[] = "Informe o e-mail.";
        if ($senha1 === '' || $senha2 === '') $erros[] = "Informe e confirme a senha.";
        if ($senha1 !== $senha2) $erros[] = "As senhas não conferem.";
        if (strlen($senha1) < 6) $erros[] = "A senha deve ter ao menos 6 caracteres.";

        if (empty($erros)) {
            $hash = password_hash($senha1, PASSWORD_DEFAULT);
            $st = $conn->prepare("UPDATE usuarios_cohidro_sigce SET nome=?, email=?, celular=?, senha=? WHERE id=? LIMIT 1");
            if(!$st){ throw new Exception("Falha ao preparar UPDATE: ".$conn->error); }
            $id = (int)$user['id'];
            $st->bind_param('ssssi', $nome, $email, $celular, $hash, $id);
            $ok = $st->execute();
            $st->close();

            if ($ok){
                $_SESSION['flash_ok'] = "Cadastro concluído. Faça login com sua nova senha.";
                header("Location: /login_senha.php?cpf=" . urlencode($cpf_hidden));
                exit;
            } else {
                $erros[] = "Não foi possível salvar. Tente novamente.";
            }
        }

        // Se houve erros, manter valores digitados
        if (!empty($erros) && $user){
            $user['nome']    = $nome ?: ($user['nome'] ?? '');
            $user['email']   = $email ?: ($user['email'] ?? '');
            $user['celular'] = $celular ?: ($user['celular'] ?? '');
        }

    }catch(Throwable $e){
        $erros[] = "Falha ao salvar: ".$e->getMessage();
    }
}

// Se ainda não temos $user e há $cpf_input válido da etapa 1, tentar preencher
if (!$user && $cpf_input){
    try{ $user = find_user_by_cpf($conn, $cpf_input); } catch(Throwable $e){}
}

// Decide etapa inicial se chegou “frio” (GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $etapa = 'cpf';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIGCE • Primeiro Acesso</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{ background:#f3f5f9; }
  .wrap{ max-width:430px; margin:56px auto; padding:0 16px; }
  .card-login{
    background:#fff;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.08);
    padding:28px 26px;
  }
  .brand-logo{
    height:110px; width:auto; display:block; margin:6px auto 10px auto; object-fit:contain;
  }
  .small-title{ text-align:center; color:#53637a; font-weight:600; margin-bottom:6px; }
  .form-label{ font-weight:600; color:#344256; }
  .form-text{ color:#7b8aa2; }
  .btn-primary{ background:#1976d2; border-color:#1976d2; font-weight:700; }
  .btn-primary:hover{ background:#1565c0; border-color:#1565c0; }
  .readonly{ background:#f2f5fa; }
  /* grid de duas colunas na etapa 2 (md pra cima) */
  @media (min-width: 768px){
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  }
  @media (max-width: 767.98px){
    .grid-2 > * { margin-bottom: 14px; }
  }
</style>
</head>
<body>
<div class="wrap">

  <?php if($okmsg): ?>
    <div class="alert alert-success mb-3"><?= e($okmsg) ?></div>
  <?php endif; ?>

  <?php if(!empty($erros)): ?>
    <div class="alert alert-danger mb-3">
      <strong>Revise os campos:</strong>
      <ul class="mb-0"><?php foreach($erros as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card-login">
    <!-- Logo e subtítulo, igual ao login -->
    <img src="/assets/emop-cohidro.jpg" alt="COHIDRO" class="brand-logo">
    <br>
    <div class="small-title"><?= $etapa==='cpf' ? 'Acessar plataforma' : 'Primeiro Acesso' ?></div>
    <br>

    <?php if ($etapa === 'cpf'): ?>
      <!-- ETAPA 1: informar CPF (mesmo layout do login) -->
      <form method="post" id="formCpf" novalidate>
        <input type="hidden" name="etapa" value="cpf">
        <div class="mb-3">
          <label class="form-label" for="cpf">CPF</label>
          <input
            id="cpf"
            type="text"
            name="cpf"
            class="form-control"
            value="<?= e($cpf_input ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','${1}.${2}.${3}-${4}', str_pad($cpf_input,11,'0',STR_PAD_LEFT)) : '') ?>"
            placeholder="000.000.000-00"
            inputmode="numeric"
            autofocus>
          <div class="form-text">Digite seu CPF</div>
        </div>

        <button class="btn btn-primary w-100 py-2" type="submit">Continuar</button>

        <div class="mt-3 small">
          Já tem senha? <a href="/login_senha.php">Ir para o login.</a>
        </div>
      </form>

    <?php else: ?>
      <!-- ETAPA 2: cadastro (duas colunas, responsivo) -->
      <?php
        $cpf_mask = only_digits($user['cpf'] ?? '');
        $dir      = $user['diretoria'] ?? '';
        $nome     = $user['nome'] ?? '';
        $email    = $user['email'] ?? '';
        $cel      = $user['celular'] ?? '';
      ?>
      <form method="post" id="formSalvar" novalidate>
        <input type="hidden" name="etapa" value="salvar">
        <input type="hidden" name="cpf_hidden" value="<?= e($cpf_mask) ?>">

        <!-- Linha 1: CPF e Diretoria (2 colunas) -->
        <div class="grid-2 mb-2">
          <div>
            <label class="form-label">CPF</label>
            <input type="text" class="form-control readonly"
              value="<?= e(preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','${1}.${2}.${3}-${4}', str_pad($cpf_mask,11,'0',STR_PAD_LEFT))) ?>" readonly>
          </div>
          <div>
            <label class="form-label">Diretoria</label>
            <input type="text" class="form-control readonly" value="<?= e($dir) ?>" readonly>
          </div>
        </div>

        <!-- Linha 2: Nome (coluna inteira) -->
        <div class="mb-2">
          <label class="form-label">Nome completo</label>
          <input type="text" name="nome" class="form-control" value="<?= e($nome) ?>" placeholder="Seu nome completo">
        </div>

        <!-- Linha 3: E-mail | Celular -->
        <div class="grid-2 mb-2">
          <div>
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" value="<?= e($email) ?>" placeholder="seu@email.com">
          </div>
          <div>
            <label class="form-label">Celular</label>
            <input type="text" name="celular" class="form-control" value="<?= e($cel) ?>" placeholder="(XX) 9XXXX-XXXX">
          </div>
        </div>

        <!-- Linha 4: Senha | Confirmar senha -->
        <div class="grid-2 mb-3">
          <div>
            <label class="form-label">Senha</label>
            <input type="password" name="senha" class="form-control" placeholder="Defina sua senha">
          </div>
          <div>
            <label class="form-label">Confirmar senha</label>
            <input type="password" name="senha2" class="form-control" placeholder="Repita a senha">
          </div>
        </div>

        <button class="btn btn-primary w-100 py-2" type="submit">Salvar e Continuar</button>

        <div class="mt-3 small">
          Informar outro CPF? <a href="/primeiro_acesso.php">Voltar ao início.</a>
        </div>
        <div class="form-text mt-2">
          * Senha com mínimo de 6 caracteres. Certifique-se de que a confirmação coincide.
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Máscara de CPF (visual) e normalização no submit da etapa 1 -->
<script>
  function maskCPF(v){
    const n = (v || '').replace(/\D/g,'').slice(0,11);
    const p1 = n.substring(0,3), p2 = n.substring(3,6), p3 = n.substring(6,9), p4 = n.substring(9,11);
    let out = '';
    if(p1){ out = p1; }
    if(p2){ out += '.'+p2; }
    if(p3){ out += '.'+p3; }
    if(p4){ out += '-'+p4; }
    return out;
  }
  const cpfEl = document.getElementById('cpf');
  if (cpfEl){
    cpfEl.addEventListener('input', ()=>{
      const rawPos = cpfEl.selectionStart || 0;
      cpfEl.value = maskCPF(cpfEl.value);
      cpfEl.setSelectionRange(cpfEl.value.length, cpfEl.value.length);
    });
    document.getElementById('formCpf').addEventListener('submit', ()=>{
      cpfEl.value = (cpfEl.value || '').replace(/\D/g,'').slice(0,11);
    });
  }
</script>
</body>
</html>
