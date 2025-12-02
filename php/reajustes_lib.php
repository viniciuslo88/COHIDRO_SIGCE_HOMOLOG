<?php
// php/reajustes_lib.php

// Helpers compartilhados
if (!function_exists('coh_current_user_id')) {
  function coh_current_user_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  }
}
if (!function_exists('coh_pode_alterar')) {
  function coh_pode_alterar($created_at, bool $tem_permissao_geral, $created_by = null): bool {
    if (!$tem_permissao_geral) return false;
    if (session_status() === PHP_SESSION_NONE) @session_start();

    $uid   = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $nivel = isset($_SESSION['role'])
                ? (int)$_SESSION['role']
                : (isset($_SESSION['nivel']) ? (int)$_SESSION['nivel'] : 0);

    if ($nivel >= 5) return true;

    if (!empty($created_by) && (int)$created_by !== $uid) return false;
    if (empty($created_at)) return false;

    $ts = is_numeric($created_at) ? (int)$created_at : strtotime((string)$created_at);
    if (!$ts) return false;

    return (time() - $ts) <= 86400;
  }
}

// Função de normalização SEGURA
if (!function_exists('coh_norm_decimal')) {
  function coh_norm_decimal($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (string)$v;

    $v = (string)$v;
    if (strpos($v, ',') !== false) {
      $v = str_replace('.', '', $v);
      $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (string)$v : null;
  }
}

/**
 * Garante a tabela e ADICIONA COLUNAS NOVAS se faltarem
 */
function coh_ensure_reajustamento_schema(mysqli $conn) {
  $sqlCreate = "CREATE TABLE IF NOT EXISTS `emop_reajustamento` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `contrato_id` INT NOT NULL,
      `reajustes_percentual` DECIMAL(15,4) DEFAULT NULL,
      `valor_total_apos_reajuste` DECIMAL(15,2) DEFAULT NULL,
      `data_base` VARCHAR(20) DEFAULT NULL,
      `observacao` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      `created_by` INT NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_contrato` (`contrato_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sqlCreate);

  // Garante colunas novas caso a tabela já exista
  $cols = [];
  if ($rs = $conn->query("SHOW COLUMNS FROM emop_reajustamento")) {
    while($r = $rs->fetch_assoc()) $cols[] = $r['Field'];
    $rs->free();
  }
  if (!in_array('data_base', $cols, true)) {
    @$conn->query("ALTER TABLE emop_reajustamento ADD COLUMN data_base VARCHAR(20) DEFAULT NULL AFTER valor_total_apos_reajuste");
  }
  if (!in_array('observacao', $cols, true)) {
    @$conn->query("ALTER TABLE emop_reajustamento ADD COLUMN observacao TEXT DEFAULT NULL AFTER data_base");
  }
  if (!in_array('created_by', $cols, true)) {
    @$conn->query("ALTER TABLE emop_reajustamento ADD COLUMN created_by INT NULL DEFAULT NULL AFTER created_at");
  }

  @$conn->query("ALTER TABLE `emop_reajustamento` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT");
}

/**
 * Busca reajustes com todos os campos novos
 */
function coh_fetch_reajustes_with_prev(mysqli $conn, int $contrato_id): array {
  coh_ensure_reajustamento_schema($conn);
  $contrato_id = (int)$contrato_id;

  $sql = "SELECT id, contrato_id, reajustes_percentual,
                 valor_total_apos_reajuste, data_base, observacao, created_at, created_by
          FROM emop_reajustamento
          WHERE contrato_id = {$contrato_id}
          ORDER BY created_at ASC, id ASC";

  $rows = [];
  if ($rs = $conn->query($sql)) {
    while ($r = $rs->fetch_assoc()) {
      $rows[] = $r;
    }
    $rs->free();
  }

  $prev_acum = 0.0;
  foreach ($rows as $i => $r) {
    $acum_total  = ($r['valor_total_apos_reajuste'] !== null ? (float)$r['valor_total_apos_reajuste'] : $prev_acum);
    $valor_linha = $acum_total - $prev_acum;

    // Normaliza tipos
    $rows[$i]['valor_total_apos_reajuste'] = $r['valor_total_apos_reajuste'] !== null ? (float)$r['valor_total_apos_reajuste'] : null;
    $rows[$i]['reajustes_percentual']      = $r['reajustes_percentual']      !== null ? (float)$r['reajustes_percentual']      : null;

    // Alias para frontend
    $rows[$i]['percentual']        = $rows[$i]['reajustes_percentual'];
    $rows[$i]['reajuste_anterior'] = $prev_acum;
    $rows[$i]['valor_reajuste']    = $valor_linha;

    $prev_acum = $acum_total;
  }

  return $rows;
}

/**
 * Insere reajustes salvando data_base, observacao e created_by
 */
function coh_insert_reajustes_from_array(mysqli $conn, int $contrato_id, array $rows) {
  coh_ensure_reajustamento_schema($conn);

  $sql = "INSERT INTO `emop_reajustamento`
          (`contrato_id`, `reajustes_percentual`, `valor_total_apos_reajuste`, `data_base`, `observacao`, `created_at`, `created_by`)
          VALUES (?,?,?,?,?, NOW(), ?)";

  $st = $conn->prepare($sql);
  if (!$st) throw new Exception('Erro prepare reajuste: ' . $conn->error);

  $created_by = coh_current_user_id();

  foreach ($rows as $r) {
    $perc  = coh_norm_decimal($r['reajustes_percentual'] ?? $r['percentual'] ?? $r['perc'] ?? null);
    $valor = coh_norm_decimal($r['valor_total_apos_reajuste'] ?? $r['valor_total'] ?? $r['valor'] ?? null);
    $dt    = isset($r['data_base']) ? (string)$r['data_base'] : null;
    $obs   = isset($r['observacao']) ? (string)$r['observacao'] : null;

    // Se estiver tudo vazio, pula
    if ($perc === null && $valor === null && $dt === null) continue;

    // Bind: i (int), d (double/string), d (double/string), s (string), s (string), i (int)
    if (!$st->bind_param("iddssi", $contrato_id, $perc, $valor, $dt, $obs, $created_by)) {
      throw new Exception('Erro bind reajuste: ' . $st->error);
    }
    if (!$st->execute()) {
      throw new Exception('Erro execute reajuste: ' . $st->error);
    }
  }
  $st->close();
}

function coh_delete_reajuste(mysqli $conn, int $contrato_id, int $reajuste_id) {
  coh_ensure_reajustamento_schema($conn);
  $sql = "DELETE FROM `emop_reajustamento` WHERE `id`=? AND `contrato_id`=?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("ii", $reajuste_id, $contrato_id);
    if (!$st->execute()) throw new Exception('Erro delete reajuste: '.$st->error);
    $st->close();
  }
}
