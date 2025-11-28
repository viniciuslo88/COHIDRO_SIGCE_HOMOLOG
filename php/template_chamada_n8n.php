<?php
// Inicia a sessão PHP para ter acesso às variáveis de sessão
session_start();

// Configurações da requisição
$webhook_url = 'http://localhost:5678/webhook-test/formulario_aai'; // Substitua com o seu URL de webhook

// Define os dados padrão a serem enviados
$data_to_send = [
    'message' => 'Esta é uma mensagem enviada apenas com PHP!',
    'timestamp' => date('Y-m-d H:i:s')
];

// --- Início das Modificações ---

// 1. Verifica se o ID do usuário existe na sessão e o adiciona ao array de dados
if (isset($_SESSION['user_id'])) {
    $data_to_send['user_id'] = $_SESSION['user_id'];
}
// Instancia variáveis para envio
$data_to_send['Titulo'] = "Meu Primeiro Fórum em Produção";
$data_to_send['Descriação_breve'] = "Olá pessoal tudo bem???";
$data_to_send['acao'] = 2;
$data_to_send['id_formulario'] = 8;

// 2. Verifica se a variável de nível de acesso existe na sessão e a adiciona ao array
// Supondo que a chave da sessão seja 'access_level' ou 'user_role'
if (isset($_SESSION['access_level'])) {
    $data_to_send['access_level'] = $_SESSION['access_level'];
} elseif (isset($_SESSION['user_role'])) {
    $data_to_send['user_role'] = $_SESSION['user_role'];
}

// --- Fim das Modificações ---

// Codifica os dados em JSON para o corpo da requisição
$json_data = json_encode($data_to_send);

// Inicializa a sessão cURL
$ch = curl_init($webhook_url);

// Configura as opções do cURL
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json_data)
]);

// Executa a requisição cURL
$response = curl_exec($ch);

// Verifica se houve algum erro
if (curl_errno($ch)) {
    echo 'Erro cURL: ' . curl_error($ch);
} else {
    // Decodifica a resposta JSON do n8n
    $response_data = json_decode($response, true);

    echo '<h1>Resposta do Webhook n8n:</h1>';
    echo '<pre>';
    print_r($response_data);
    echo '</pre>';
}

// Fecha a sessão cURL
curl_close($ch);
?>