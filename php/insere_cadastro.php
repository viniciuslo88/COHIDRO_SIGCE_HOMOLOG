<?php
// php/aai_store.php — Persiste um AAI

require_once __DIR__ . '/conn.php';

$cpf = $_POST['cpf'] ?? '';
$senha = $_POST['senha'] ?? '';

// data/hora atual no momento do envio
$data_registro = date('Y-m-d H:i:s');

// ==== DEBUG ====
// echo "NOME: $nome<br>";
// echo "CPF: $cpf<br>";
// echo "DIRETORIA: $diretoria<br>";
// echo "DATA INICIAL: $data_inicial<br>";
// echo "DATA FINAL: $data_final<br>";
// echo "ATIVIDADES REALIZADAS: $atividades_realizadas<br>";
// echo "ATIVIDADES PREVISTAS: $atividades_previstas<br>";
// echo "PONTOS RELEVANTES: $pontos_relevantes<br>";

// Query de inserção
$sql = "UPDATE `usuarios_cohidro_sigce` SET `senha`=? WHERE `cpf`=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $senha, $cpf);

$sucesso = $stmt->execute();

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Redirecionamento</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="bg-white shadow-lg rounded-lg p-8 text-center max-w-md">
    <?php if ($sucesso): ?>
      <h2 class="text-2xl font-bold text-emerald-600 mb-4">✅ Perfil cadastrado com sucesso!</h2>
    <?php else: ?>
      <h2 class="text-2xl font-bold text-red-600 mb-4">❌ Erro ao cadastrar perfil!</h2>
    <?php endif; ?>

    <p class="text-base">
      Você será redirecionado em <span id="timer" class="font-semibold">5</span> segundos.
    </p>
    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden mt-4">
      <div id="bar" class="h-2 bg-emerald-500 transition-[width] duration-1000 ease-linear" style="width:0%"></div>
    </div>
  </div>

  <script>
    let segundos = 5;
    const timerEl = document.getElementById("timer");
    const barEl   = document.getElementById("bar");

    const interval = setInterval(() => {
      segundos--;
      timerEl.textContent = segundos;
      barEl.style.width = ((5 - segundos) / 5) * 100 + "%";

      if (segundos <= 0) {
        clearInterval(interval);
        window.location.href = "/login_senha.php?cpf=<?= urlencode($cpf) ?>";
      }
    }, 1000);
  </script>
</body>
</html>