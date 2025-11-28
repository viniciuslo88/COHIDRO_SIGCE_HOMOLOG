<?php
/**
 * cohidro_insert.php
 * - Recebe POST do formulário estilo EMOPS
 * - Detecta automaticamente a tabela e as colunas existentes
 * - Monta o INSERT apenas com as colunas que existem, evitando "Unknown column"
 * - Confirma o envio e redireciona para o login
 */
session_start();
header('Content-Type: text/html; charset=UTF-8');

// 1) Conexão
$connected = false;
if (file_exists(__DIR__ . '/conexao_cohidro.php')) {
    require_once __DIR__ . '/conexao_cohidro.php'; // deve definir $conn
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $connected = true;
    }
}
if (!$connected) {
    // fallback (ajuste se necessário)
    $servername = "localhost";
    $username   = "cortex360";
    $password   = "Cortex360Vini";
    $dbname     = "cortex360";
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Falha na conexão: " . $conn->connect_error);
    }
}

// 2) Sanitização básica
function val($k, $default='') { return isset($_POST[$k]) ? trim($_POST[$k]) : $default; }
$payload = [
    'cpf'                    => preg_replace('/\D+/', '', val('cpf')),
    'nome'                   => val('nome'),
    'diretoria'              => val('diretoria'),
    'data_inicial'           => val('data_inicial'),
    'data_final'             => val('data_final'),
    'atividades_realizadas'  => val('atividades_realizadas'),
    'atividades_andamento'   => val('atividades_andamento'),
    'atividades_previstas'   => val('atividades_previstas'),
    'pontos_relevantes'      => val('pontos_relevantes'),
    'pontos_criticos'        => val('pontos_criticos'),
    'local'                  => val('local'),
    'data_relatorio'         => val('data_relatorio'),
];

// 3) Tabelas candidatas (primeira existente será usada)
$candidate_tables = ['acompanhamento_atividades','emop_registros','relatorios_atividades','atividades'];
$table = null;
foreach ($candidate_tables as $t) {
    $q = $conn->query("SHOW TABLES LIKE '{$conn->real_escape_string($t)}'");
    if ($q && $q->num_rows > 0) { $table = $t; break; }
}
if (!$table) {
    // se nenhuma das candidatas existir, tenta descobrir por colunas parecidas
    $guess = $conn->query("SHOW TABLES");
    if ($guess) {
        while ($r = $guess->fetch_array()) {
            $t = $r[0];
            $cols = $conn->query("SHOW COLUMNS FROM `{$t}`");
            if ($cols) {
                $names = [];
                while ($c = $cols->fetch_assoc()) $names[] = strtolower($c['Field']);
                // heurística simples: presença de 'cpf' e alguma coluna de texto
                if (in_array('cpf',$names)) { $table = $t; break; }
            }
        }
    }
}
if (!$table) {
    http_response_code(500);
    echo "<h2>Não foi possível detectar a tabela de destino.</h2><p>Crie uma das tabelas esperadas (ex.: <code>acompanhamento_atividades</code>) ou ajuste a lista em <code>cohidro_insert.php</code>.</p>";
    exit;
}

// 4) Mapeamento flexível de campos -> colunas possíveis
$map = [
    'cpf'                   => ['cpf'],
    'nome'                  => ['nome','nome_colaborador','colaborador_nome'],
    'diretoria'             => ['diretoria','diretoria_setor','setor','departamento'],
    'data_inicial'          => ['data_inicial','data_inicio','dt_inicial'],
    'data_final'            => ['data_final','dt_final','data_fim'],
    'atividades_realizadas' => ['atividades_realizadas','atividades','atividades_real'],
    'atividades_andamento'  => ['atividades_andamento','em_andamento'],
    'atividades_previstas'  => ['atividades_previstas','planejadas','previstas'],
    'pontos_relevantes'     => ['pontos_relevantes','destaques'],
    'pontos_criticos'       => ['pontos_criticos','riscos','impedimentos'],
    'local'                 => ['local','cidade'],
    'data_relatorio'        => ['data_relatorio','data_envio','dt_relatorio'],
    // timestamps comuns:
    'data_registro_now'     => ['data_registro','created_at','criado_em'],
];

