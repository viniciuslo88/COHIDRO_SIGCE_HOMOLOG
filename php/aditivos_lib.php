<?php
// php/aditivos_lib.php
// Biblioteca de funções para gerenciamento de Aditivos no Banco de Dados
// Suporta: numero_aditivo, data, tipo, observacao, novo_prazo (texto/int) e valores.

// -------------------------------------------------------
// Helpers compartilhados
// -------------------------------------------------------
if (!function_exists('coh_current_user_id')) {
  function coh_current_user_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  }
}

/**
 * Verifica permissão de edição (Regra de 24h ou Nível 5)
 */
if (!function_exists('coh_pode_alterar')) {
  function coh_pode_alterar($created_at, bool $tem_permissao_geral, $created_by = null): bool {
    if (!$tem_permissao_geral) return false;
    if (session_status() === PHP_SESSION_NONE) @session_start();

    $uid   = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $nivel = isset($_SESSION['role']) ? (int)$_SESSION['role'] : (isset($_SESSION['nivel']) ? (int)$_SESSION['nivel'] : 0);

    // Admin/dev (Nível 5) podem sempre
    if ($nivel >= 5) return true;

    // Se tem created_by e não é o dono, não pode mexer
    if (!empty($created_by) && (int)$created_by !== $uid) return false;

    if (empty($created_at)) return false;

    $ts = is_numeric($created_at) ? (int)$created_at : strtotime((string)$created_at);
    if (!$ts) return false;

    // 24 horas = 86400 segundos
    return (time() - $ts) <= 86400;
  }
}

if (!function_exists('coh_norm_decimal')) {
  function coh_norm_decimal($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (string)$v;
    $v = (string)$v;
    $v = str_replace('.', '', $v);   // remove milhares
    $v = str_replace(',', '.', $v);  // vírgula -> ponto
    return is_numeric($v) ? (string)$v : null;
  }
}

// Helper para data
if (!function_exists('coh_parse_date_nullable')) {
  function coh_parse_date_nullable($s) {
    $s = trim((string)($s ?? ''));
    if ($s === '') return null;
    // Formato YYYY-MM-DD
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;
    // Formato DD/MM/YYYY
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
  }
}

/**
 * Garante que a tabela emop_aditivos exista e tenha as colunas novas.
 */
function coh_ensure_aditivos_schema(mysqli $conn) {
  $sqlCreate = "CREATE TABLE IF NOT EXISTS `emop_aditivos` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `contrato_id` INT NOT NULL,
      `numero_aditivo` VARCHAR(50) DEFAULT NULL,
      `tipo` VARCHAR(50) DEFAULT NULL,
      `data` DATE DEFAULT NULL,
      `valor_aditivo_total` DECIMAL(15,2) DEFAULT NULL,
      `novo_prazo` VARCHAR(100) DEFAULT NULL,
      `valor_total_apos_aditivo` DECIMAL(15,2) DEFAULT NULL,
      `observacao` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      `created_by` INT NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_contrato` (`contrato_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sqlCreate);

  // Verificação e adição de colunas em bancos existentes
  $cols = [];
  if ($rs = $conn->query("SHOW COLUMNS FROM emop_aditivos")) {
    while ($r = $rs->fetch_assoc()) $cols[] = $r['Field'];
    $rs->free();
  }

  if (!in_array('numero_aditivo', $cols)) $conn->query("ALTER TABLE `emop_aditivos` ADD COLUMN `numero_aditivo` VARCHAR(50) DEFAULT NULL AFTER `contrato_id`");
  if (!in_array('tipo', $cols))           $conn->query("ALTER TABLE `emop_aditivos` ADD COLUMN `tipo` VARCHAR(50) DEFAULT NULL AFTER `numero_aditivo`");
  if (!in_array('data', $cols))           $conn->query("ALTER TABLE `emop_aditivos` ADD COLUMN `data` DATE DEFAULT NULL AFTER `tipo`");
  if (!in_array('observacao', $cols))     $conn->query("ALTER TABLE `emop_aditivos` ADD COLUMN `observacao` TEXT DEFAULT NULL AFTER `valor_total_apos_aditivo`");
  if (!in_array('created_by', $cols))     $conn->query("ALTER TABLE `emop_aditivos` ADD COLUMN `created_by` INT NULL DEFAULT NULL AFTER `created_at`");
  
  // Alterar novo_prazo para VARCHAR para aceitar textos como "30 dias" ou "12 meses"
  // O original usava INT, o que causava perda de dados se o usuário digitasse texto.
  $conn->query("ALTER TABLE `emop_aditivos` MODIFY `novo_prazo` VARCHAR(100) DEFAULT NULL");
}

