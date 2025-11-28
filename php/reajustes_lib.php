<?php
// reajustes_lib.php — compatível com:
// emop_reajustamento(
//   id, contrato_id, reajustes_percentual,
//   valor_total_apos_reajuste, created_at
// )

if (!function_exists('coh_norm_decimal')) {
  /**
   * Normaliza número em formato BR/US para string com ponto decimal.
   */
  function coh_norm_decimal($v) {
    if ($v === null || $v === '') return null;
    $v = (string)$v;
    $v = preg_replace('/\./', '', $v); // remove milhares
    $v = str_replace(',', '.', $v);    // vírgula -> ponto
    return is_numeric($v) ? (string)$v : null;
  }
}

/**
 * Garante a existência da tabela com as colunas esperadas
 * e força AUTO_INCREMENT no id (idempotente).
 */
function coh_ensure_reajustamento_schema(mysqli $conn) {
  $sqlCreate = "CREATE TABLE IF NOT EXISTS `emop_reajustamento` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `contrato_id` INT NOT NULL,
      `reajustes_percentual` DECIMAL(15,4) DEFAULT NULL,
      `valor_total_apos_reajuste` DECIMAL(15,2) DEFAULT NULL,
      `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_contrato` (`contrato_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sqlCreate);

  @$conn->query("ALTER TABLE `emop_reajustamento`
                 MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT");
}

/**
 * Lê os reajustes de um contrato ordenados cronologicamente
 * e devolve também:
 *  - reajuste_anterior: acumulado até a linha anterior
 *  - valor_reajuste: diferença entre o acumulado atual e o anterior
 *
 * @return array<int, array{
 *   id:int,
 *   contrato_id:int,
 *   reajustes_percentual:?float,
 *   valor_total_apos_reajuste:?float,
 *   created_at:?string,
 *   reajuste_anterior:?float,
 *   valor_reajuste:?float
 * }>
 */
function coh_fetch_reajustes_with_prev(mysqli $conn, int $contrato_id): array {
  coh_ensure_reajustamento_schema($conn);
  $contrato_id = (int)$contrato_id;

  $sql = "SELECT id, contrato_id, reajustes_percentual,
                 valor_total_apos_reajuste, created_at
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
    $acum_total  = ($r['valor_total_apos_reajuste'] !== null
                    ? (float)$r['valor_total_apos_reajuste']
                    : $prev_acum);
    $valor_linha = $acum_total - $prev_acum;

    $rows[$i]['valor_total_apos_reajuste'] =
      ($r['valor_total_apos_reajuste'] !== null ? (float)$r['valor_total_apos_reajuste'] : null);

    $rows[$i]['reajustes_percentual'] =
      ($r['reajustes_percentual'] !== null ? (float)$r['reajustes_percentual'] : null);

    $rows[$i]['reajuste_anterior'] = $prev_acum;
    $rows[$i]['valor_reajuste']    = $valor_linha;

    $prev_acum = $acum_total;
  }

  return $rows;
}

/**
 * Insere vários reajustes de uma vez.
 * $rows: cada item pode conter:
 *   - reajustes_percentual (ou percentual/perc)
 *   - valor_total_apos_reajuste (ou valor_total/valor)
 *
 * Aceita números em BR ("1.234,56") ou US ("1234.56").
 */
function coh_insert_reajustes_from_array(mysqli $conn, int $contrato_id, array $rows) {
  coh_ensure_reajustamento_schema($conn);

  $sql = "INSERT INTO `emop_reajustamento`
          (`contrato_id`, `reajustes_percentual`, `valor_total_apos_reajuste`, `created_at`)
          VALUES (?,?,?, NOW())";

  $st = $conn->prepare($sql);
  if (!$st) {
    throw new Exception('Falha ao preparar statement de reajuste: ' . ($conn->error ?: 'erro desconhecido'));
  }

  foreach ($rows as $r) {
    $perc  = coh_norm_decimal(
               $r['reajustes_percentual']
               ?? $r['percentual']
               ?? $r['perc']
               ?? null
             );
    $valor = coh_norm_decimal(
               $r['valor_total_apos_reajuste']
               ?? $r['valor_total']
               ?? $r['valor']
               ?? null
             );

    // contrato_id (i), reajustes_percentual (d), valor_total_apos_reajuste (d)
    if (!$st->bind_param("idd", $contrato_id, $perc, $valor)) {
      $err = $conn->error ?: $st->error;
      throw new Exception('Falha ao bind do reajuste: ' . $err);
    }
    if (!$st->execute()) {
      $err = $conn->error ?: $st->error;
      throw new Exception('Falha ao inserir reajuste: ' . $err);
    }
  }

  $st->close();
}

/**
 * Exclusão simples por id + contrato.
 */
function coh_delete_reajuste(mysqli $conn, int $contrato_id, int $reajuste_id) {
  coh_ensure_reajustamento_schema($conn);
  $sql = "DELETE FROM `emop_reajustamento`
          WHERE `id`=? AND `contrato_id`=?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("ii", $reajuste_id, $contrato_id);
    if (!$st->execute()) {
      throw new Exception('Falha ao excluir reajuste: '.$st->error);
    }
    $st->close();
  } else {
    throw new Exception('Falha ao preparar exclusão de reajuste: ' . ($conn->error ?: 'erro desconhecido'));
  }
}
