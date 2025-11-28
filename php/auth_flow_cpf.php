<?php
/**
 * Controller do passo CPF (nenhum HTML aqui) — incluído no topo de login_senha.php
 * Fluxo: recebe POST[cpf], valida usuário ATIVO, seta sessão pendente e redireciona para login_senha.php.
 * Se já autenticado, envia direto ao index.php.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/auth.php'; // helper: sanitize_cpf(), redirect(), get_user_by_cpf()

// Já autenticado? Vai pro index.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['cpf'])) {
    redirect('/index.php');
}

// Tratamento de POST do CPF
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $cpf_in = $_POST['cpf'] ?? '';
    $cpf    = sanitize_cpf($cpf_in);

    if (!$cpf) {
        $_SESSION['auth_error'] = 'CPF inválido.';
        redirect('/login_senha.php');
    }

    $user = get_user_by_cpf($cpf);
    if (!$user) {
        $_SESSION['auth_error'] = 'Usuário não encontrado.';
        redirect('/login_senha.php');
    }

    if (strtolower($user['status'] ?? '') !== 'ativo') {
        $_SESSION['auth_error'] = 'Usuário inativo. Contate o administrador.';
        redirect('/login_senha.php');
    }

    // Se não existir senha cadastrada, força cadastro
    if (empty($user['senha'])) {
        // guarda pendências para o cadastro / definição de senha
        $_SESSION['pending_user_id'] = $user['id'];
        $_SESSION['pending_cpf']     = $user['cpf'];
        $_SESSION['pending_nome']    = $user['nome'] ?? '';
        $_SESSION['pending_nivel']   = $user['access_level'] ?? null;

        redirect('/cadastro.php?cpf=' . urlencode($user['cpf']));
    }

    // OK: manda para a tela de senha
    $_SESSION['pending_user_id'] = $user['id'];
    $_SESSION['pending_cpf']     = $user['cpf'];
    $_SESSION['pending_nome']    = $user['nome'] ?? '';
    $_SESSION['pending_nivel']   = $user['access_level'] ?? null;

    // Preserve redirect param if sent
    $redirect = $_POST['redirect'] ?? '';
    if ($redirect) {
        $_SESSION['post_login_redirect'] = $redirect;
    }

    redirect('/login_senha.php');
}

// Se GET, apenas exibe a página (layout mantido no arquivo original).
