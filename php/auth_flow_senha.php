<?php
/**
 * Controller: Password step (no UI) — inclua no TOPO do login_senha.php.
 * Preserva seu HTML/CSS/JS. Só processa o POST e, se ok, finaliza login e redireciona.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/auth.php'; // verify_password(), redirect(), route_after_login(), etc.
require_once __DIR__ . '/conn.php'; // garante $conn

// Sem CPF pendente volta ao início do fluxo
if (empty($_SESSION['pending_user_id']) || empty($_SESSION['pending_cpf'])) {
    redirect('/login_senha.php');
}

// Já autenticado? Vai pro index (ou redirect pré-definido)
if (!empty($_SESSION['user_id']) && !empty($_SESSION['cpf'])) {
    if (!empty($_SESSION['post_login_redirect'])) {
        $r = $_SESSION['post_login_redirect'];
        unset($_SESSION['post_login_redirect']);
        redirect($r ?: '/index.php');
    } else {
        redirect('/index.php');
    }
}

// Checamos se é POST e se veio o campo 'senha' (não dependemos de 'entrar_senha').
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['senha'])) {
    $senha = trim($_POST['senha'] ?? '');
    if ($senha === '') {
        $_SESSION['auth_error'] = 'Digite sua senha.';
        redirect('/login_senha.php');
    }

    $uid = (int)($_SESSION['pending_user_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, nome, cpf, access_level, status, senha 
                            FROM usuarios_cohidro_sigce
                            WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $_SESSION['auth_error'] = 'Usuário não encontrado.';
        redirect('/login_senha.php');
    }
    if (strtolower($user['status'] ?? '') !== 'ativo') {
        $_SESSION['auth_error'] = 'Usuário inativo. Contate o administrador.';
        redirect('/login_senha.php');
    }

    if (!verify_password($senha, $user['senha'] ?? '')) {
        $_SESSION['auth_error'] = 'Senha inválida.';
        redirect('/login_senha.php');
    }

    // Sucesso — cria sessão final e limpa pendências
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['cpf']     = $user['cpf'];
    $_SESSION['nome']    = $user['nome'];
    $_SESSION['nivel']   = $user['access_level'];
    
    unset($_SESSION['pending_user_id'], $_SESSION['pending_cpf'], $_SESSION['pending_nome'], $_SESSION['pending_nivel']);
    
    // evita fixation e garante novo ID
    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }
    
    // 1) Se houver redirect explícito no fluxo, respeita
    if (!empty($_SESSION['post_login_redirect'])) {
        $r = $_SESSION['post_login_redirect'];
        unset($_SESSION['post_login_redirect']);
        redirect($r ?: '/index.php');
    }
    
    // 2) Senão: regra atual (nível >= 2 vai pro index)
    $dest = route_after_login($_SESSION['nivel'] ?? null);
    redirect($dest);
}