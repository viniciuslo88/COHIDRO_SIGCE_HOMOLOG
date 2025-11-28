<?php
// /php/historico_alteracoes_contratos.php
// Página de Histórico de Alterações de Contratos — com filtro de Diretoria (níveis 4 e 5) + comparativo antes/depois + solicitante correto

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/diretoria_guard.php';
require_once __DIR__ . '/roles.php';
date_default_timezone_set('America/Sao_Paulo');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ============================================================
   FUNÇÕES AUXILIARES
============================================================ */
function column_label_map(){ return [
  'Objeto_Da_Obra'=>'Objeto da Obra','Fonte_De_Recursos'=>'Fonte de Recursos','Aditivo_N'=>'Aditivo Nº','Processo_SEI'=>'Processo SEI',
  'Diretoria'=>'Diretoria','Secretaria'=>'Secretaria','Municipio'=>'Município','Empresa'=>'Empresa','Valor_Do_Contrato'=>'Valor do Contrato',
  'Data_Inicio'=>'Data de Início','Data_Fim_Prevista'=>'Data de Fim Prevista','Status'=>'Status','Observacoes'=>'Observações',
  'Percentual_Executado'=>'% Executado','Valor_Liquidado_Acumulado'=>'Valor Liquidado (Acum.)','Data_Da_Medicao_Atual'=>'Data da Medição Atual',
  'Valor_Liquidado_Na_Medicao_RS'=>'Valor da Medição (R$)',
];}
function prettify_column($col){
  $label = str_replace('_',' ', $col);
  $label = ucwords(strtolower($label));
  $label = str_replace([' Sei',' Rj',' N '],[' SEI',' RJ',' Nº '], $label);
  $label = str_replace(' Nº  ',' Nº ', $label);
  return $label;
}
function column_label($col){ $map = column_label_map(); return $map[$col] ?? prettify_column($col); }

