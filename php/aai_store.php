<?php
// php/aai_store.php — Persiste um AAI com tela de sucesso + auto-logout (tema claro)

require_once __DIR__ . '/conn.php';

// Coleta segura do POST
$nome                  = $_POST['nome'] ?? '';
$cpf                   = $_POST['cpf'] ?? '';
$diretoria             = $_POST['diretoria'] ?? '';
$data_inicial          = $_POST['data_inicial'] ?? '';
$data_final            = $_POST['data_final'] ?? '';
$atividades_realizadas = $_POST['atividades_realizadas'] ?? '';
$atividades_previstas  = $_POST['atividades_previstas'] ?? '';
$pontos_relevantes     = $_POST['pontos_relevantes'] ?? '';

// data/hora atual no momento do envio
date_default_timezone_set('America/Sao_Paulo');
$data_registro = date('Y-m-d H:i:s');

// (opcional) normalize CPF apenas dígitos
$cpf = preg_replace('/\D+/', '', $cpf);

// Use prepared statements (mais seguro)
$sql = "
INSERT INTO `acompanhamento_atividades`
(`nome`, `cpf`, `diretoria`, `data_inicial`, `data_final`,
 `atividades_realizadas`, `atividades_previstas`, `pontos_relevantes`, `data_registro`)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = $conn->prepare($sql);
if ($stmt) {
  $stmt->bind_param(
    "sssssssss",
    $nome,
    $cpf,
    $diretoria,
    $data_inicial,
    $data_final,
    $atividades_realizadas,
    $atividades_previstas,
    $pontos_relevantes,
    $data_registro
  );
  $ok = $stmt->execute();
  $stmt->close();
} else {
  $ok = false;
}

$conn->close();

// Se inseriu, mostra página Tailwind com contagem regressiva de 5s e redireciona para logout
if ($ok) {
  ?>
  <!DOCTYPE html>
  <html lang="pt-br" class="h-full">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Registro salvo — Acompanhamento de Atividades</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
      html, body { height: 100%; background: #f8fafc; }
      body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial; color: #1e293b; }
    </style>
    <script>
      // Contagem regressiva + redirect
      let seconds = 10;
      function startCountdown() {
        const el = document.getElementById('timer');
        const bar = document.getElementById('bar');
        const total = seconds;
        const tick = () => {
          if (seconds <= 0) {
            window.location.href = "/php/logout.php";
            return;
          }
          el.textContent = seconds;
          bar.style.width = ((total - seconds + 1) / total) * 100 + "%";
          seconds--;
          setTimeout(tick, 1000);
        };
        tick();
      }
      window.addEventListener('DOMContentLoaded', startCountdown);
    </script>
  </head>
  <body class="flex items-center justify-center p-6">
    <div class="w-full max-w-xl">
      <!-- Card -->
      <div class="bg-white shadow-xl rounded-2xl overflow-hidden ring-1 ring-slate-200/70">
        <div class="px-6 sm:px-8 pt-8 pb-4">
          <!-- Cabeçalho -->
          <div class="flex items-center gap-3">
            <div class="shrink-0 inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 text-emerald-700">
              <!-- Ícone de check -->
              <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
            </div>
            <div>
              <h1 class="text-xl sm:text-2xl font-semibold leading-tight">Registro salvo com sucesso</h1>
              <p class="text-sm text-slate-500">Seu Acompanhamento de Atividades foi enviado em <?php echo htmlspecialchars($data_registro); ?>.</p>
            </div>
          </div>

          <!-- Mensagem -->
          <div class="mt-6 space-y-2">
            <p class="text-base">Você será desconectado em <span id="timer" class="font-semibold">10</span> segundos.</p>
            <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
              <div id="bar" class="h-2 bg-emerald-500 transition-[width] duration-1000 ease-linear" style="width:0%"></div>
            </div>
          </div>

          <!-- Ações -->
          <div class="mt-6 flex flex-wrap gap-3">
            <a href="/php/logout.php"
               class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-medium bg-emerald-600 text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
              Sair agora
            </a>
            <a href="/index.php"
               class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-medium bg-slate-100 text-slate-800 hover:bg-slate-200">
              Voltar para o início
            </a>
          </div>
        </div>

        <!-- Rodapé -->
        <div class="px-6 sm:px-8 py-4 bg-slate-50 border-t border-slate-200 text-sm text-slate-500">
          Caso não seja redirecionado automaticamente, clique em <a href="/php/logout.php" class="underline decoration-dotted underline-offset-4 hover:text-slate-700">Sair agora</a>.
        </div>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// Falha → envia para logout
header("Location: /php/logout.php");
exit;
