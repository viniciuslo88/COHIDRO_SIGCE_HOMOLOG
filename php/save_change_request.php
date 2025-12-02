<?php
// php/save_change_request.php
session_start();
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/require_auth.php';

// Verifica se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /index.php");
    exit;
}

$user_id   = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['user_name'] ?? 'Usuário';
$diretoria = $_SESSION['diretoria'] ?? '';

// Pega o ID do contrato
$contrato_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($contrato_id <= 0) {
    die("ID do contrato inválido.");
}

// 1. Busca os dados atuais do contrato para comparação
$stmt = $conn->prepare("SELECT * FROM emop_contratos WHERE id = ?");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$res = $stmt->get_result();
$atual = $res->fetch_assoc();
$stmt->close();

if (!$atual) {
    die("Contrato não encontrado.");
}

// 2. Lista de campos que podem ser auditados (ajuste conforme suas colunas reais no form_contratos.php)
$campos_auditaveis = [
    'Objeto_Da_Obra', 'Processo_SEI', 'Empresa', 'Valor_Do_Contrato', 
    'Data_Inicio', 'Data_Fim_Prevista', 'Status', 'Percentual_Executado',
    'Valor_Liquidado_Acumulado', 'Data_Da_Medicao_Atual', 'Valor_Liquidado_Na_Medicao_RS',
    'Observacoes' // e outros campos do seu formulário
];

$mudancas = [];

// 3. Compara campos simples
foreach ($campos_auditaveis as $campo) {
    if (isset($_POST[$campo])) {
        $novo_valor = trim($_POST[$campo]);
        $valor_atual = isset($atual[$campo]) ? trim($atual[$campo]) : '';

        // Normalização básica para evitar falsos positivos (ex: 100.00 vs 100)
        if ($novo_valor != $valor_atual) {
            $mudancas[$campo] = $novo_valor;
        }
    }
}

// 4. Processa Novas Medições (vindas do JS dinâmico)
$novas_medicoes = [];
if (isset($_POST['new_med']) && is_array($_POST['new_med'])) {
    foreach ($_POST['new_med'] as $med) {
        // Valida se tem dados mínimos
        if (!empty($med['data']) && !empty($med['valor_liq'])) {
            $novas_medicoes[] = [
                'data' => $med['data'],
                'valor_rs' => $med['valor_liq'],
                'acumulado_rs' => $med['liq_acum'] ?? '',
                'percentual' => $med['perc'] ?? '',
                'obs' => $med['obs'] ?? ''
            ];
        }
    }
}

// 5. Verifica se houve alguma alteração real
if (empty($mudancas) && empty($novas_medicoes)) {
    // Se não mudou nada, redireciona de volta com aviso (usando flash message se tiver, ou get param)
    header("Location: /form_contratos.php?id=$contrato_id&msg=nada_alterado");
    exit;
}

// 6. Monta o Payload JSON
$payload = [
    'campos' => $mudancas, // Campos editados
    'novas_medicoes' => $novas_medicoes, // Novas linhas de medição
    'timestamp' => date('Y-m-d H:i:s'),
    'solicitante' => $user_name
];

$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// 7. Insere no coordenador_inbox
$sqlInsert = "INSERT INTO coordenador_inbox 
              (contrato_id, diretoria, fiscal_id, payload_json, status, created_at) 
              VALUES (?, ?, ?, ?, 'PENDENTE', NOW())";

$stmtIns = $conn->prepare($sqlInsert);
$stmtIns->bind_param("isis", $contrato_id, $diretoria, $user_id, $payload_json);

if ($stmtIns->execute()) {
    $request_id = $stmtIns->insert_id;
    // Redireciona para a página de sucesso mostrando o ID da solicitação
    header("Location: solicitacao_aprov_sucesso.php?req_id=$request_id");
    exit;
} else {
    echo "Erro ao salvar solicitação: " . $conn->error;
}
?>