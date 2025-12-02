<?php
// medicoes_lib.php — usa exatamente:
// emop_medicoes(id, contrato_id, data_medicao, valor_rs, acumulado_rs, percentual, obs, created_at, created_by)

/**
 * Helpers compartilhados
 * ----------------------
 * - coh_current_user_id(): pega o ID do usuário logado na sessão
 * - coh_pode_alterar(): regra 24h + mesmo usuário + nível
 */
if (!function_exists('coh_current_user_id')) {
  function coh_current_user_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
      @session_start();
    }
    if (!empty($_SESSION['user_id'])) {
      return (int)$_SESSION['user_id'];
    }
    return null;
  }
}

if (!function_exists('coh_pode_alterar')) {
  /**
   * Regra:
   *  - Se $tem_permissao_geral == false  => nunca pode
   *  - Nível >= 5 (admin/dev)            => sempre pode
   *  - Demais níveis:
   *      - Se houver created_by e for de outro usuário => NÃO pode
   *      - Se created_by for do mesmo usuário OU null  => pode até 24h após created_at
   */
  function coh_pode_alterar($created_at, bool $tem_permissao_geral, $created_by = null): bool {
    if (!$tem_permissao_geral) return false;

    if (session_status() === PHP_SESSION_NONE) {
      @session_start();
    }

    $uid   = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $nivel = isset($_SESSION['role'])
                ? (int)$_SESSION['role']
                : (isset($_SESSION['nivel']) ? (int)$_SESSION['nivel'] : 0);

    // Admin / Dev sempre podem
    if ($nivel >= 5) return true;

    // Se veio created_by e é de outro usuário, não pode
    if (!empty($created_by) && (int)$created_by !== $uid) {
      return false;
    }

    if (empty($created_at)) return false;

    $ts = is_numeric($created_at) ? (int)$created_at : strtotime((string)$created_at);
    if (!$ts) return false;

    // 24h = 86400s
    return (time() - $ts) <= 86400;
  }
}

// =========== Normalização numérica & data ===========

if (!function_exists('coh_norm_decimal')) {
  function coh_norm_decimal($v) {
    if ($v === null || $v === '') return null;

    // Se já for numérico/ponto flutuante (ex: 1200.50), retorna ele mesmo
    if (is_numeric($v)) return (string)$v;

    $v = (string)$v;
    // Remove pontos de milhar
    $v = str_replace('.', '', $v);
    // Troca vírgula decimal por ponto
    $v = str_replace(',', '.', $v);

    return is_numeric($v) ? (string)$v : null;
  }
}

/** Aceita dd/mm/aaaa ou aaaa-mm-dd; retorna 'aaaa-mm-dd' ou null */
function coh_parse_date_nullable($s) {
  $s = trim((string)($s ?? ''));
  if ($s === '') return null;
  // ISO (aaaa-mm-dd)
  if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;
  // BR (dd/mm/aaaa)
  if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  return null;
}

