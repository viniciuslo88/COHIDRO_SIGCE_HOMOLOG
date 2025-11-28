<?php
session_start();
if (!isset($_SESSION['cpf'])) {
  header("Location: /login_senha.php");
  exit;
}
$cpf  = $_SESSION['cpf'];
$nome = $_SESSION['nome'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Alterar Senha</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="d-flex align-items-center justify-content-center min-vh-100">
  <div class="col-md-5">
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white text-center">
        Alterar Senha
      </div>
      <div class="card-body">
        <form method="POST" action="/php/alterar_senha_action.php">
          <div class="mb-3">
            <label class="form-label">Senha Atual</label>
            <input type="password" class="form-control" name="senha_atual" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nova Senha</label>
            <input type="password" class="form-control" name="nova_senha" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirmar Nova Senha</label>
            <input type="password" class="form-control" name="confirmar_senha" required>
          </div>

          <!-- Botões -->
          <div class="d-flex gap-2">
            <button type="button"
                    class="btn btn-outline-secondary w-50"
                    onclick="history.back();">
              Voltar
            </button>
            <button type="submit" class="btn btn-success w-50">
              Salvar Alteração
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

</body>
</html>
