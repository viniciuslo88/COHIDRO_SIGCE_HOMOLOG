<?php
/**
 * session_guard.php
 * Inclua este arquivo APÓS session_start() e após validar que o usuário está logado.
 * Controla expiração por inatividade e força logout quando estoura o tempo.
 */

// Tempo máximo de inatividade (em segundos)
$MAX_IDLE = $_SESSION['MAX_IDLE'] ?? 1800; // 30 minutos padrão; ajuste se quiser

$agora = time();
$ultima = $_SESSION['LAST_ACTIVITY'] ?? $agora;

if (($agora - $ultima) > $MAX_IDLE) {
    // Expirou: destruir sessão e redirecionar p/ login
    session_unset();
    session_destroy();
    // Evita reuso de sessão antiga
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    header("Location: /login_senha.php?expired=1");
    exit;
}

// Se não expirou, atualiza LAST_ACTIVITY
$_SESSION['LAST_ACTIVITY'] = $agora;
