<?php
// php/auth.php — helpers de autenticação e saneamento (unificado)
if (!function_exists('sanitize_cpf')) {
  function sanitize_cpf($v){
    return preg_replace('/\D/', '', (string)($v ?? ''));
  }
}
if (!function_exists('redirect')) {
  function redirect($path){
    // garante gravação do cookie de sessão
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
    // tenta Location normal
    if (!headers_sent()) {
      header('Location: ' . $path, true, 302);
      exit;
    }
    // Fallback se já houve saída (BOM/echo): JS + meta refresh
    $path_esc = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    echo "<script>window.location.replace('{$path_esc}');</script>";
    echo "<noscript><meta http-equiv='refresh' content='0;url={$path_esc}'></noscript>";
    exit;
  }
}
if (!function_exists('verify_password')) {
  function verify_password($input, $hash_from_db){
    // Aceita tanto password_hash() quanto texto em claro legado
    if (!$hash_from_db) return false;
    if (password_get_info($hash_from_db)['algo']) {
      return password_verify($input, $hash_from_db);
    }
    // fallback legado
    return hash_equals((string)$hash_from_db, (string)$input);
  }
}
// === NOVO: função para buscar usuário pelo CPF ===
if (!function_exists('get_user_by_cpf')) {
  function get_user_by_cpf($cpf){
    global $conn;
    require_once __DIR__ . '/conn.php';

    $stmt = $conn->prepare("SELECT id, nome, cpf, access_level, status, senha 
                            FROM usuarios_cohidro_sigce
                            WHERE cpf = ? LIMIT 1");
    $stmt->bind_param("s", $cpf);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();
    return $user ?: null;
  }
}
// Roteia por nível — sua regra atual
if (!function_exists('route_after_login')) {
  function route_after_login($nivel) {
    $n = is_numeric($nivel) ? (int)$nivel : (int)strval($nivel ?? 0);
    if ($n === 1) return '/form_contratos.php';
    if ($n >= 2)  return '/index.php';
    return '/login_senha.php';
  }
}
}
?>