function coh_ensure_medicoes_schema(mysqli $conn) {
  $sqlCreate = "CREATE TABLE IF NOT EXISTS `emop_medicoes` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `contrato_id` INT NOT NULL,
      `data_medicao` DATE DEFAULT NULL,
      `valor_rs` DECIMAL(15,2) DEFAULT NULL,
      `acumulado_rs` DECIMAL(15,2) DEFAULT NULL,
      `percentual` DECIMAL(15,4) DEFAULT NULL,
      `obs` TEXT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      `created_by` INT NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY (`contrato_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sqlCreate);

  // garante AUTO_INCREMENT
  @$conn->query("ALTER TABLE `emop_medicoes` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT");

  // garante coluna created_by em bases antigas
  $cols = [];
  if ($rs = $conn->query("SHOW COLUMNS FROM emop_medicoes")) {
    while ($r = $rs->fetch_assoc()) $cols[] = $r['Field'];
    $rs->free();
  }
  if (!in_array('created_by', $cols, true)) {
    @$conn->query("ALTER TABLE `emop_medicoes` ADD COLUMN `created_by` INT NULL DEFAULT NULL AFTER `created_at`");
  }
}

/**
 * Lê as medições do contrato ordenadas cronologicamente
 * e adiciona o campo derivado "liquidado_anterior" (acumulado da linha anterior).
 */
function coh_fetch_medicoes_with_prev(mysqli $conn, int $contrato_id): array {
  coh_ensure_medicoes_schema($conn);
  $contrato_id = (int)$contrato_id;

  $sql = "SELECT id, contrato_id, data_medicao, valor_rs, acumulado_rs, percentual, obs, created_at, created_by
          FROM emop_medicoes
          WHERE contrato_id = {$contrato_id}
          ORDER BY data_medicao ASC, id ASC";

  $rows = [];
  if ($rs = $conn->query($sql)) {
    while ($r = $rs->fetch_assoc()) { $rows[] = $r; }
    $rs->free();
  }

  $prev_acum = null;
  foreach ($rows as $i => $r) {
    $rows[$i]['valor_rs']           = ($r['valor_rs']      !== null ? (float)$r['valor_rs']      : null);
    $rows[$i]['acumulado_rs']       = ($r['acumulado_rs']  !== null ? (float)$r['acumulado_rs']  : null);
    $rows[$i]['percentual']         = ($r['percentual']    !== null ? (float)$r['percentual']    : null);
    $rows[$i]['liquidado_anterior'] = $prev_acum !== null ? (float)$prev_acum : null;

    if ($r['acumulado_rs'] !== null) {
      $prev_acum = (float)$r['acumulado_rs'];
    }
  }
  return $rows;
}

/**
 * Insere um lote de medições
 * - created_at = NOW()
 * - created_by = usuário logado
 */
function coh_insert_medicoes_from_array(mysqli $conn, int $contrato_id, array $rows) {
  coh_ensure_medicoes_schema($conn);

  $sql = "INSERT INTO `emop_medicoes`
          (`contrato_id`, `data_medicao`, `valor_rs`, `acumulado_rs`, `percentual`, `obs`, `created_at`, `created_by`)
          VALUES (?,?,?,?,?,?, NOW(), ?)";
  $st = $conn->prepare($sql);
  if (!$st) {
    throw new Exception('Falha ao preparar medição: ' . ($conn->error ?: 'erro desconhecido'));
  }

  $created_by = coh_current_user_id();

  foreach ($rows as $r) {
    // Normaliza data
    $data = coh_parse_date_nullable($r['data_medicao'] ?? $r['data'] ?? null);

    $valor      = coh_norm_decimal($r['valor_rs']      ?? $r['valor']      ?? null);
    $acumulado  = coh_norm_decimal($r['acumulado_rs'] ?? $r['acumulado'] ?? null);
    $percentual = coh_norm_decimal($r['percentual']    ?? $r['perc']       ?? null);
    $obs        = isset($r['obs']) ? (string)$r['obs'] : (isset($r['observacao']) ? (string)$r['observacao'] : null);

    // Se tudo for nulo/vazio, pula
    if ($data === null && $valor === null && $acumulado === null && $obs === null) {
        continue;
    }

    // bind: i s s s s s i
    if (!$st->bind_param("isssssi", $contrato_id, $data, $valor, $acumulado, $percentual, $obs, $created_by)) {
      $err = $conn->error ?: $st->error;
      throw new Exception('Falha ao bind da medição: ' . $err);
    }
    if (!$st->execute()) {
      $err = $conn->error ?: $st->error;
      throw new Exception('Falha ao inserir medição: ' . $err);
    }
  }
  $st->close();
}

function coh_delete_medicao(mysqli $conn, int $contrato_id, int $medicao_id) {
  coh_ensure_medicoes_schema($conn);
  $sql = "DELETE FROM `emop_medicoes` WHERE `id`=? AND `contrato_id`=?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("ii", $medicao_id, $contrato_id);
    if (!$st->execute()) throw new Exception('Falha ao excluir medição: '.$st->error);
    $st->close();
  } else {
    throw new Exception('Falha ao preparar exclusão de medição: ' . ($conn->error ?: 'erro desconhecido'));
  }
}
