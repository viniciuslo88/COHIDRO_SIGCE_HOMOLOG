<?php
// php/diretoria_guard.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/**
 * Retorna um fragmento SQL para restringir por Diretoria do usuário logado.
 * - Fiscal (role=1) e diretorias operacionais (DIRM, DIRPP, DIROB): vê apenas sua própria Diretoria
 * - Níveis altos (>=4) e diretoria PRES/GER/DEV: acesso total (sem filtro)
 * - Sem diretoria definida: bloqueia (1=0)
 *
 * @param mysqli $conn
 * @param string $alias Optional table alias (e.g., 'c')
 * @return string SQL fragment começando com ' AND ...' ou ' AND 1=0' ou string vazia
 */
function diretoria_guard_where(mysqli $conn, string $alias = ''): string {
  $alias = $alias ? rtrim($alias, '.') . '.' : '';
  $role = (int)($_SESSION['role'] ?? 0);
  $dir  = strtoupper(trim((string)($_SESSION['diretoria'] ?? '')));

  // Admin-like (Presidente/Desenvolvedor) tem visão total
  if ($role >= 4 || in_array($dir, ['PRES','GER','DEV'], true)) {
    return '';
  }

  if (in_array($dir, ['DIRM','DIRPP','DIROB'], true)) {
    $esc = $conn->real_escape_string($dir);
    return " AND UPPER(TRIM({$alias}Diretoria)) = '{$esc}' ";
  }

  // Falha fechada: sem diretoria consistente, não retorna nada
  return " AND 1=0 ";
}

/**
 * Verifica se o usuário atual pode acessar um contrato específico por id,
 * considerando a Diretoria. Admin-like sempre pode.
 */
function can_access_contrato_by_id(mysqli $conn, int $contrato_id): bool {
  $role = (int)($_SESSION['role'] ?? 0);
  $dir  = strtoupper(trim((string)($_SESSION['diretoria'] ?? '')));

  if ($role >= 4 || in_array($dir, ['PRES','GER','DEV'], true)) {
    return true;
  }
  if (!in_array($dir, ['DIRM','DIRPP','DIROB'], true)) {
    return false;
  }
  $sql = "SELECT 1 FROM emop_contratos WHERE id = ? AND UPPER(TRIM(Diretoria)) = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return false;
  $stmt->bind_param("is", $contrato_id, $dir);
  $ok = $stmt->execute();
  if (!$ok) { $stmt->close(); return false; }
  $stmt->store_result();
  $allowed = $stmt->num_rows > 0;
  $stmt->close();
  return $allowed;
}

/**
 * Fonte (tabela/visão) de contratos conforme sessão.
 * Mantida para compatibilidade com seu código atual.
 */
function emop_source_by_session(): string {
  $dirRaw = $_SESSION['diretoria'] ?? '';
  $role   = (int)($_SESSION['role'] ?? 0);
  $dir = strtoupper(trim((string)$dirRaw));

  if ($role >= 5 || in_array($dir, ['PRES','GER','DEV'], true)) {
      return 'vw_emop_contratos_all'; // ou 'emop_contratos'
  }
  if ($dir === 'DIRM')  return 'vw_emop_contratos_dirm';
  if ($dir === 'DIRPP') return 'vw_emop_contratos_dirpp';
  if ($dir === 'DIROB') return 'vw_emop_contratos_dirob';

  return '(SELECT * FROM emop_contratos WHERE 1=0) AS emop_contratos';
}