/**
 * Lê os aditivos de um contrato
 */
function coh_fetch_aditivos_with_prev(mysqli $conn, int $contrato_id): array {
  coh_ensure_aditivos_schema($conn);
  $contrato_id = (int)$contrato_id;

  $sql = "SELECT id, contrato_id,
                 numero_aditivo,
                 valor_aditivo_total, novo_prazo,
                 valor_total_apos_aditivo,
                 data, tipo, observacao,
                 created_at, created_by
          FROM emop_aditivos
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
    $valor      = ($r['valor_aditivo_total']      !== null ? (float)$r['valor_aditivo_total']      : 0.0);
    $acum_total = ($r['valor_total_apos_aditivo'] !== null ? (float)$r['valor_total_apos_aditivo'] : $prev_acum + $valor);

    $rows[$i]['valor_aditivo_total']      = $valor;
    $rows[$i]['valor_total_apos_aditivo'] = $acum_total;
    // novo_prazo agora é retornado como string para não quebrar textos
    $rows[$i]['novo_prazo']               = $r['novo_prazo'];
    $rows[$i]['aditivo_anterior']         = $prev_acum;

    $prev_acum = $acum_total;
  }

  return $rows;
}

/**
 * Insere vários aditivos.
 */
function coh_insert_aditivos_from_array(mysqli $conn, int $contrato_id, array $rows) {
  coh_ensure_aditivos_schema($conn);

  $sql = "INSERT INTO `emop_aditivos`
          (`contrato_id`, `numero_aditivo`, `valor_aditivo_total`,
           `novo_prazo`, `valor_total_apos_aditivo`,
           `data`, `tipo`, `observacao`,
           `created_at`, `created_by`)
          VALUES (?,?,?,?,?,?,?,?, NOW(), ?)";
  
  $st = $conn->prepare($sql);
  if (!$st) {
    throw new Exception('Falha ao preparar statement de aditivo: ' . ($conn->error ?: 'erro desconhecido'));
  }

  $created_by = coh_current_user_id();

  foreach ($rows as $r) {
    // Tratamento dos campos
    $numero = trim((string)($r['numero_aditivo'] ?? $r['numero'] ?? $r['num_aditivo'] ?? ''));
    $valor  = coh_norm_decimal($r['valor_aditivo_total']      ?? $r['valor']        ?? null);
    
    // Prazo tratado como string (substr para evitar erro de tamanho)
    $prazoRaw = $r['novo_prazo'] ?? $r['prazo'] ?? null;
    $prazo    = ($prazoRaw !== null) ? substr((string)$prazoRaw, 0, 100) : null;
    
    $valorTT = coh_norm_decimal($r['valor_total_apos_aditivo'] ?? $r['valor_total'] ?? null);
    $data    = coh_parse_date_nullable($r['data'] ?? null);
    $tipo    = isset($r['tipo']) ? substr((string)$r['tipo'], 0, 50) : null;
    $obs     = isset($r['observacao']) ? (string)$r['observacao'] : (isset($r['obs']) ? (string)$r['obs'] : null);

    // Validação: Se tudo vier vazio, ignora (segurança extra)
    if ($numero === '' && $valor === null && $prazo === null && $valorTT === null && $data === null && $tipo === null && $obs === null) {
      continue;
    }

    // Bind Param:
    // i = contrato_id
    // s = numero_aditivo
    // s = valor_aditivo_total (decimal as string)
    // s = novo_prazo (AGORA STRING PARA ACEITAR TEXTO)
    // s = valor_total_apos_aditivo
    // s = data
    // s = tipo
    // s = observacao
    // i = created_by
    if (!$st->bind_param("isssssssi", $contrato_id, $numero, $valor, $prazo, $valorTT, $data, $tipo, $obs, $created_by)) {
      throw new Exception('Falha ao bind do aditivo: ' . ($st->error ?: $conn->error));
    }

    if (!$st->execute()) {
      throw new Exception('Falha ao inserir aditivo: ' . ($st->error ?: $conn->error));
    }
  }

  $st->close();
}

/**
 * Exclui um aditivo específico
 */
function coh_delete_aditivo(mysqli $conn, int $contrato_id, int $aditivo_id) {
  coh_ensure_aditivos_schema($conn);
  $sql = "DELETE FROM `emop_aditivos` WHERE `id`=? AND `contrato_id`=?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("ii", $aditivo_id, $contrato_id);
    if (!$st->execute()) {
      throw new Exception('Falha ao excluir aditivo: '.$st->error);
    }
    $st->close();
  } else {
    throw new Exception('Falha ao preparar exclusão de aditivo: ' . ($conn->error ?: 'erro desconhecido'));
  }
}
?>