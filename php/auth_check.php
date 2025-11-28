<?php

$cpf_recebido = $_GET["cpf"];
$nome = $_GET["nome"];
session_start(); // Sempre no topo do script, antes de qualquer saída

//echo $cpf;

// Criar cookie com expiração de 1 hora (opcional, se ainda quiser manter)
$expiry = time() + 3600; // 1 hora
$cookie_value = base64_encode(json_encode([
    'username' => $username ?? '',
    'expiry' => $expiry
]));

setcookie('user_session', $cookie_value, $expiry, '/');

// Guarda o CPF na sessão
$_SESSION['cpf'] = $cpf_recebido;
$_SESSION['nome'] = $nome;

// Redireciona
header('Location: ../index.php');
exit;
