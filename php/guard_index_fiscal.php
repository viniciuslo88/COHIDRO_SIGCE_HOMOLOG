<?php
// php/guard_index_fiscal.php — bloqueia acesso ao index para Fiscal (nível 1)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$role = (int)($_SESSION['role'] ?? 0);
if ($role === 1) {
  header('Location: /form_contratos.php');
  exit;
}
