<?php
/**
 * /php/processa_login.php — compat e API POST para login
 * Aceita CPF+senha e autentica; se vier só CPF, redireciona para /login_senha.php?cpf=...
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/conn.php';

$cpf   = sanitize_cpf($_POST['cpf'] ?? '');
$senha = (string)($_POST['senha'] ?? '');

if ($cpf && !$senha){
    redirect('/login_senha.php?cpf=' . urlencode($cpf));
    exit;
}

if (!$cpf || !$senha){
    $_SESSION['auth_error'] = 'Informe CPF e senha.';
    redirect('/login_senha.php');
    exit;
}

$user = fetch_user_by_cpf($cpf);
if (!$user){
    $_SESSION['auth_error'] = 'Usuário não encontrado.';
    redirect('/login_senha.php?cpf=' . urlencode($cpf));
    exit;
}

// Primeiro acesso (sem senha definida)
if (empty($user['senha'])){
    $_SESSION['flash_ok'] = 'Defina sua senha para continuar.';
    redirect('/primeiro_acesso.php?cpf=' . urlencode($cpf));
    exit;
}

if (!verify_password($senha, $user['senha'])){
    $_SESSION['auth_error'] = 'Senha inválida.';
    redirect('/login_senha.php?cpf=' . urlencode($cpf));
    exit;
}

// OK — cria sessão
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['cpf']     = $user['cpf'];
$_SESSION['nome']    = $user['nome'] ?? '';
$_SESSION['nivel']   = (int)($user['access_level'] ?? 0);
$_SESSION['role']    = (int)($user['access_level'] ?? 0);
$_SESSION['is_auth'] = true;
$_SESSION['last_login']= date('Y-m-d H:i:s');

if (function_exists('session_regenerate_id')){
    session_regenerate_id(true);
}

redirect(route_after_login($_SESSION['nivel'] ?? null));
