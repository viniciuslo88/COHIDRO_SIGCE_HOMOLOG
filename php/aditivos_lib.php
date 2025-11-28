<?php
// aditivos_lib.php — usa exatamente:
// emop_aditivos(
//   id, contrato_id, valor_aditivo_total,
//   novo_prazo, valor_total_apos_aditivo, created_at
// )

if (!function_exists('coh_norm_decimal')) {
  /**
   * Normaliza número em formato BR/US para string com ponto decimal.
   * "1.234,56" -> "1234.56"
   */
  function coh_norm_decimal($v) {
    if ($v === null || $v === '') return null;
    $v = (string)$v;
    // remove milhares
    $v = preg_replace('/\./', '', $v);
    // vírgula vira ponto
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (string)$v : null;
  }
}

/**
 * Garante que a tabela emop_aditivos exista com o schema esperado.
 */
function coh_ensure_aditivos_schema(mysqli $conn) {
  $sqlCreate = "CREATE TABLE IF NOT EXISTS `emop_aditivos` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `contrato_id` INT NOT NULL,
      `valor_aditivo_total` DECIMAL(15,2) DEFAULT NULL,
      `novo_prazo` INT DEFAULT NULL,
      `valor_total_apos_aditivo` DECIMAL(15,2) DEFAULT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_contrato` (`contrato_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sqlCreate);

  // garante AI no id (idempotente)
  @$conn->query("ALTER TABLE `emop_aditivos` 
                 MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT");
}

/**
 * Lê os aditivos de um contrato, ordenados cronologicamente,
 * e adiciona o campo derivado "aditivo_anterior"
 * (valor total acumulado da linha anterior).
 *
 * @return array<int, array{
 *   id:int,
 *   contrato_id:int,
 *   valor_aditivo_total:?float,
 *   novo_prazo:?int,
 *   valor_total_apos_aditivo:?float,
 *   created_at:?string,
 *   aditivo_anterior:?float
 * }>
 */
function coh_fetch_aditivos_with_prev(mysqli $conn, int $contrato_id): array {
  coh_ensure_aditivos_schema($conn);
  $contrato_id = (int)$contrato_id;

  $sql = "SELECT id, contrato_id, valor_aditivo_total, novo_prazo,
                 valor_total_apos_aditivo, created_at
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
    $rows[$i]['novo_prazo']               = ($r['novo_prazo'] !== null ? (int)$r['novo_prazo'] : null);
    $rows[$i]['aditivo_anterior']         = $prev_acum;

    $prev_acum = $acum_total;
  }

  return $rows;
}

/**
 * Insere vários aditivos.
 * Cada item pode ter:
 *  - valor_aditivo_total (ou valor)
 *  - novo_prazo (ou prazo)
 *  - valor_total_apos_aditivo (ou valor_total)
 *
 * Aceita números em BR ("1.234,56") ou US ("1234.56").
 */
function coh_insert_aditivos_from_array(mysqli $conn, int $contrato_id, array $rows) {
  coh_ensure_aditivos_schema($conn);

  $sql = "INSERT INTO `emop_aditivos`
          (`contrato_id`, `valor_aditivo_total`, `novo_prazo`, `valor_total_apos_aditivo`, `created_at`)
          VALUES (?,?,?,?, NOW())";
  $st = $conn->prepare($sql);
  if (!$st) {
    throw new Exception('Falha ao preparar statement de aditivo: ' . ($conn->error ?: 'erro desconhecido'));
  }

  foreach ($rows as $r) {
    $valor   = coh_norm_decimal($r['valor_aditivo_total']      ?? $r['valor']       ?? null);
    $prazo   = isset($r['novo_prazo']) ? (int)$r['novo_prazo']
             : (isset($r['prazo'])     ? (int)$r['prazo']      : null);
    $valorTT = coh_norm_decimal($r['valor_total_apos_aditivo'] ?? $r['valor_total'] ?? null);

    // contrato_id (i), valor_aditivo_total (s), novo_prazo (i), valor_total_apos_aditivo (s)
    if (!$st->bind_param("isis", $contrato_id, $valor, $prazo, $valorTT)) {
      $err = $conn->error ?: $st->error;
      throw new Exception('Falha ao bind do aditivo: ' . $err);
    }
    if (!$st->execute()) {
      $err = $conn->error ?: $st->error;
      throw new Exception('Falha ao inserir aditivo: ' . $err);
    }
  }

  $st->close();
}

/**
 * Exclui um aditivo específico, garantindo vínculo com o contrato.
 */
function coh_delete_aditivo(mysqli $conn, int $contrato_id, int $aditivo_id) {
  coh_ensure_aditivos_schema($conn);
  $sql = "DELETE FROM `emop_aditivos`
          WHERE `id`=? AND `contrato_id`=?";
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