// 5) Descobrir colunas existentes
$existing = [];
$colsRes = $conn->query("SHOW COLUMNS FROM `{$table}`");
if ($colsRes) {
    while ($c = $colsRes->fetch_assoc()) $existing[] = strtolower($c['Field']);
}

// 6) Construir lista de colunas e valores de forma dinâmica
$columns = [];
$values  = [];
$types   = '';   // string para bind_param
$params  = [];   // valores por referência

foreach ($map as $key => $cands) {
    if ($key === 'data_registro_now') continue; // trataremos depois
    $value = isset($payload[$key]) ? $payload[$key] : null;
    if ($value === null || $value === '') continue;

    foreach ($cands as $col) {
        if (in_array(strtolower($col), $existing)) {
            $columns[] = $col;
            $values[]  = '?';
            $types    .= 's'; // tudo como string (MySQL faz cast)
            $params[]  = $value;
            break;
        }
    }
}

// 6.1) Coluna de timestamp automática se existir
$autoNowCol = null;
foreach ($map['data_registro_now'] as $col) {
    if (in_array(strtolower($col), $existing)) {
        $autoNowCol = $col;
        break;
    }
}
if (empty($columns)) {
    http_response_code(400);
    echo "<h2>Nenhum campo de formulário corresponde às colunas da tabela <code>{$table}</code>.</h2>";
    exit;
}

// 7) Montar SQL
$sql  = "INSERT INTO `{$table}` (" . implode(',', $columns);
if ($autoNowCol) $sql .= ", {$autoNowCol}";
$sql .= ") VALUES (" . implode(',', $values);
if ($autoNowCol) $sql .= ", NOW()";
$sql .= ")";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "<h2>Erro ao preparar INSERT.</h2><pre>{$conn->error}</pre>";
    exit;
}

// 8) bind dinâmico
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$ok  = $stmt->execute();
$err = $stmt->error;
$stmt->close();
$conn->close();

// 9) Feedback e redirecionamento
$sucesso = $ok && empty($err);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo $sucesso ? 'Enviado com sucesso' : 'Falha ao enviar'; ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <meta http-equiv="refresh" content="5;url=login.php">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white max-w-lg w-full p-6 rounded-2xl shadow-xl text-center">
    <div class="<?php echo $sucesso ? 'text-green-600' : 'text-red-600'; ?> text-2xl font-semibold mb-2">
      <?php echo $sucesso ? '✅ Envio realizado!' : '❌ Não foi possível salvar'; ?>
    </div>
    <p class="text-gray-700 mb-1">
      <?php if ($sucesso): ?>
        Obrigado por enviar seu relatório!
      <?php else: ?>
        Ocorreu um erro ao salvar seus dados.
      <?php endif; ?>
    </p>

    <?php if (!$sucesso): ?>
      <div class="mt-3 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg text-left">
        <strong>Erro MySQL:</strong><br>
        <?php echo htmlspecialchars($err ?: 'Erro desconhecido.'); ?>
      </div>
      <p class="text-xs text-gray-500 mt-2">
        Verifique se as colunas existem na tabela e ajuste o mapa em <code>cohidro_insert.php</code>.
      </p>
    <?php endif; ?>

    <p class="text-sm text-gray-600 mt-4">
      Você será redirecionado para o login em <span id="c">5</span> segundos…
    </p>
    <a href="login.php" class="inline-block mt-3 text-blue-700 hover:underline text-sm">Ir agora</a>
  </div>
  <script>
    let r = 5;
    const el = document.getElementById('c');
    const i = setInterval(() => { r--; el.textContent = r; if (r <= 0) clearInterval(i); }, 1000);
  </script>
</body>
</html>
