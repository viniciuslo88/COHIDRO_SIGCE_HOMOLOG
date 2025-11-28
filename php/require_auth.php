
<?php
// RBAC bootstrapping: garante $_SESSION['role'] usando usuarios_cohidro.access_level
if (!isset($_SESSION['role']) && isset($_SESSION['user_id'])){
  require_once __DIR__ . '/conn.php';
  $uid = (int)$_SESSION['user_id'];
  if ($uid>0){
    $r = $conn->query("SELECT access_level, diretoria, nome, cpf FROM usuarios_cohidro_sigce WHERE id={$uid} LIMIT 1");
    if ($r && $r->num_rows){
      $u = $r->fetch_assoc();
      $_SESSION['role'] = (int)$u['access_level'];
      if (empty($_SESSION['diretoria']) && !empty($u['diretoria'])){
        $_SESSION['diretoria'] = $u['diretoria'];
      }
      if (empty($_SESSION['nome']) && !empty($u['nome'])){ $_SESSION['nome'] = $u['nome']; }
      if (empty($_SESSION['cpf']) && !empty($u['cpf'])){ $_SESSION['cpf'] = $u['cpf']; }
    }
  }
}
?>

<?php
// php/require_auth.php — proteção para páginas privadas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id']) || empty($_SESSION['cpf'])) {
    header('Location: /login_senha.php');
    exit;
}
?>
