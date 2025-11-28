<?php
// php/roles.php — níveis de acesso

define('ROLE_FISCAL',        1);
define('ROLE_COORDENADOR',   2);
define('ROLE_DIRETOR',       3);
define('ROLE_PRESIDENTE',    4);
define('ROLE_DESENVOLVEDOR', 5);

function role_name(int $n): string {
  return match($n){
    ROLE_FISCAL        => 'Fiscal',
    ROLE_COORDENADOR   => 'Coordenador',
    ROLE_DIRETOR       => 'Diretor',
    ROLE_PRESIDENTE    => 'Presidente',
    ROLE_DESENVOLVEDOR => 'Desenvolvedor',
    default            => 'Desconhecido'
  };
}

function current_role(): int {
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  return (int)($_SESSION['role'] ?? 0);
}

// Alias conveniente
function role_id(): int { return current_role(); }

function require_role_or_higher(int $minRole){
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    header('Location: /login_senha.php'); exit;
  }
  if ((int)$_SESSION['role'] < $minRole) {
    http_response_code(403);
    echo "Acesso negado."; exit;
  }
}

function is_admin_like(): bool {
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  $r = (int)($_SESSION['role'] ?? 0);
  // 4 (Presidente) e 5 (Desenvolvedor) têm acesso total
  return $r >= ROLE_PRESIDENTE;
}

/**
 * Pode editar e aplicar diretamente no contrato (sem workflow de aprovação)?
 * Regra pedida: Coordenador (2) e Desenvolvedor (5) aplicam direto.
 */
function can_edit_immediately(): bool {
  $r = current_role();
  return in_array($r, [ROLE_DESENVOLVEDOR], true);
}

/** Precisa abrir solicitação para aprovação (workflow)? */
function must_request_approval(): bool {
  return !can_edit_immediately();
}