/* ===== Nome do solicitante (igual ao modal) ===== */
function scalarize_name($v){
  if ($v === null) return '';
  if (is_scalar($v)) return (string)$v;
  if (is_array($v)) {
    foreach (['name','nome','full_name','display','email'] as $k) {
      if (isset($v[$k]) && is_scalar($v[$k])) return (string)$v[$k];
    }
    return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function solicitante_from_payload($payload){
  if (!is_array($payload)) return null;
  foreach ([
    'fiscal_nome','fiscal','solicitante_nome','solicitante','autor_nome','autor',
    'usuario_nome','usuario','user_name','requested_by','created_by_name'
  ] as $k){
    if (!array_key_exists($k, $payload)) continue;
    $name = trim(scalarize_name($payload[$k]));
    if ($name !== '') return $name;
  }
  foreach (['user','autor'] as $nk){
    if (!isset($payload[$nk])) continue;
    $name = trim(scalarize_name($payload[$nk]));
    if ($name !== '') return $name;
  }
  return null;
}
function resolve_solicitante(array $row, $payload, mysqli $conn){
  foreach ([
    'fiscal_nome','fiscal','solicitante_nome','solicitante',
    'usuario_nome','usuario','user_name','requested_by','created_by_name'
  ] as $k){
    if (!isset($row[$k])) continue;
    $name = trim(scalarize_name($row[$k]));
    if ($name !== '') return $name;
  }
  $p = solicitante_from_payload(is_array($payload) ? $payload : []);
  if ($p) return $p;
  return '—';
}

/* ============================================================
   CONTROLE DE ACESSO
============================================================ */
$role = (int)($_SESSION['role'] ?? 0);
if ($role < 2) {
  http_response_code(403);
  echo '<div class="alert alert-danger m-3">Acesso negado.</div>';
  exit;
}

/* ============================================================
   ENTRADAS
============================================================ */
$contrato_id = (int)($_GET['contrato_id'] ?? 0);
$diretoria   = trim((string)($_GET['diretoria'] ?? ''));
if ($diretoria === '') $diretoria = trim((string)($_SESSION['diretoria'] ?? ''));

$de      = trim((string)($_GET['de'] ?? ''));
$ate     = trim((string)($_GET['ate'] ?? ''));
$status  = strtoupper(trim((string)($_GET['status'] ?? 'TODOS')));
$q       = trim((string)($_GET['q'] ?? ''));

/* ============================================================
   DIRETORIAS DISPONÍVEIS (para níveis 4 e 5)
============================================================ */
$diretorias_opts = [];
if ($role >= 4) {
  $rs = $conn->query("SELECT DISTINCT Diretoria FROM emop_contratos WHERE Diretoria IS NOT NULL AND Diretoria <> '' ORDER BY Diretoria");
  while ($rs && $r = $rs->fetch_assoc()) $diretorias_opts[] = $r['Diretoria'];
  if ($rs) $rs->free();
}

/* ============================================================
   TÍTULO / ESCOPO
============================================================ */
if ($contrato_id > 0) {
  $rs = $conn->query("SELECT id, Objeto_Da_Obra AS objeto, Empresa, Diretoria FROM emop_contratos WHERE id={$contrato_id} LIMIT 1");
  $contrato = ($rs && $rs->num_rows)?$rs->fetch_assoc():null; if($rs) $rs->free();
  $scope_title = 'Contrato '.(int)$contrato_id.( $contrato ? ' — '.h($contrato['Diretoria']??'').' — '.h($contrato['objeto']??'') : '');
} elseif ($role >= 4) {
  $scope_title = ($diretoria === '' || strtolower($diretoria) === 'todas')
    ? 'Todas as Diretorias'
    : 'Diretoria '.h($diretoria);
} else {
  $scope_title = 'Diretoria '.h($_SESSION['diretoria'] ?? $diretoria);
}

/* ============================================================
   QUERY BASE — com regra de acesso por nível
============================================================ */
// >>> ALTERAÇÃO: Join com usuarios_cohidro_sigce p/ nome do fiscal
$where = [];
$select = "SELECT a.*, 
                  c.Objeto_Da_Obra AS objeto, 
                  c.Empresa AS empresa, 
                  c.Diretoria AS diretoria_c,
                  u.nome  AS fiscal_nome, 
                  u.email AS fiscal_email
           FROM coordenador_inbox a
           LEFT JOIN emop_contratos c ON c.id = a.contrato_id
           LEFT JOIN usuarios_cohidro_sigce u ON u.id = a.fiscal_id";

if ($contrato_id > 0) {
  $where[] = "a.contrato_id = ".(int)$contrato_id;
} elseif ($role >= 4) {
  // níveis 4 e 5 — “Todas” significa sem filtro
  if ($diretoria !== '' && strtolower($diretoria) !== 'todas') {
    $where[] = "a.diretoria = '".$conn->real_escape_string($diretoria)."'";
  }
} else {
  // níveis 2 e 3 — apenas sua diretoria
  $where[] = "a.diretoria = '".$conn->real_escape_string($_SESSION['diretoria'] ?? '') ."'";
}

if ($status !== 'TODOS') {
  $where[] = "UPPER(a.status) = '".$conn->real_escape_string($status)."'";
}
if ($de !== ''  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
  $where[] = "DATE(a.created_at) >= '".$conn->real_escape_string($de)."'";
}
if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
  $where[] = "DATE(a.created_at) <= '".$conn->real_escape_string($ate)."'";
}

$sql = $select;
if (count($where) > 0) {
  $sql .= " WHERE ".implode(' AND ', $where);
}
$sql .= " ORDER BY a.contrato_id DESC, a.created_at DESC, a.id DESC";

/* ============================================================
   EXECUÇÃO DA QUERY
============================================================ */
$rows = [];
if ($rs = $conn->query($sql)) { while ($r = $rs->fetch_assoc()) $rows[] = $r; if($rs) $rs->free(); }

/* ============================================================
   AGRUPAR POR CONTRATO + CACHE do "antes"
============================================================ */
$byContrato = [];
foreach ($rows as $r) { $byContrato[(int)$r['contrato_id']][] = $r; }

$cacheAntes = []; // contrato_id => row emop_contratos
function contrato_antes(mysqli $conn, array &$cache, int $cid): array {
  if (!isset($cache[$cid])) {
    $res = $conn->query("SELECT * FROM emop_contratos WHERE id={$cid} LIMIT 1");
    $cache[$cid] = ($res && $res->num_rows) ? $res->fetch_assoc() : [];
    if ($res) $res->free();
  }
  return $cache[$cid];
}

/* ============================================================
   HTML
============================================================ */
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

  /* pílulas antes/depois */
  .pill{ display:inline-block; padding:.2rem .5rem; border-radius:999px; font-size:.85rem; line-height:1; border:1px solid var(--pill-bd); background:var(--pill-bg); }
  .pill.before s{ opacity:.65; }
  .pill.after{ background:var(--after-bg); border-color:var(--after-bd); font-weight:700; }
  .arrow{ margin:0 .35rem; opacity:.6; }
</style>

<div class="page">
  <div class="sticky-head">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-2">Histórico — <?= $scope_title ?></h5>
      <div class="toolbar d-flex">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExpandAll">Expandir todos</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCollapseAll">Recolher todos</button>
      </div>
    </div>

    <!-- Filtros (mantidos DENTRO do arquivo, como no seu original) -->
    <form class="row search-row g-2 align-items-center" method="get" action="">
      <?php if($contrato_id>0): ?>
        <input type="hidden" name="contrato_id" value="<?= (int)$contrato_id ?>">
      <?php endif; ?>

      <?php if($role >= 4): ?>
      <div class="col-auto">
        <label class="form-label mb-0 small">Diretoria</label>
        <select class="form-select" name="diretoria">
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
        <input type="date" class="form-control" name="de" value="<?= h($de) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Até</label>
        <input type="date" class="form-control" name="ate" value="<?= h($ate) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0 small">Status</label>
        <select class="form-select" name="status">
          <?php foreach(['TODOS','APROVADO','REJEITADO','PENDENTE'] as $opt): ?>
            <option value="<?= $opt ?>" <?= $status===$opt?'selected':'' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label class="form-label mb-0 small">Buscar</label>
        <input type="text" class="form-control" name="q" placeholder="Empresa, objeto, campo, valor..." value="<?= h($q) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0">&nbsp;</label>
        <div><button class="btn btn-primary btn-sm">Aplicar</button></div>
      </div>
    </form>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info mt-3">Nenhum registro de alterações encontrado.</div>
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
          // carrega a linha atual do contrato (estado "antes")
          $antesRow = contrato_antes($conn, $cacheAntes, (int)$cid);

          // pega colunas atuais do banco para resolver case-insensitive
          $cols = [];
          if ($rsCols = $conn->query("SHOW COLUMNS FROM emop_contratos")) {
            while ($rc = $rsCols->fetch_assoc()) $cols[] = $rc['Field'];
            $rsCols->free();
          }
        ?>

        <div class="accordion" id="acc-<?= (int)$cid ?>">
          <?php foreach ($items as $row):
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            $statusR  = strtoupper(trim((string)($row['status'] ?? 'PENDENTE')));
            $badge   = ($statusR==='APROVADO'?'success':($statusR==='REJEITADO'?'danger':'secondary'));
            $itemId  = 'c'.$cid.'-'.$row['id'];

            // >>> Fiscal: prioriza o join u.nome/u.email; fallback resolve_solicitante
            $fiscal = '';
            foreach (['fiscal_nome','fiscal_email'] as $k) {
              if (!empty($row[$k])) { $fiscal = trim((string)$row[$k]); break; }
            }
            if ($fiscal === '') $fiscal = resolve_solicitante($row, $payload, $conn);

            // monta lista de campos alterados (exibe antes→depois)
            $changes = [];
            if (is_array($payload) && isset($payload['campos']) && is_array($payload['campos'])) {
              foreach ($payload['campos'] as $col => $novo) {
                // resolver nome da coluna respeitando case do banco
                $col_db = $col;
                foreach ($cols as $c) { if (strcasecmp($c, $col)===0) { $col_db=$c; break; } }
                $antes = $antesRow[$col_db] ?? '—';
                if (is_array($novo) || is_object($novo)) {
                  $novo = json_encode($novo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
                $changes[] = [
                  'label'  => column_label($col_db),
                  'antes'  => ($antes === '' ? '—' : $antes),
                  'depois' => ($novo   === '' ? '—' : $novo),
                ];
              }
            }

            // >>> Novas medições / aditivos / reajustes (exibição somente se houver)
            $medicoes = []; $aditivos = []; $reajustes = [];
            if (is_array($payload) && !empty($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])) {
              foreach ($payload['novas_medicoes'] as $m) {
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
            if (is_array($payload) && !empty($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])) {
              foreach ($payload['novos_aditivos'] as $a) {
                if (!is_array($a)) continue;
                $aditivos[] = [
                  'numero'          => $a['numero_aditivo'] ?? '',
                  'data'            => $a['data'] ?? '',
                  'tipo'            => $a['tipo'] ?? '',
                  'valor_total'     => $a['valor_aditivo_total'] ?? '',
                  'valor_total_apos'=> $a['valor_total_apos_aditivo'] ?? '',
                  'obs'             => $a['observacao'] ?? '',
                ];
              }
            }
            if (is_array($payload) && !empty($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])) {
              foreach ($payload['novos_reajustes'] as $rj) {
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
          ?>
          <div class="accordion-item border-0 border-top">
            <h2 class="accordion-header" id="h-<?= $itemId ?>">
              <button class="accordion-button collapsed bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#b-<?= $itemId ?>" aria-expanded="false" aria-controls="b-<?= $itemId ?>">
                <div class="w-100 d-flex justify-content-between align-items-center">
                  <div class="text-truncate muted"><?= h($row['objeto'] ?? '') ?></div>
                  <span class="badge bg-<?= $badge ?>"><?= h($statusR) ?></span>
                </div>
              </button>
            </h2>
            <div id="b-<?= $itemId ?>" class="accordion-collapse collapse" aria-labelledby="h-<?= $itemId ?>" data-bs-parent="#acc-<?= (int)$cid ?>">
              <div class="accordion-body pt-2 small">
                <div class="text-muted mb-2">
                  Solicitado por <strong><?= h($fiscal ?: '—') ?></strong>
                  <?php if (!empty($row['created_at'])): ?> em <?= h(date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?><?php endif; ?>
                </div>

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
                <?php else: ?>
                  <div class="alert alert-warning mb-3">Nenhuma alteração estruturada identificada.</div>
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

$pageTitle = 'Histórico — '.$scope_title;
$header = __DIR__ . '/../partials/header.php';
$footer = __DIR__ . '/../partials/footer.php';

if (is_file($header) && is_file($footer)) {
  include $header;
  echo $__CONTENT__;
  include $footer;
} else {
  ?><!doctype html><html lang="pt-br"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link href="/assets/bootstrap.min.css" rel="stylesheet">
  </head><body><?= $__CONTENT__ ?></body></html><?php
}
