<?php
/**
 * logout.php
 * Finaliza sessão e volta para tela de login.
 */
session_start();
session_unset();
session_destroy();

// Limpa cookie de sessão, se existir
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Redireciona para login (com flag de logout)
header('Location: /login_senha.php?logout=1');
exit;
