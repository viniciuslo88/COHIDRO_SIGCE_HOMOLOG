<?php
// /php/historico_fiscal.php — Histórico do Fiscal (com mesmos estilos do histórico do coordenador)

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/roles.php';

// Fallbacks de roles (se necessário)
defined('ROLE_FISCAL')        || define('ROLE_FISCAL', 1);
defined('ROLE_COORDENADOR')   || define('ROLE_COORDENADOR', 2);
defined('ROLE_DIRETOR')       || define('ROLE_DIRETOR', 3);
defined('ROLE_PRESIDENTE')    || define('ROLE_PRESIDENTE', 4);
defined('ROLE_ADMIN')         || define('ROLE_ADMIN', 5);
defined('ROLE_DESENVOLVEDOR') || define('ROLE_DESENVOLVEDOR', 6);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function column_label_map(){ return [
  'Objeto_Da_Obra'=>'Objeto da Obra','Fonte_De_Recursos'=>'Fonte de Recursos','Aditivo_N'=>'Aditivo Nº','Processo_SEI'=>'Processo SEI',
  'Diretoria'=>'Diretoria','Secretaria'=>'Secretaria','Municipio'=>'Município','Empresa'=>'Empresa','Valor_Do_Contrato'=>'Valor do Contrato',
  'Data_Inicio'=>'Data de Início','Data_Fim_Prevista'=>'Data de Fim Prevista','Status'=>'Status','Observacoes'=>'Observações',
  'Percentual_Executado'=>'% Executado','Valor_Liquidado_Acumulado'=>'Valor Liquidado (Acum.)',
  'Data_Da_Medicao_Atual'=>'Data da Medição Atual','Valor_Liquidado_Na_Medicao_RS'=>'Valor da Medição (R$)',
];}
function prettify_column($col){
  $label = str_replace('_',' ', $col);
  $label = ucwords(strtolower($label));
  $label = str_replace([' Sei',' Rj',' N '],[' SEI',' RJ',' Nº '], $label);
  $label = str_replace(' Nº  ',' Nº ', $label);
  return $label;
}
function column_label($col){ $map = column_label_map(); return $map[$col] ?? prettify_column($col); }
function status_label_ptbr(string $s): string {
  $s = strtoupper(trim($s));
  $map = [
    'REVISAO_SOLICITADA' => 'REVISÃO SOLICITADA',
    'APROVADO'           => 'APROVADO',
    'REJEITADO'          => 'REJEITADO',
    'PENDENTE'           => 'PENDENTE',
  ];
  return $map[$s] ?? $s;
}
function col_exists(mysqli $c, string $t, string $col): bool {
  $t = $c->real_escape_string($t);
  $col = $c->real_escape_string($col);
  if(!$rs = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'")) return false;
  $ok = $rs->num_rows > 0; $rs->free(); return $ok;
}
function table_exists(mysqli $c, string $t): bool {
  $like = $c->real_escape_string($t);
  if(!$rs=$c->query("SHOW TABLES LIKE '{$like}'")) return false;
  $ok = $rs->num_rows>0; $rs->free(); return $ok;
}

/* ===== Acesso ===== */
$role      = (int)($_SESSION['role'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_cpf  = trim((string)($_SESSION['cpf'] ?? ''));
$user_name = trim((string)($_SESSION['nome'] ?? $_SESSION['name'] ?? ''));
$user_dir  = trim((string)($_SESSION['diretoria'] ?? ''));
if ($role < ROLE_FISCAL) { http_response_code(403); echo '<div class="alert alert-danger m-3">Acesso negado.</div>'; exit; }

/* ===== Entradas ===== */
$contrato_id = (int)($_GET['contrato_id'] ?? 0);
$diretoria   = trim((string)($_GET['diretoria'] ?? ''));
if ($diretoria === '') $diretoria = $user_dir;

$de      = trim((string)($_GET['de'] ?? ''));
$ate     = trim((string)($_GET['ate'] ?? ''));
$status  = strtoupper(trim((string)($_GET['status'] ?? 'TODOS'))); // PENDENTE/APROVADO/REJEITADO/REVISAO_SOLICITADA
$q       = trim((string)($_GET['q'] ?? ''));

/* Diretorias (níveis 4+) */
$diretorias_opts = [];
if ($role >= ROLE_DIRETOR) {
  $rs = $conn->query("SELECT DISTINCT Diretoria FROM emop_contratos WHERE Diretoria <> '' ORDER BY Diretoria");
  while ($rs && $r = $rs->fetch_assoc()) $diretorias_opts[] = $r['Diretoria'];
  if ($rs) $rs->free();
}

/* Escopo/título */
if ($contrato_id > 0) {
  $rs = $conn->query("SELECT id, Objeto_Da_Obra AS objeto, Empresa, Diretoria FROM emop_contratos WHERE id={$contrato_id} LIMIT 1");
  $contrato = ($rs && $rs->num_rows)?$rs->fetch_assoc():null; if($rs) $rs->free();
  $scope_title = 'Contrato '.(int)$contrato_id.( $contrato ? ' — '.h($contrato['Diretoria']??'').' — '.h($contrato['objeto']??'') : '');
} elseif ($role >= ROLE_DIRETOR) {
  $scope_title = ($diretoria === '' || strtolower($diretoria) === 'todas') ? 'Todas as Diretorias' : 'Diretoria '.h($diretoria);
} else {
  $scope_title = 'Diretoria '.h($user_dir ?: $diretoria);
}

/* ===== Query base (coordenador_inbox) ===== */
$table = 'coordenador_inbox';
$sel = "a.*,
        c.Objeto_Da_Obra AS objeto,
        c.Empresa AS empresa,
        c.Diretoria AS diretoria_c";
$join = " LEFT JOIN emop_contratos c ON c.id = a.contrato_id ";
if (table_exists($conn,'usuarios_cohidro_sigce')) {
  $sel  .= ", uc.nome AS processed_by_name";
  if (col_exists($conn,'usuarios_cohidro_sigce','diretoria')) $sel .= ", uc.diretoria AS processed_by_dir";
  $join .= " LEFT JOIN usuarios_cohidro_sigce uc ON uc.id = a.processed_by ";
}
$where = [];
if ($contrato_id > 0) {
  $where[] = "a.contrato_id = ".(int)$contrato_id;
} elseif ($role >= ROLE_DIRETOR) {
  if ($diretoria !== '' && strtolower($diretoria) !== 'todas') $where[] = "a.diretoria = '".$conn->real_escape_string($diretoria)."'";
} else {
  $where[] = "a.diretoria = '".$conn->real_escape_string($user_dir)."'";
}
/* Fiscal vê o que ele solicitou */
if ($role === ROLE_FISCAL) {
  $parts=[];
  if (col_exists($conn,$table,'fiscal_id') && $user_id>0)             $parts[] = "a.fiscal_id={$user_id}";
  if (col_exists($conn,$table,'requested_by_id') && $user_id>0)       $parts[] = "a.requested_by_id={$user_id}";
  if (col_exists($conn,$table,'requested_by_cpf') && $user_cpf!=='')  $parts[] = "a.requested_by_cpf='".$conn->real_escape_string($user_cpf)."'";
  if (col_exists($conn,$table,'requested_by') && $user_name!==''){
    $nm = $conn->real_escape_string($user_name);
    $parts[] = "a.requested_by='{$nm}'";
    $parts[] = "a.requested_by LIKE '%{$nm}%'";
  }
  if ($parts) $where[] = '('.implode(' OR ', $parts).')';
}
if ($status !== 'TODOS' && $status !== '') $where[] = "UPPER(a.status) = '".$conn->real_escape_string($status)."'";
if ($de  !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$de))  $where[] = "DATE(a.created_at) >= '".$conn->real_escape_string($de)."'";
if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$ate)) $where[] = "DATE(a.created_at) <= '".$conn->real_escape_string($ate)."'";
if ($q   !== '') { $qq=$conn->real_escape_string($q); $where[]="(c.Empresa LIKE '%{$qq}%' OR c.Objeto_Da_Obra LIKE '%{$qq}%' OR a.review_notes LIKE '%{$qq}%' OR a.motivo_rejeicao LIKE '%{$qq}%')"; }

