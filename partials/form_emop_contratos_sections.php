<?php
// partials/form_emop_contratos_sections.php
// ------------------------------------------
// Seções internas do formulário (form_emop_contratos.php)

$id     = $id     ?? null;
$row    = (isset($row) && is_array($row)) ? $row : [];
$is_new = isset($is_new) ? (bool)$is_new : (empty($id));

if (!function_exists('e')) {
  function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/* ================== HELPERS DE NORMALIZAÇÃO/INBOX ================== */

/** Normaliza chaves: minúsculas, sem acento, só [a-z0-9_] */
if (!function_exists('coh_norm')) {
  function coh_norm(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    // troca espaços e hífens por underscore
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s); // tudo que não é letra/número -> _
    // remove acentos (fallback seguro caso iconv não esteja disponível)
    if (function_exists('iconv')) {
      $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
      if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    // compacta underscores
    $s = preg_replace('/_+/', '_', $s);
    // tira underscores do começo/fim
    $s = trim($s, '_');
    return $s;
  }
}

/** Lista canônica dos NAMES do formulário (mantém exatamente como nos inputs) */
$__FORM_CANON = [
  // DADOS GERAIS
  'Processo_SEI', 'Secretaria', 'Diretoria', 'No_do_Contrato', 'Valor_Do_Contrato',
  'Assinatura_Do_Contrato_Data', 'Status', 'Tipo', 'Fonte_De_Recursos',
  // LICITAÇÃO
  'Procedimento_Licitatorio', 'Processo_SEI_Licitacao',
  // DADOS DA OBRA
  'Estado', 'Municipio', 'Bairro', 'Regiao', 'Gestor_Obra', 'Objeto_Da_Obra',
  'Setor_EMOP', 'Responsavel_Fiscal', 'Fiscal_2', 'Empresa',
  // PRAZO
  'Data_Inicio', 'Prazo_Obra_Ou_Projeto', 'Data_Fim_Prevista',
  // SALDO
  'Medicao_Anterior_Acumulada_RS', 'Valor_Liquidado_Na_Medicao_RS',
  'Valor_Liquidado_Acumulado', 'Percentual_Executado',
];

/** Mapa norm->canônico, incluindo alguns aliases comuns de labels */
$__NORM_MAP = (function () use ($__FORM_CANON) {
  $m = [];
  foreach ($__FORM_CANON as $name) {
    $m[coh_norm($name)] = $name;
  }

  // Aliases úteis (labels diferentes que podem aparecer no payload)
  $aliases = [
    'processo_sei_licitacao'         => 'Processo_SEI_Licitacao',
    'processo_sei_da_licitacao'      => 'Processo_SEI_Licitacao',
    'procedimento_licitatorio'       => 'Procedimento_Licitatorio',
    'objeto_da_obra'                 => 'Objeto_Da_Obra',
    'percentual_executado'           => 'Percentual_Executado',
    'valor_do_contrato'              => 'Valor_Do_Contrato',
    'valor_liquidado_na_medicao_rs'  => 'Valor_Liquidado_Na_Medicao_RS',
    'medicao_anterior_acumulada_rs'  => 'Medicao_Anterior_Acumulada_RS',
    'no_do_contrato'                 => 'No_do_Contrato',
    'numero_do_contrato'             => 'No_do_Contrato',
    'data_fim_prevista'              => 'Data_Fim_Prevista',
    'prazo_da_obra_ou_projeto'       => 'Prazo_Obra_Ou_Projeto',
    'prazo_obra_ou_projeto'          => 'Prazo_Obra_Ou_Projeto',
    'setor_emop'                     => 'Setor_EMOP',
    'fiscal_responsavel'             => 'Responsavel_Fiscal',
  ];
  foreach ($aliases as $norm => $canon) {
    $m[$norm] = $canon;
  }
  return $m;
})();

/** Tenta resolver uma chave/label do payload para o name canônico do formulário */
if (!function_exists('coh_resolve_canon')) {
  function coh_resolve_canon(string $keyOrLabel) {
    global $__NORM_MAP;
    $n = coh_norm($keyOrLabel);
    if ($n === '') return null;
    if (isset($__NORM_MAP[$n])) return $__NORM_MAP[$n];

    // fallback: aproximação leve — remove sufixos tipo "_data" para tentar "data_inicio" etc.
    $strip = preg_replace('/_(data|valor|rs|porcento|percentual)$/', '', $n);
    if ($strip && isset($__NORM_MAP[$strip])) return $__NORM_MAP[$strip];

    return null;
  }
}

/** Verifica coluna em tabela (para filtros por fiscal) */
if (!function_exists('coh_col_exists')) {
  function coh_col_exists(mysqli $c, string $t, string $col): bool {
    $t   = $c->real_escape_string($t);
    $col = $c->real_escape_string($col);
    if (!$rs = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'")) return false;
    $ok = $rs->num_rows > 0;
    $rs->free();
    return $ok;
  }
}

/** WHERE que identifica o “mesmo fiscal” (id/cpf/nome), usado na revisão */
if (!function_exists('coh_where_mesmo_fiscal')) {
  function coh_where_mesmo_fiscal(mysqli $conn): string {
    $parts     = [];
    $user_id   = (int)($_SESSION['user_id'] ?? 0);
    $user_cpf  = trim((string)($_SESSION['cpf'] ?? ''));
    $user_name = trim((string)($_SESSION['nome'] ?? $_SESSION['name'] ?? ''));

    if (coh_col_exists($conn, 'coordenador_inbox', 'fiscal_id') && $user_id > 0) {
      $parts[] = "a.fiscal_id={$user_id}";
    }
    if (coh_col_exists($conn, 'coordenador_inbox', 'requested_by_id') && $user_id > 0) {
      $parts[] = "a.requested_by_id={$user_id}";
    }
    if ($user_cpf !== '' && coh_col_exists($conn, 'coordenador_inbox', 'requested_by_cpf')) {
      $parts[] = "a.requested_by_cpf='" . $conn->real_escape_string($user_cpf) . "'";
    }
    if ($user_name !== '' && coh_col_exists($conn, 'coordenador_inbox', 'requested_by')) {
      $nm      = $conn->real_escape_string($user_name);
      $parts[] = "a.requested_by='{$nm}'";
      $parts[] = "a.requested_by LIKE '%{$nm}%'";
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=1';
  }
}

/** Lê último payload de REJEITADO/REVISAO_SOLICITADA do mesmo fiscal para este contrato */
if (!function_exists('coh_inbox_last_payload_for_contract')) {
  function coh_inbox_last_payload_for_contract(mysqli $conn, int $contrato_id): array {
    if ($contrato_id <= 0) {
      return ['payload' => [], 'aditivos' => [], 'reajustes' => [], 'medicoes' => []];
    }

    $whereFiscal = coh_where_mesmo_fiscal($conn);

    // não exibir itens já dispensados por este fiscal (se existir)
    $dismiss = [];
    if (coh_col_exists($conn, 'coordenador_inbox', 'dismissed_by_cpf')) {
      $cpf = $conn->real_escape_string((string)($_SESSION['cpf'] ?? ''));
      if ($cpf !== '') {
        $dismiss[] = "COALESCE(a.dismissed_by_cpf,'') <> '{$cpf}'";
      }
    }
    if (coh_col_exists($conn, 'coordenador_inbox', 'dismissed_by_id')) {
      $uid = (int)($_SESSION['user_id'] ?? 0);
      if ($uid > 0) {
        $dismiss[] = "(a.dismissed_by_id IS NULL OR a.dismissed_by_id <> {$uid})";
      }
    }
    if (coh_col_exists($conn, 'coordenador_inbox', 'dismissed_by')) {
      $cpf = $conn->real_escape_string((string)($_SESSION['cpf'] ?? ''));
      if ($cpf !== '') {
        $dismiss[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dismissed_by, '$.cpf')),'') <> '{$cpf}'";
      }
    }
    $dismissSql = $dismiss ? (' AND ' . implode(' AND ', $dismiss)) : '';

    $sql = "
      SELECT a.payload_json
      FROM coordenador_inbox a
      WHERE a.contrato_id = {$contrato_id}
        AND UPPER(a.status) IN ('REJEITADO','REVISAO_SOLICITADA')
        AND {$whereFiscal}
        {$dismissSql}
      ORDER BY a.processed_at DESC, a.id DESC
      LIMIT 1
    ";

    $pl = [];
    if ($rs = $conn->query($sql)) {
      $r = $rs->fetch_assoc();
      $rs->free();
      if ($r && !empty($r['payload_json'])) {
        $tmp = json_decode($r['payload_json'], true);
        if (is_array($tmp)) $pl = $tmp;
      }
    }

    // normalizações para listas
    $medicoes = [];
    $aditivos = [];
    $reajustes = [];

    if (!empty($pl['novas_medicoes']) && is_array($pl['novas_medicoes'])) {
      foreach ($pl['novas_medicoes'] as $m) {
        if (!is_array($m)) continue;
        $medicoes[] = [
          'data'       => $m['data'] ?? ($m['data_medicao'] ?? ''),
          'valor_rs'   => $m['valor_rs'] ?? ($m['valor'] ?? ''),
          'acumulado'  => $m['acumulado_rs'] ?? '',
          'percentual' => $m['percentual'] ?? '',
          'obs'        => $m['obs'] ?? ($m['observacao'] ?? ''),
        ];
      }
    }

    if (!empty($pl['novos_aditivos']) && is_array($pl['novos_aditivos'])) {
      foreach ($pl['novos_aditivos'] as $a) {
        if (!is_array($a)) continue;
        $aditivos[] = [
          'numero'           => $a['numero_aditivo'] ?? '',
          'data'             => $a['data'] ?? '',
          'tipo'             => $a['tipo'] ?? '',
          'valor_total'      => $a['valor_aditivo_total'] ?? '',
          'valor_total_apos' => $a['valor_total_apos_aditivo'] ?? '',
          'obs'              => $a['observacao'] ?? '',
        ];
      }
    }

    if (!empty($pl['novos_reajustes']) && is_array($pl['novos_reajustes'])) {
      foreach ($pl['novos_reajustes'] as $rj) {
        if (!is_array($rj)) continue;
        $reajustes[] = [
          'indice'     => $rj['indice'] ?? '',
          'percentual' => $rj['percentual'] ?? '',
          'data_base'  => $rj['data_base'] ?? '',
          'valor_apos' => $rj['valor_total_apos_reajuste'] ?? '',
          'obs'        => $rj['observacao'] ?? '',
        ];
      }
    }

    return ['payload' => $pl, 'medicoes' => $medicoes, 'aditivos' => $aditivos, 'reajustes' => $reajustes];
  }
}

/** Constrói SET de campos a revisar, resolvendo por coluna OU por label */
if (!function_exists('coh_revision_fields_for_contract')) {
  function coh_revision_fields_for_contract(mysqli $conn = null, int $contrato_id = 0): array {
    if (!$conn || $contrato_id <= 0) return [];

    $last = coh_inbox_last_payload_for_contract($conn, $contrato_id);
    $p    = $last['payload'] ?? [];
    $set  = [];

    // 1) payload['campos'] como mapa coluna=>valor
    if (isset($p['campos']) && is_array($p['campos'])) {
      if (array_values($p['campos']) !== $p['campos']) {
        foreach ($p['campos'] as $k => $_) {
          $canon = coh_resolve_canon((string)$k);
          if ($canon) $set[$canon] = true;
        }
      } else {
        // ['campo'=>'X', 'label'=>'Y'] como array
        foreach ($p['campos'] as $item) {
          if (!is_array($item)) continue;
          $canon = null;
          if (isset($item['campo'])) {
            $canon = coh_resolve_canon((string)$item['campo']);
          }
          if (!$canon && isset($item['label'])) {
            $canon = coh_resolve_canon((string)$item['label']);
          }
          if ($canon) $set[$canon] = true;
        }
      }
    }

    // 2) changes/alteracoes/differences (array)
    foreach (['changes', 'alteracoes', 'differences'] as $k) {
      if (empty($p[$k]) || !is_array($p[$k])) continue;
      foreach ($p[$k] as $it) {
        if (!is_array($it)) continue;
        $canon = null;
        if (isset($it['campo'])) {
          $canon = coh_resolve_canon((string)$it['campo']);
        }
        if (!$canon && isset($it['label'])) {
          $canon = coh_resolve_canon((string)$it['label']);
        }
        if ($canon) $set[$canon] = true;
      }
    }

    return $set; // chaves = names canônicos do formulário
  }
}

/* ===== Estado de revisão atual ===== */
$__revSet = [];
$__cid    = (int)($id ?? ($row['id'] ?? 0));
if (isset($conn) && $conn instanceof mysqli) {
  $__revSet = coh_revision_fields_for_contract($conn, $__cid);
}

if (!function_exists('coh_has_rev')) {
  function coh_has_rev(string $name) {
    global $__revSet;
    return isset($__revSet[$name]);
  }
}

if (!function_exists('coh_badge_rev')) {
  function coh_badge_rev(string $name) {
    return coh_has_rev($name) ? ' <span class="badge text-bg-warning">revisar</span>' : '';
  }
}
?>

<style>
  .coh-rev {
    border: 1px dashed #f59e0b;
    border-radius: 10px;
    padding: 6px;
  }
  .coh-rev .form-control,
  .coh-rev .form-select,
  .coh-rev textarea {
    background: #fff8e1 !important;
    border-color: #facc15 !important;
  }
  .coh-rev .form-label {
    font-weight: 700;
    color: #8a6116;
  }

  .coh-saldo-atualizado {
    border: 2px solid #198754;
    border-radius: 12px;
    background: #e9f7ef;
  }
  .coh-saldo-atualizado .form-label {
    font-weight: 700;
  }
  .coh-saldo-atualizado .form-control {
    font-size: 1.3rem;
    font-weight: 700;
  }
</style>

<div style="display:none">
  <input type="hidden" name="novas_medicoes"  id="novas_medicoes">
  <input type="hidden" name="novos_aditivos"  id="novos_aditivos">
  <input type="hidden" name="novos_reajustes" id="novos_reajustes">
  <input type="hidden" id="novas_medicoes_json">
  <input type="hidden" id="novos_aditivos_json">
  <input type="hidden" id="novos_reajustes_json">
</div>

<div class="card mb-4 sec--ficha" id="sec-ficha">
  <div class="sec-title">
    <span class="dot"></span>
    <?= $is_new ? 'Cadastro de Contrato (novo)' : 'Dados Gerais' ?>
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-3">
        <label class="form-label coh-label">ID</label>
        <input class="form-control" value="<?= e($is_new ? 'novo' : $id) ?>" readonly>
      </div>

      <div class="col-md-3 <?= coh_has_rev('Processo_SEI')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Processo (SEI)<?= coh_badge_rev('Processo_SEI') ?>
        </label>
        <input class="form-control" name="Processo_SEI" value="<?= e($row['Processo_SEI'] ?? '') ?>">
      </div>

      <div class="col-md-6 <?= coh_has_rev('Secretaria')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Órgão Demandante<?= coh_badge_rev('Secretaria') ?>
        </label>
        <input class="form-control" name="Secretaria" value="<?= e($row['Secretaria'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Diretoria')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Diretoria<?= coh_badge_rev('Diretoria') ?>
        </label>
        <input list="lista_diretoria" class="form-control" name="Diretoria" value="<?= e($row['Diretoria'] ?? '') ?>">
        <datalist id="lista_diretoria">
          <option>DIRIM</option>
          <option>DIROB</option>
          <option>DIRPP</option>
        </datalist>
      </div>

      <div class="col-md-3 <?= coh_has_rev('No_do_Contrato')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Nº do Contrato<?= coh_badge_rev('No_do_Contrato') ?>
        </label>
        <input class="form-control" name="No_do_Contrato" value="<?= e($row['No_do_Contrato'] ?? '') ?>">
      </div>

        <div class="col-md-3 <?= coh_has_rev('Valor_Do_Contrato')?'coh-rev':'' ?>">
          <label class="form-label coh-label">
            Valor do Contrato (R$)<?= coh_badge_rev('Valor_Do_Contrato') ?>
          </label>
          <input
            type="text"
            inputmode="decimal"
            class="form-control"
            name="Valor_Do_Contrato"
            value="<?= br_money($row['Valor_Do_Contrato'] ?? '') ?>"
          >
        </div>

      <div class="col-md-3 <?= coh_has_rev('Assinatura_Do_Contrato_Data')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Assinatura do Contrato<?= coh_badge_rev('Assinatura_Do_Contrato_Data') ?>
        </label>
        <input class="form-control date-br" name="Assinatura_Do_Contrato_Data" value="<?= br_date($row['Assinatura_Do_Contrato_Data'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Status')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Status<?= coh_badge_rev('Status') ?>
        </label>
        <input list="lista_status" class="form-control" name="Status" value="<?= e($row['Status'] ?? '') ?>">
        <datalist id="lista_status">
          <option>EM EXECUÇÃO</option>
          <option>SUSPENSO</option>
          <option>CONCLUÍDO</option>
          <option>RESCINDIDO</option>
        </datalist>
      </div>

      <div class="col-md-3 <?= coh_has_rev('Tipo')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Tipo<?= coh_badge_rev('Tipo') ?>
        </label>
        <input class="form-control" name="Tipo" value="<?= e($row['Tipo'] ?? '') ?>">
      </div>

      <div class="col-md-6 <?= coh_has_rev('Fonte_De_Recursos')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Fonte de Recursos<?= coh_badge_rev('Fonte_De_Recursos') ?>
        </label>
        <input class="form-control" name="Fonte_De_Recursos" value="<?= e($row['Fonte_De_Recursos'] ?? '') ?>">
      </div>
    </div>
  </div>
</div>

<div class="card mb-4 sec--licit" id="sec-licit">
  <div class="sec-title">
    <span class="dot"></span> Procedimento Licitatório
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-12 <?= coh_has_rev('Procedimento_Licitatorio')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Procedimento Licitatório<?= coh_badge_rev('Procedimento_Licitatorio') ?>
        </label>
        <input class="form-control" name="Procedimento_Licitatorio" value="<?= e($row['Procedimento_Licitatorio'] ?? '') ?>">
      </div>
      </div>
  </div>
</div>

<div class="card mb-4 sec--dados" id="sec-dados">
  <div class="sec-title">
    <span class="dot"></span> Dados da Obra
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-3 <?= coh_has_rev('Estado')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Estado<?= coh_badge_rev('Estado') ?>
        </label>
        <input class="form-control" name="Estado" value="<?= e($row['Estado'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Municipio')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Município<?= coh_badge_rev('Municipio') ?>
        </label>
        <input class="form-control" name="Municipio" value="<?= e($row['Municipio'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Bairro')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Bairro<?= coh_badge_rev('Bairro') ?>
        </label>
        <input class="form-control" name="Bairro" value="<?= e($row['Bairro'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Regiao')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Mesorregião<?= coh_badge_rev('Regiao') ?>
        </label>
        <input class="form-control" name="Regiao" value="<?= e($row['Regiao'] ?? '') ?>">
      </div>

      <div class="col-md-6 <?= coh_has_rev('Gestor_Obra')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Gestor da Obra<?= coh_badge_rev('Gestor_Obra') ?>
        </label>
        <input class="form-control" name="Gestor_Obra" value="<?= e($row['Gestor_Obra'] ?? '') ?>">
      </div>

      <div class="col-md-6 <?= coh_has_rev('Objeto_Da_Obra')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Objeto da Obra<?= coh_badge_rev('Objeto_Da_Obra') ?>
        </label>
        <textarea class="form-control" name="Objeto_Da_Obra" rows="2"><?= e($row['Objeto_Da_Obra'] ?? '') ?></textarea>
      </div>

      <div class="col-md-4 <?= coh_has_rev('Setor_EMOP')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Setor EMOP<?= coh_badge_rev('Setor_EMOP') ?>
        </label>
        <input class="form-control" name="Setor_EMOP" value="<?= e($row['Setor_EMOP'] ?? '') ?>">
      </div>

      <div class="col-md-4 <?= coh_has_rev('Responsavel_Fiscal')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Fiscal Responsável<?= coh_badge_rev('Responsavel_Fiscal') ?>
        </label>
        <input class="form-control" name="Responsavel_Fiscal" value="<?= e($row['Responsavel_Fiscal'] ?? '') ?>">
      </div>

      <div class="col-md-4 <?= coh_has_rev('Fiscal_2')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Fiscal 2<?= coh_badge_rev('Fiscal_2') ?>
        </label>
        <input class="form-control" name="Fiscal_2" value="<?= e($row['Fiscal_2'] ?? '') ?>">
      </div>

      <div class="col-md-6 <?= coh_has_rev('Empresa')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Empresa<?= coh_badge_rev('Empresa') ?>
        </label>
        <input class="form-control" name="Empresa" value="<?= e($row['Empresa'] ?? '') ?>">
      </div>
    </div>
  </div>
</div>

<div class="card mb-4 sec--prazo" id="sec-prazo">
  <div class="sec-title">
    <span class="dot"></span> Prazo da Obra
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-3 <?= coh_has_rev('Data_Inicio')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Data de Início<?= coh_badge_rev('Data_Inicio') ?>
        </label>
        <input class="form-control date-br" name="Data_Inicio" value="<?= br_date($row['Data_Inicio'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Prazo_Obra_Ou_Projeto')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Prazo (dias)<?= coh_badge_rev('Prazo_Obra_Ou_Projeto') ?>
        </label>
        <input type="number" class="form-control" name="Prazo_Obra_Ou_Projeto" value="<?= e($row['Prazo_Obra_Ou_Projeto'] ?? '') ?>">
      </div>

      <div class="col-md-3 <?= coh_has_rev('Data_Fim_Prevista')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Término Previsto<?= coh_badge_rev('Data_Fim_Prevista') ?>
        </label>
        <input class="form-control date-br" name="Data_Fim_Prevista" value="<?= br_date($row['Data_Fim_Prevista'] ?? '') ?>">
      </div>
    </div>
  </div>
</div>

<div class="card mb-4 sec--adit" id="sec-adit">
  <div class="sec-title">
    <span class="dot"></span> Aditivos
  </div>
  <div class="card-body">
    <?php
      if (isset($conn) && $conn instanceof mysqli) {
        $__cid  = (int)($id ?? ($row['id'] ?? 0));
        $__pend = coh_inbox_last_payload_for_contract($conn, $__cid);
        if (!empty($__pend['aditivos'])) {
          ?>
          <div class="alert alert-warning border-warning-subtle bg-warning-subtle mb-3">
            <div class="d-flex align-items-center mb-2" style="gap:.5rem;">
              <i class="bi bi-clipboard2-pulse"></i>
              <strong>Seus aditivos pendentes para revisão</strong>
              <span class="badge text-bg-warning ms-1">em revisão</span>
            </div>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Nº</th>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Valor do Aditivo</th>
                    <th>Valor Total Após</th>
                    <th>Obs</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($__pend['aditivos'] as $a): ?>
                    <tr>
                      <td><?= e((string)($a['numero'] ?? '')) ?></td>
                      <td><?= e((string)($a['data'] ?? '')) ?></td>
                      <td><?= e((string)($a['tipo'] ?? '')) ?></td>
                      <td><?= e((string)($a['valor_total'] ?? '')) ?></td>
                      <td><?= e((string)($a['valor_total_apos'] ?? '')) ?></td>
                      <td><?= e((string)($a['obs'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php
        }
      }

      $path_adit = __DIR__ . '/form_emop_contratos_aditivos.php';
      if (file_exists($path_adit)) {
        require $path_adit;
      } else {
        echo '<div class="text-muted">Partial de Aditivos não encontrado.</div>';
      }
    ?>
    <div class="mt-2 small text-secondary" data-coh-preview="adit"></div>
  </div>
</div>

<div class="card mb-4 sec--reaj" id="sec-reaj">
  <div class="sec-title">
    <span class="dot"></span> Reajustamento
  </div>
  <div class="card-body">
    <?php
      if (isset($conn) && $conn instanceof mysqli) {
        $__cid  = (int)($id ?? ($row['id'] ?? 0));
        $__pend = isset($__pend) ? $__pend : coh_inbox_last_payload_for_contract($conn, $__cid);
        if (!empty($__pend['reajustes'])) {
          ?>
          <div class="alert alert-warning border-warning-subtle bg-warning-subtle mb-3">
            <div class="d-flex align-items-center mb-2" style="gap:.5rem;">
              <i class="bi bi-clipboard2-pulse"></i>
              <strong>Seus reajustes pendentes para revisão</strong>
              <span class="badge text-bg-warning ms-1">em revisão</span>
            </div>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Índice</th>
                    <th>%</th>
                    <th>Data-base</th>
                    <th>Valor Total Após Reajuste</th>
                    <th>Obs</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($__pend['reajustes'] as $rj): ?>
                    <tr>
                      <td><?= e((string)($rj['indice'] ?? '')) ?></td>
                      <td><?= e((string)($rj['percentual'] ?? '')) ?></td>
                      <td><?= e((string)($rj['data_base'] ?? '')) ?></td>
                      <td><?= e((string)($rj['valor_apos'] ?? '')) ?></td>
                      <td><?= e((string)($rj['obs'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php
        }
      }

      $path_reaj = __DIR__ . '/form_emop_contratos_reajustes.php';
      if (file_exists($path_reaj)) {
        require $path_reaj;
      } else {
        echo '<div class="text-muted">Partial de Reajustes não encontrado.</div>';
      }
    ?>
    <div class="mt-2 small text-secondary" data-coh-preview="reaj"></div>
  </div>
</div>

<?php
// =================== CÁLCULO PHP: VALOR TOTAL ATUALIZADO DO CONTRATO ===================

if (!function_exists('coh_parse_brl_num')) {
  function coh_parse_brl_num($v): float {
    if ($v === null) return 0.0;
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    $v = str_replace(['R$', ' '], '', $v);
    // formato BR: 1.234.567,89
    if (preg_match('/,\d{1,2}$/', $v)) {
      $v = str_replace('.', '', $v);
      $v = str_replace(',', '.', $v);
    }
    if (!is_numeric($v)) return 0.0;
    return (float)$v;
  }
}

$__valorAtualizadoContratoNum  = 0.0;
$__valorAtualizadoContrato     = '';
$__saldoAtualizadoContratoNum  = 0.0;
$__saldoAtualizadoContrato     = '';
$__percAnt  = '';
$__percMed  = '';
$__percAcum = '';

$contratoId = (int)($row['id'] ?? $id ?? 0);

if (!empty($row) && $contratoId > 0 && isset($conn) && $conn instanceof mysqli) {

  // Valor base do contrato (emop_contratos)
  $v_inicial  = coh_parse_brl_num($row['Valor_Do_Contrato']         ?? 0);
  $v_liq_acum = coh_parse_brl_num($row['Valor_Liquidado_Acumulado'] ?? 0);

  // ===== Último valor acumulado dos ADITIVOS (valor_total_apos_aditivo) =====
  $valorAposAditivo = 0.0;
  $dataAditivo      = null;
  if ($stA = $conn->prepare("
        SELECT valor_total_apos_aditivo, created_at
        FROM emop_aditivos
        WHERE contrato_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1
      ")) {
    $stA->bind_param('i', $contratoId);
    $stA->execute();
    if ($resA = $stA->get_result()) {
      if ($ra = $resA->fetch_assoc()) {
        $valorAposAditivo = (float)($ra['valor_total_apos_aditivo'] ?? 0);
        $dataAditivo      = $ra['created_at'];
      }
    }
    $stA->close();
  }

  // ===== Último valor acumulado dos REAJUSTES (valor_total_apos_reajuste) =====
  $valorAposReajuste = 0.0;
  $dataReajuste      = null;
  if ($stR = $conn->prepare("
        SELECT valor_total_apos_reajuste, created_at
        FROM emop_reajustamento
        WHERE contrato_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1
      ")) {
    $stR->bind_param('i', $contratoId);
    $stR->execute();
    if ($resR = $stR->get_result()) {
      if ($rr = $resR->fetch_assoc()) {
        $valorAposReajuste = (float)($rr['valor_total_apos_reajuste'] ?? 0);
        $dataReajuste      = $rr['created_at'];
      }
    }
    $stR->close();
  }

  // ===== Regra do Valor Total Atualizado (CORRIGIDO: Compara datas) =====
  // 1) Começa com o valor inicial
  $__valorAtualizadoContratoNum = $v_inicial;
  $ultimaData = '0000-00-00 00:00:00';

  // 2) Verifica Aditivo
  if ($valorAposAditivo > 0 && $dataAditivo) {
      $__valorAtualizadoContratoNum = $valorAposAditivo;
      $ultimaData = $dataAditivo;
  }

  // 3) Verifica Reajuste (sobrepõe se for mais recente)
  if ($valorAposReajuste > 0 && $dataReajuste) {
      if ($dataReajuste > $ultimaData) {
          $__valorAtualizadoContratoNum = $valorAposReajuste;
      }
  }

  // ===== Formata Valor Total Atualizado e Saldo =====
  if ($__valorAtualizadoContratoNum > 0) {
    if (function_exists('br_money')) {
      $__valorAtualizadoContrato = br_money($__valorAtualizadoContratoNum);
    } else {
      $__valorAtualizadoContrato = number_format($__valorAtualizadoContratoNum, 2, ',', '.');
    }

    // Saldo atualizado = valor total atualizado - liquidado acumulado
    $__saldoAtualizadoContratoNum = $__valorAtualizadoContratoNum - $v_liq_acum;

    if (function_exists('br_money')) {
      $__saldoAtualizadoContrato = br_money($__saldoAtualizadoContratoNum);
    } else {
      $__saldoAtualizadoContrato = number_format($__saldoAtualizadoContratoNum, 2, ',', '.');
    }
  }

  // ===== Percentuais dos campos do saldo em relação ao valor total =====
  if ($__valorAtualizadoContratoNum > 0) {
    $v_ant  = coh_parse_brl_num($row['Medicao_Anterior_Acumulada_RS'] ?? 0);
    $v_med  = coh_parse_brl_num($row['Valor_Liquidado_Na_Medicao_RS'] ?? 0);
    $v_acum = coh_parse_brl_num($row['Valor_Liquidado_Acumulado']     ?? 0);

    if ($v_ant > 0) {
      $__percAnt = number_format(($v_ant / $__valorAtualizadoContratoNum) * 100, 2, ',', '.')
                   . '% do contrato';
    }
    if ($v_med > 0) {
      $__percMed = number_format(($v_med / $__valorAtualizadoContratoNum) * 100, 2, ',', '.')
                   . '% do contrato';
    }
    if ($v_acum > 0) {
      $__percAcum = number_format(($v_acum / $__valorAtualizadoContratoNum) * 100, 2, ',', '.')
                    . '% do contrato';
    }
  }
}
?>


<div class="card mb-4 sec--saldo" id="sec-saldo">
  <div class="sec-title"><span class="dot"></span> Saldo Contratual</div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-3 <?= coh_has_rev('Medicao_Anterior_Acumulada_RS')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Liq. Acum. Anterior (R$)<?= coh_badge_rev('Medicao_Anterior_Acumulada_RS') ?>
        </label>
        <input
          class="form-control brl"
          name="Medicao_Anterior_Acumulada_RS"
          value="<?= br_money($row['Medicao_Anterior_Acumulada_RS'] ?? '') ?>"
        >
        <div class="form-text text-muted coh-saldo-perc" data-coh-perc="anterior">
          <?= e($__percAnt) ?>
        </div>
      </div>

      <div class="col-md-3 <?= coh_has_rev('Valor_Liquidado_Na_Medicao_RS')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Valor Liquidado na Medição (R$)<?= coh_badge_rev('Valor_Liquidado_Na_Medicao_RS') ?>
        </label>
        <input
          class="form-control brl"
          name="Valor_Liquidado_Na_Medicao_RS"
          value="<?= br_money($row['Valor_Liquidado_Na_Medicao_RS'] ?? '') ?>"
        >
        <div class="form-text text-muted coh-saldo-perc" data-coh-perc="medicao">
          <?= e($__percMed) ?>
        </div>
      </div>

      <div class="col-md-3 <?= coh_has_rev('Valor_Liquidado_Acumulado')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Liquidado Acumulado (R$)<?= coh_badge_rev('Valor_Liquidado_Acumulado') ?>
        </label>
        <input
          class="form-control brl"
          name="Valor_Liquidado_Acumulado"
          value="<?= br_money($row['Valor_Liquidado_Acumulado'] ?? '') ?>"
        >
        <div class="form-text text-muted coh-saldo-perc" data-coh-perc="acumulado">
          <?= e($__percAcum) ?>
        </div>
      </div>

      <div class="col-md-3 <?= coh_has_rev('Percentual_Executado')?'coh-rev':'' ?>">
        <label class="form-label coh-label">
          Percentual Executado (%)<?= coh_badge_rev('Percentual_Executado') ?>
        </label>
        <input
          class="form-control"
          name="Percentual_Executado"
          value="<?= e($row['Percentual_Executado'] ?? '') ?>"
          readonly
        >
      </div>
    </div>

    <div class="row mt-4 g-4 justify-content-center">
      <div class="col-md-6 col-lg-5">
        <div class="p-3 coh-saldo-atualizado text-center">
          <label class="form-label coh-label mb-1 w-100 text-center">
            Valor Total Atualizado do Contrato (R$)
          </label>
          <input
            class="form-control brl text-center"
            name="Valor_Total_Atualizado_Contrato"
            value="<?= e($__valorAtualizadoContrato) ?>"
            readonly
          >
          <div class="small text-muted mt-1">
            Calculado a partir do valor inicial do contrato, aditivos e reajustes.
          </div>
        </div>
      </div>

      <div class="col-md-6 col-lg-5">
        <div class="p-3 coh-saldo-atualizado text-center">
          <label class="form-label coh-label mb-1 w-100 text-center">
            Saldo Atualizado do Contrato (R$)
          </label>
          <input
            class="form-control brl text-center"
            name="Saldo_Atualizado_Contrato"
            value="<?= e($__saldoAtualizadoContrato) ?>"
            readonly
          >
          <div class="small text-muted mt-1">
            Valor total atualizado do contrato menos o Liquidado Acumulado.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<div class="card mb-4 sec--med" id="sec-med">
  <div class="sec-title">
    <span class="dot"></span> Medições
  </div>
  <div class="card-body">
    <?php
      if (isset($conn) && $conn instanceof mysqli) {
        $__cid  = (int)($id ?? ($row['id'] ?? 0));
        $__pend = isset($__pend) ? $__pend : coh_inbox_last_payload_for_contract($conn, $__cid);
        if (!empty($__pend['medicoes'])) {
          ?>
          <div class="alert alert-warning border-warning-subtle bg-warning-subtle mb-3">
            <div class="d-flex align-items-center mb-2" style="gap:.5rem;">
              <i class="bi bi-clipboard2-pulse"></i>
              <strong>Suas medições pendentes para revisão</strong>
              <span class="badge text-bg-warning ms-1">em revisão</span>
            </div>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Valor (R$)</th>
                    <th>Acumulado (R$)</th>
                    <th>%</th>
                    <th>Obs</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($__pend['medicoes'] as $m): ?>
                    <tr>
                      <td><?= e((string)($m['data'] ?? '')) ?></td>
                      <td><?= e((string)($m['valor_rs'] ?? '')) ?></td>
                      <td><?= e((string)($m['acumulado'] ?? '')) ?></td>
                      <td><?= e((string)($m['percentual'] ?? '')) ?></td>
                      <td><?= e((string)($m['obs'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php
        }
      }

      include __DIR__ . '/form_emop_contratos_medicoes.php';
    ?>
    <div class="mt-2 small text-secondary" data-coh-preview="med"></div>
  </div>
</div>

<script>
(function(){
  function parseBRL(str) {
    if (!str) return NaN;
    str = String(str).replace(/[^\d.,-]/g, '').trim();
    if (!str) return NaN;

    if (str.indexOf(',') !== -1) {
      // Formato típico BR: milhar com ponto, decimal com vírgula
      str = str.replace(/\./g, '').replace(',', '.');
    } else {
      // Sem vírgula; se tiver vários pontos, só o último é decimal
      const parts = str.split('.');
      if (parts.length > 2) {
        const last = parts.pop();
        str = parts.join('') + '.' + last;
      }
    }
    const n = parseFloat(str);
    return isNaN(n) ? NaN : n;
  }

  function formatBRL(num) {
    if (typeof num !== 'number' || !isFinite(num)) return '';
    const sign = num < 0 ? '-' : '';
    num = Math.abs(num);
    const fixed = num.toFixed(2);
    let parts = fixed.split('.');
    let intPart = parts[0];
    const decPart = parts[1];
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return sign + intPart + ',' + decPart;
  }

  // Calcula e atualiza as porcentagens abaixo de cada campo do saldo
  function atualizarPercentuaisSaldo() {
    const totalInp = document.querySelector('input[name="Valor_Total_Atualizado_Contrato"]');
    const totalNum = parseBRL(totalInp ? totalInp.value : '');
    const percEls  = document.querySelectorAll('.coh-saldo-perc');

    if (!totalInp || isNaN(totalNum) || totalNum <= 0) {
      percEls.forEach(function(el){ el.textContent = ''; });
      return;
    }

    function setPerc(inputName, key) {
      const inp  = document.querySelector('input[name="'+inputName+'"]');
      const span = document.querySelector('.coh-saldo-perc[data-coh-perc="'+key+'"]');
      if (!span) return;

      const val = parseBRL(inp ? inp.value : '');
      if (isNaN(val) || val <= 0) {
        span.textContent = '';
        return;
      }
      const pct = (val / totalNum) * 100;
      span.textContent = pct.toFixed(2).replace('.', ',') + '% do contrato';
    }

    setPerc('Medicao_Anterior_Acumulada_RS', 'anterior');
    setPerc('Valor_Liquidado_Na_Medicao_RS', 'medicao');
    setPerc('Valor_Liquidado_Acumulado',     'acumulado');
  }

  // Atualiza campos do bloco de Saldo com base na última medição
  function atualizarSaldoPorMedicoes() {
    const secMed = document.getElementById('sec-med');
    if (!secMed) return;

    const table = secMed.querySelector('table');
    const tbody = table ? table.querySelector('tbody') : null;
    if (!table || !tbody) return;

    const rows = tbody.querySelectorAll('tr');
    if (!rows.length) return;

    // Descobre índices das colunas "Valor (R$)", "Acumulado (R$)" e "%"
    const headCells = table.querySelectorAll('thead tr th');
    let idxValor = -1, idxAcum = -1, idxPerc = -1;
    headCells.forEach(function(th, idx){
      const txt = th.textContent.toLowerCase();
      if (idxValor === -1 && txt.includes('valor') && txt.includes('r$')) idxValor = idx;
      if (idxAcum  === -1 && (txt.includes('acumulado') || txt.includes('acum.'))) idxAcum = idx;
      if (idxPerc  === -1 && (txt.includes('%') || txt.includes('percent'))) idxPerc = idx;
    });
    if (idxValor === -1) idxValor = 1;
    if (idxAcum  === -1) idxAcum  = 2;
    if (idxPerc  === -1) idxPerc  = 3;

    const lastRow = rows[rows.length - 1];
    const tds = lastRow.querySelectorAll('td');
    if (tds.length === 0) return;

    const txtValor = tds[idxValor] ? tds[idxValor].textContent.trim() : '';
    const txtAcum  = tds[idxAcum]  ? tds[idxAcum].textContent.trim()  : '';
    const txtPerc  = tds[idxPerc]  ? tds[idxPerc].textContent.trim()  : '';

    const vValor = parseBRL(txtValor);
    const vAcum  = parseBRL(txtAcum);
    const vAnt   = (!isNaN(vValor) && !isNaN(vAcum)) ? (vAcum - vValor) : NaN;

    const inpAnt  = document.querySelector('input[name="Medicao_Anterior_Acumulada_RS"]');
    const inpVal  = document.querySelector('input[name="Valor_Liquidado_Na_Medicao_RS"]');
    const inpAcum = document.querySelector('input[name="Valor_Liquidado_Acumulado"]');
    const inpPerc = document.querySelector('input[name="Percentual_Executado"]');

    if (inpVal && txtValor) {
      inpVal.value = txtValor;
    }
    if (inpAcum && txtAcum) {
      inpAcum.value = txtAcum;
    }
    if (inpAnt && !isNaN(vAnt)) {
      inpAnt.value = formatBRL(vAnt);
    }
    if (inpPerc && txtPerc) {
      inpPerc.value = txtPerc;
    }

    // sempre recalcula o saldo / total / percentuais quando mexer em medições
    atualizarValorTotalContrato();
  }

  // Calcula "Valor Total Atualizado do Contrato" e "Saldo Atualizado"
  function atualizarValorTotalContrato() {
    const outTotal = document.querySelector('input[name="Valor_Total_Atualizado_Contrato"]');
    const outSaldo = document.querySelector('input[name="Saldo_Atualizado_Contrato"]');
    const inpLiqAcum = document.querySelector('input[name="Valor_Liquidado_Acumulado"]');
    
    if (!outTotal) return;

    // 1. Tenta pegar o valor base calculado pelo PHP na inicialização (via defaultValue)
    // Se falhar, tenta pegar do input de valor do contrato.
    const inpValorInicial = document.querySelector('input[name="Valor_Do_Contrato"]');
    let baseVal = parseBRL(outTotal.defaultValue); 
    if (isNaN(baseVal)) baseVal = parseBRL(inpValorInicial ? inpValorInicial.value : 0);

    let currentTotal = baseVal;

    // 2. Verifica se há novos itens no Rascunho (Client-side)
    // Se existirem, eles assumem precedência sobre o valor do banco.
    if (window.COH && window.COH.draft) {
        const ads = window.COH.draft.aditivos || [];
        const rjs = window.COH.draft.reajustes || [];
        
        let lastAditivoVal = null;
        if (ads.length > 0) {
            const last = ads[ads.length - 1];
            lastAditivoVal = parseBRL(last.valor_total_apos_aditivo);
        }

        let lastReajusteVal = null;
        if (rjs.length > 0) {
            const last = rjs[rjs.length - 1];
            lastReajusteVal = parseBRL(last.valor_total_apos_reajuste);
        }

        // Se houver novos reajustes, assume-se que são os mais recentes
        // Se não, usa o último aditivo novo.
        if (lastReajusteVal !== null && !isNaN(lastReajusteVal)) {
            currentTotal = lastReajusteVal;
        } else if (lastAditivoVal !== null && !isNaN(lastAditivoVal)) {
            currentTotal = lastAditivoVal;
        }
    }

    // 3. Atualiza os inputs na tela
    outTotal.value = formatBRL(currentTotal);

    if (outSaldo) {
        let vLiq = parseBRL(inpLiqAcum ? inpLiqAcum.value : 0);
        if (isNaN(vLiq)) vLiq = 0;
        const saldo = currentTotal - vLiq;
        outSaldo.value = formatBRL(saldo);
    }
    
    atualizarPercentuaisSaldo();
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Atualiza no carregamento
    atualizarSaldoPorMedicoes();
    atualizarValorTotalContrato();
    atualizarPercentuaisSaldo();

    // === MÁSCARA LEVE PARA "Valor do Contrato (R$)" ===
    const inpValorContrato = document.querySelector('input[name="Valor_Do_Contrato"]');
    if (inpValorContrato) {
      // Formata valor inicial, se vier do PHP já preenchido
      const iniNum = parseBRL(inpValorContrato.value);
      if (!isNaN(iniNum) && iniNum > 0) {
        inpValorContrato.value = 'R$ ' + formatBRL(iniNum);
      }

      // Ao focar: tira R$, pontos e espaços pra facilitar digitação
      inpValorContrato.addEventListener('focus', function () {
        let v = this.value || '';
        v = v.replace(/\s/g, '');      // tira espaços
        v = v.replace(/^R\$\s?/, '');  // tira "R$"
        v = v.replace(/\./g, '');      // tira separador de milhar
        // mantém vírgula se tiver
        this.value = v;
      });

      // Ao sair: aplica R$, pontos de milhar e vírgula
      inpValorContrato.addEventListener('blur', function () {
        const n = parseBRL(this.value);
        if (isNaN(n) || !this.value.trim()) {
          this.value = '';
          atualizarValorTotalContrato();
          return;
        }
        this.value = 'R$ ' + formatBRL(n);
        atualizarValorTotalContrato(); // recalc total + saldo + %
      });
    }

    // Observa mudanças na tabela de medições (novas linhas, etc.)
    const secMed = document.getElementById('sec-med');
    const tbody = secMed ? secMed.querySelector('table tbody') : null;
    if (tbody && 'MutationObserver' in window) {
      const obs = new MutationObserver(function(){
        atualizarSaldoPorMedicoes();
      });
      obs.observe(tbody, { childList: true, subtree: false });
    }

    // Reage caso algum script altere o JSON de medições
    const jsonHiddenMed = document.getElementById('novas_medicoes_json');
    if (jsonHiddenMed) {
      ['change','input'].forEach(function(ev){
        jsonHiddenMed.addEventListener(ev, atualizarSaldoPorMedicoes);
      });
    }

    // Escuta alterações nos JSONs de Aditivos e Reajustes (disparados pelos Modais)
    const jsonHiddenAdit = document.getElementById('novos_aditivos_json');
    if (jsonHiddenAdit) {
      ['change','input'].forEach(function(ev){
        jsonHiddenAdit.addEventListener(ev, atualizarValorTotalContrato);
      });
    }
    const jsonHiddenReaj = document.getElementById('novos_reajustes_json');
    if (jsonHiddenReaj) {
      ['change','input'].forEach(function(ev){
        jsonHiddenReaj.addEventListener(ev, atualizarValorTotalContrato);
      });
    }

    // Listeners para recálculo do valor atualizado do contrato, saldo e percentuais
    const camposValor = [
      'Valor_Do_Contrato',
      'Valor_Liquidado_Acumulado',
      'Medicao_Anterior_Acumulada_RS',
      'Valor_Liquidado_Na_Medicao_RS'
    ];
    camposValor.forEach(function(name){
      const el = document.querySelector('input[name="'+name+'"]');
      if (el) {
        ['change','input','blur'].forEach(function(ev){
          el.addEventListener(ev, atualizarValorTotalContrato);
        });
      }
    });
  });

  // Disponibiliza função global, caso queira chamar do modal
  window.cohAtualizarSaldoPorMedicoes = atualizarSaldoPorMedicoes;
  window.cohAtualizarValorTotalContrato = atualizarValorTotalContrato;
})();
</script>