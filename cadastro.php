<?php
require_once __DIR__ . "/php/conn.php";

// Normaliza CPF vindo por GET (?cpf=...)
$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');

// Inicializa variáveis
$id_colaborador = $nome = $diretoria = $funcao = $data_inicio = $celular = $email = '';
$status = $access_level = '';

if ($cpf !== '') {
    $stmt = $conn->prepare("SELECT id, nome, diretoria, funcao, status, celular, cpf, email, access_level, senha 
                            FROM usuarios_cohidro_sigce
                            WHERE cpf = ? LIMIT 1");
    $stmt->bind_param("s", $cpf);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $id_colaborador = $row['id'];
        $nome           = $row['nome'];
        $diretoria      = $row['diretoria'];
        $funcao         = $row['funcao'];
        $status         = $row['status'];
        $celular        = $row['celular'];
        $cpf            = $row['cpf'];
        $email          = $row['email'];
        $access_level   = $row['access_level'];
    }
    $stmt->close();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Cadastro de Colaborador</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f5f6fa; }
    .form-card {
      max-width: 700px;
      margin: 3% auto;
      padding: 2rem;
      border-radius: 1rem;
      background: #fff;
      box-shadow: 0 0 20px rgba(0,0,0,.1);
    }
    input[readonly] {
      background-color: #e9ecef; /* cinza claro */
      cursor: not-allowed;
    }
  </style>
</head>
<body>
  <div class="form-card">
    <h4 class="mb-4 text-center">Cadastro de Colaborador</h4>
    
    <form method="post" action="/php/insere_cadastro.php">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">ID</label>
          <input type="text" class="form-control" name="id" 
                 value="<?= htmlspecialchars($id_colaborador) ?>" 
                 <?= $id_colaborador ? 'readonly' : '' ?> required>
        </div>
        <div class="col-md-9">
          <label class="form-label">Nome</label>
          <input type="text" class="form-control" name="nome" 
                 value="<?= htmlspecialchars($nome) ?>" 
                 <?= $nome ? 'readonly' : '' ?> required>
        </div>

        <div class="col-md-6">
          <label class="form-label">Diretoria</label>
          <input type="text" class="form-control" name="diretoria" 
                 value="<?= htmlspecialchars($diretoria) ?>" 
                 <?= $diretoria ? 'readonly' : '' ?>>
        </div>
        <div class="col-md-6">
          <label class="form-label">Função</label>
          <input type="text" class="form-control" name="funcao" 
                 value="<?= htmlspecialchars($funcao) ?>" 
                 <?= $funcao ? 'readonly' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">Celular</label>
          <input type="text" class="form-control" name="celular" 
                 value="<?= htmlspecialchars($celular) ?>" 
                 <?= $celular ? 'readonly' : '' ?>>
        </div>

        <div class="col-md-6">
          <label class="form-label">CPF</label>
          <input type="text" class="form-control" name="cpf" 
                 value="<?= htmlspecialchars($cpf) ?>" 
                 <?= $cpf ? 'readonly' : '' ?> required>
        </div>
        <div class="col-md-6">
          <label class="form-label">E-mail</label>
          <input type="email" class="form-control" name="email" 
                 value="<?= htmlspecialchars($email) ?>" 
                 <?= $email ? 'readonly' : '' ?> required>
        </div>

        <!-- Senha e confirmar senha SEM readonly -->
        <div class="col-12">
          <label class="form-label">Senha</label>
          <input type="password" class="form-control" name="senha" id="senha" required>
        </div>
        <div class="col-12">
          <label class="form-label">Confirmar Senha</label>
          <input type="password" class="form-control" name="confirmar_senha" id="confirmar_senha" required>
        </div>
      </div>
      
      <div class="d-grid mt-4">
        <button type="submit" class="btn btn-primary btn-lg">Salvar</button>
      </div>
    </form>
  </div>

  <script>
    // Validação simples de senha e confirmar senha
    document.querySelector("form").addEventListener("submit", function(e) {
      const senha = document.getElementById("senha").value;
      const confirmar = document.getElementById("confirmar_senha").value;
      if (senha !== confirmar) {
        e.preventDefault();
        alert("As senhas não coincidem!");
      }
    });
  </script>
</body>
</html>