$sql = "SELECT {$sel} FROM {$table} a {$join} ".(count($where)?('WHERE '.implode(' AND ',$where)):'')." ORDER BY a.contrato_id DESC, a.created_at DESC, a.id DESC";
$rows = []; if ($rs = $conn->query($sql)) { while ($r = $rs->fetch_assoc()) $rows[] = $r; if($rs) $rs->free(); }
$byContrato = []; foreach ($rows as $r) { $byContrato[(int)$r['contrato_id']][] = $r; }
$cacheAntes = [];
function contrato_antes(mysqli $conn, array &$cache, int $cid): array {
  if (!isset($cache[$cid])) {
    $res = $conn->query("SELECT * FROM emop_contratos WHERE id={$cid} LIMIT 1");
    $cache[$cid] = ($res && $res->num_rows) ? $res->fetch_assoc() : [];
    if ($res) $res->free();
  }
  return $cache[$cid];
}

/* ===== Conteúdo (entre os partials) ===== */
ob_start();
?>
<link href="/assets/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --card-bd:#e9edf1; --muted:#6b7280; --chip:#f1f5f9; --chip-bd:#e2e8f0;
         --pill-bd:#e5e7eb; --pill-bg:#f8fafc; --after-bg:#e8fff1; --after-bd:#c8f0d6; }
  .page { max-width: 1180px; margin: 1rem auto; }
  .sticky-head{ position: sticky; top:0; z-index:5; background:#fff; padding:.5rem 0 .25rem; }
  .toolbar { gap:.5rem; }
  .chip{ background:var(--chip); border:1px solid var(--chip-bd); padding:.15rem .5rem; border-radius:999px; font-size:.75rem; }
  .pill{ display:inline-block; padding:.2rem .5rem; border-radius:999px; font-size:.85rem; line-height:1; border:1px solid var(--pill-bd); background:var(--pill-bg); }
  .pill.before s{ opacity:.65; }
  .pill.after{ background:var(--after-bg); border-color:var(--after-bd); font-weight:700; }
  .arrow{ margin:0 .35rem; opacity:.6; }
</style>

<div class="page">
  <div class="sticky-head">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-2">Histórico do Fiscal — <?= $scope_title ?></h5>
      <div class="toolbar d-flex">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExpandAll">Expandir todos</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCollapseAll">Recolher todos</button>
      </div>
    </div>

    <form class="row g-2 align-items-end" method="get" action="">
      <?php if($contrato_id>0): ?><input type="hidden" name="contrato_id" value="<?= (int)$contrato_id ?>"><?php endif; ?>

      <?php if($role >= ROLE_DIRETOR): ?>
      <div class="col-auto">
        <label class="form-label mb-0 small">Diretoria</label>
        <select class="form-select form-select-sm" name="diretoria">
          <option value="todas" <?= (strtolower($diretoria)==='todas' || $diretoria==='')?'selected':'' ?>>Todas</option>
          <?php foreach($diretorias_opts as $d): ?>
            <option value="<?= h($d) ?>" <?= $diretoria===$d?'selected':'' ?>><?= h($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="diretoria" value="<?= h($diretoria) ?>">
      <?php endif; ?>

      <div class="col-auto">
        <label class="form-label mb-0 small">De</label>
        <input type="date" class="form-control form-control-sm" name="de" value="<?= h($de) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Até</label>
        <input type="date" class="form-control form-control-sm" name="ate" value="<?= h($ate) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Status</label>
        <select class="form-select form-select-sm" name="status">
          <option value="TODOS" <?= $status==='TODOS'?'selected':'' ?>>Todos</option>
          <option value="PENDENTE" <?= $status==='PENDENTE'?'selected':'' ?>>Pendente</option>
          <option value="APROVADO" <?= $status==='APROVADO'?'selected':'' ?>>Aprovado</option>
          <option value="REJEITADO" <?= $status==='REJEITADO'?'selected':'' ?>>Rejeitado</option>
          <option value="REVISAO_SOLICITADA" <?= $status==='REVISAO_SOLICITADA'?'selected':'' ?>>Revisão Solicitada</option>
        </select>
      </div>
      <div class="col">
        <label class="form-label mb-0 small">Buscar</label>
        <input type="text" class="form-control form-control-sm" name="q" placeholder="Empresa, objeto, motivo..." value="<?= h($q) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm">Aplicar</button>
      </div>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info mt-3">Nenhum registro encontrado.</div>
  <?php else: ?>
    <?php foreach ($byContrato as $cid => $items): ?>
      <div class="contract-card mb-3 border rounded">
        <div class="contract-head bg-white border-bottom p-2 d-flex justify-content-between align-items-center">
          <div class="contract-title fw-bold">Contrato <?= (int)$cid ?></div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="chip"><?= h($items[0]['empresa'] ?? '') ?></span>
            <span class="chip">Diretoria: <?= h($items[0]['diretoria'] ?? $items[0]['diretoria_c'] ?? '') ?></span>
          </div>
        </div>

        <?php
          $antesRow = contrato_antes($conn, $cacheAntes, (int)$cid);
          $cols = [];
          if ($rsCols = $conn->query("SHOW COLUMNS FROM emop_contratos")) {
            while ($rc = $rsCols->fetch_assoc()) $cols[] = $rc['Field'];
            $rsCols->free();
          }
        ?>

        <div class="accordion" id="acc-<?= (int)$cid ?>">
          <?php foreach ($items as $row):
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            $status_raw   = strtoupper(trim((string)($row['status'] ?? 'PENDENTE')));
            $status_label = status_label_ptbr($status_raw);
            $badge = ($status_raw==='APROVADO'?'success':($status_raw==='REJEITADO'?'danger':($status_raw==='REVISAO_SOLICITADA'?'warning':'secondary')));
            $itemId  = 'c'.$cid.'-'.$row['id'];

            $coord     = !empty($row['processed_by_name']) ? $row['processed_by_name'] : (!empty($row['processed_by']) ? 'ID '.$row['processed_by'] : '—');
            $coord_dir = isset($row['processed_by_dir']) ? (string)$row['processed_by_dir'] : '—';
            $motivo    = (string)($row['review_notes'] ?? $row['motivo_rejeicao'] ?? '');

            $changes=[]; $medicoes=[]; $aditivos=[]; $reajustes=[];
            if (is_array($payload) && isset($payload['campos']) && is_array($payload['campos'])) {
              foreach ($payload['campos'] as $col => $novo) {
                $col_db = $col; foreach ($cols as $c) { if (strcasecmp($c, $col)===0) { $col_db=$c; break; } }
                $antes = $antesRow[$col_db] ?? '—';
                if (is_array($novo) || is_object($novo)) $novo = json_encode($novo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $changes[] = ['label'=>column_label($col_db),'antes'=>($antes===''?'—':$antes),'depois'=>($novo===''?'—':$novo)];
              }
            }
            if (is_array($payload) && !empty($payload['novas_medicoes'])) {
              foreach ($payload['novas_medicoes'] as $m) {
                if (!is_array($m)) continue;
                $medicoes[]=['data'=>$m['data']??($m['data_medicao']??''),'valor_rs'=>$m['valor_rs']??($m['valor']??''),'acumulado'=>$m['acumulado_rs']??'','percentual'=>$m['percentual']??'','obs'=>$m['obs']??($m['observacao']??'')];
              }
            }
            if (is_array($payload) && !empty($payload['novos_aditivos'])) {
              foreach ($payload['novos_aditivos'] as $a) {
                if (!is_array($a)) continue;
                $aditivos[]=['numero'=>$a['numero_aditivo']??'','data'=>$a['data']??'','tipo'=>$a['tipo']??'','valor_total'=>$a['valor_aditivo_total']??'','valor_total_apos'=>$a['valor_total_apos_aditivo']??'','obs'=>$a['observacao']??''];
              }
            }
            if (is_array($payload) && !empty($payload['novos_reajustes'])) {
              foreach ($payload['novos_reajustes'] as $rj) {
                if (!is_array($rj)) continue;
                $reajustes[]=['indice'=>$rj['indice']??'','percentual'=>$rj['percentual']??'','data_base'=>$rj['data_base']??'','valor_apos'=>$rj['valor_total_apos_reajuste']??'','obs'=>$rj['observacao']??''];
              }
            }
          ?>
          <div class="accordion-item border-0 border-top">
            <h2 class="accordion-header" id="h-<?= $itemId ?>">
              <button class="accordion-button collapsed bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#b-<?= $itemId ?>" aria-expanded="false" aria-controls="b-<?= $itemId ?>">
                <div class="w-100 d-flex justify-content-between align-items-center">
                  <div class="text-truncate muted"><?= h($row['objeto'] ?? '') ?></div>
                  <span class="badge bg-<?= $badge ?>"><?= h($status_label) ?></span>
                </div>
              </button>
            </h2>
            <div id="b-<?= $itemId ?>" class="accordion-collapse collapse" aria-labelledby="h-<?= $itemId ?>" data-bs-parent="#acc-<?= (int)$cid ?>">
              <div class="accordion-body pt-2 small">
                <div class="row mb-2">
                  <div class="col-md">
                    <div class="text-muted">
                      <strong>Coordenador:</strong> <?= h($coord) ?> &nbsp;·&nbsp;
                      <strong>Diretoria (Coord.):</strong> <?= h($coord_dir ?: '—') ?>
                    </div>
                    <?php if (!empty($motivo)): ?>
                      <div class="text-muted"><strong>Motivo/Obs:</strong> <?= nl2br(h($motivo)) ?></div>
                    <?php endif; ?>
                    <div class="text-muted">
                      <strong>Solicitado em:</strong> <?= h($row['created_at'] ?? '—') ?> &nbsp;·&nbsp;
                      <strong>Processado em:</strong> <?= h($row['processed_at'] ?? '—') ?>
                    </div>
                  </div>
                </div>

                <?php if (empty($changes) && empty($medicoes) && empty($aditivos) && empty($reajustes)): ?>
                  <div class="alert alert-warning mb-3">Nenhuma alteração estruturada identificada.</div>
                <?php endif; ?>

                <?php if (!empty($changes)): ?>
                <ul class="list-group list-group-flush mb-3">
                  <?php foreach($changes as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                      <div class="fw-semibold"><?= h($c['label']) ?></div>
                      <div class="ms-auto text-end">
                        <span class="pill before"><s><?= h((string)$c['antes']) ?></s></span>
                        <span class="arrow">→</span>
                        <span class="pill after"><?= h((string)$c['depois']) ?></span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($medicoes)): ?>
                <div class="table-responsive mb-3">
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr><th colspan="5">Novas medições</th></tr>
                      <tr><th>Data</th><th>Valor (R$)</th><th>Acumulado (R$)</th><th>%</th><th>Obs</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach($medicoes as $m): ?>
                        <tr>
                          <td><?= h($m['data']) ?></td>
                          <td><?= h((string)$m['valor_rs']) ?></td>
                          <td><?= h((string)$m['acumulado']) ?></td>
                          <td><?= h((string)$m['percentual']) ?></td>
                          <td><?= h((string)$m['obs']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($aditivos)): ?>
                <div class="table-responsive mb-3">
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr><th colspan="6">Novos aditivos</th></tr>
                      <tr><th>Nº</th><th>Data</th><th>Tipo</th><th>Valor do Aditivo</th><th>Valor Total Após</th><th>Obs</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach($aditivos as $a): ?>
                        <tr>
                          <td><?= h((string)$a['numero']) ?></td>
                          <td><?= h((string)$a['data']) ?></td>
                          <td><?= h((string)$a['tipo']) ?></td>
                          <td><?= h((string)$a['valor_total']) ?></td>
                          <td><?= h((string)$a['valor_total_apos']) ?></td>
                          <td><?= h((string)$a['obs']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($reajustes)): ?>
                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr><th colspan="5">Novos reajustes</th></tr>
                      <tr><th>Índice</th><th>%</th><th>Data-base</th><th>Valor Total Após Reajuste</th><th>Obs</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach($reajustes as $rj): ?>
                        <tr>
                          <td><?= h((string)$rj['indice']) ?></td>
                          <td><?= h((string)$rj['percentual']) ?></td>
                          <td><?= h((string)$rj['data_base']) ?></td>
                          <td><?= h((string)$rj['valor_apos']) ?></td>
                          <td><?= h((string)$rj['obs']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>

              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  document.getElementById('btnExpandAll')?.addEventListener('click', function(){
    document.querySelectorAll('.accordion-collapse').forEach(el => {
      if (!el.classList.contains('show')) new bootstrap.Collapse(el, {show:true});
    });
  });
  document.getElementById('btnCollapseAll')?.addEventListener('click', function(){
    document.querySelectorAll('.accordion-collapse.show').forEach(el => {
      new bootstrap.Collapse(el, {toggle:true});
    });
  });
})();
</script>
<?php
$__CONTENT__ = ob_get_clean();

/* ===== Renderização: apenas header + footer (evita duplicar topbar/sidebar) ===== */
$header = __DIR__ . '/../partials/header.php';
$footer = __DIR__ . '/../partials/footer.php';
$pageTitle = 'Histórico do Fiscal';

if (is_file($header) && is_file($footer)) {
  include $header;
  echo $__CONTENT__;
  include $footer;
} else {
  // fallback simples
  ?><!doctype html><html lang="pt-br"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
  </head><body><?= $__CONTENT__ ?></body></html><?php
}
