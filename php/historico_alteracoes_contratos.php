<?php
// /php/historico_alteracoes_contratos.php
// Histórico de Alterações — inclui solicitações (coordenador_inbox) + auditoria (emop_contratos_log)

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
   GARANTE SCHEMA DE LOG (fallback seguro)
============================================================ */
if (!function_exists('ensure_contratos_log_schema')) {
  function ensure_contratos_log_schema(mysqli $conn): void {
    // não altera nada se já existir
    $conn->query("CREATE TABLE IF NOT EXISTS emop_contratos_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      contrato_id INT NOT NULL,
      usuario_nome VARCHAR(255) NULL,
      diretoria VARCHAR(255) NULL,
      acao VARCHAR(255) NULL,
      detalhes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_contrato (contrato_id),
      KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}

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

function resolve_solicitante(array $row, $payload){
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

function resolve_decisor(array $row, $payload){
  if (!is_array($payload)) $payload = [];
  $candidates = [
    'decisor_nome','decisor','decisao_por_nome','decisao_por',
    'avaliador_nome','coordenador_nome','gestor_nome',
    'aprovado_por_nome','aprovado_por',
    'rejeitado_por_nome','rejeitado_por',
    'decidido_por','decidido_por_nome',
    'analista_nome','analista',
  ];

  foreach ($candidates as $k){
    if (!empty($row[$k])) {
      $name = trim(scalarize_name($row[$k]));
      if ($name !== '') return $name;
    }
  }
  foreach ($candidates as $k){
    if (!empty($payload[$k])) {
      $name = trim(scalarize_name($payload[$k]));
      if ($name !== '') return $name;
    }
  }
  return null;
}

function resolve_decision_datetime(array $row, $payload){
  if (!is_array($payload)) $payload = [];
  $decisionFields = ['decision_at','decisao_em','status_at','aprovado_em','rejeitado_em','updated_at'];
  foreach ($decisionFields as $k){
    if (!empty($row[$k]) && $row[$k] !== '0000-00-00 00:00:00') return $row[$k];
  }
  foreach ($decisionFields as $k){
    if (!empty($payload[$k]) && $payload[$k] !== '0000-00-00 00:00:00') return $payload[$k];
  }
  return null;
}

function fmt_tempo_medio($segundos){
  $segundos = (int)$segundos;
  if ($segundos <= 0) return '—';
  $dias   = intdiv($segundos, 86400);
  $resto  = $segundos % 86400;
  $horas  = intdiv($resto, 3600);
  $resto  = $resto % 3600;
  $min    = intdiv($resto, 60);
  $partes = [];
  if ($dias > 0)  $partes[] = $dias.'d';
  if ($horas > 0) $partes[] = $horas.'h';
  if ($min > 0)   $partes[] = $min.'min';
  if (!$partes) return 'menos de 1 min';
  return implode(' ', $partes);
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
   CONSULTA 1 — SOLICITAÇÕES (coordenador_inbox)
   (diretoria de referência = diretoria do contrato, fallback na diretoria do inbox)
============================================================ */
$DIR_REF_INBOX = "COALESCE(NULLIF(c.Diretoria,''), NULLIF(a.diretoria,''))";

$selectInbox = "SELECT
    'INBOX' AS src,
    a.id AS row_id,
    a.contrato_id,
    a.status,
    a.created_at,
    a.updated_at,
    a.payload_json,
    c.Objeto_Da_Obra AS objeto,
    c.Empresa AS empresa,
    c.Diretoria AS diretoria_contrato,
    a.diretoria AS diretoria_solicitante,
    {$DIR_REF_INBOX} AS diretoria_ref,
    u.nome AS fiscal_nome,
    u.email AS fiscal_email,
    NULL AS usuario_nome_log,
    NULL AS acao_log,
    NULL AS detalhes_log
  FROM coordenador_inbox a
  LEFT JOIN emop_contratos c ON c.id = a.contrato_id
  LEFT JOIN usuarios_cohidro_sigce u ON u.id = a.fiscal_id";

$whereInbox = [];

if ($contrato_id > 0) {
  $whereInbox[] = "a.contrato_id = ".(int)$contrato_id;
} elseif ($role >= 4) {
  if ($diretoria !== '' && strtolower($diretoria) !== 'todas') {
    $whereInbox[] = "{$DIR_REF_INBOX} = '".$conn->real_escape_string($diretoria)."'";
  }
} else {
  $dirSess = trim((string)($_SESSION['diretoria'] ?? ''));
  if ($dirSess === '') {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3">Diretoria da sessão não definida.</div>';
    exit;
  }
  $whereInbox[] = "{$DIR_REF_INBOX} = '".$conn->real_escape_string($dirSess)."'";
}

if ($status !== 'TODOS') {
  $whereInbox[] = "UPPER(a.status) = '".$conn->real_escape_string($status)."'";
}

if ($de !== ''  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
  $whereInbox[] = "DATE(a.created_at) >= '".$conn->real_escape_string($de)."'";
}
if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
  $whereInbox[] = "DATE(a.created_at) <= '".$conn->real_escape_string($ate)."'";
}

if ($q !== '') {
  $qEsc = '%'.$conn->real_escape_string($q).'%';
  $whereInbox[] = "(
      CAST(a.contrato_id AS CHAR) LIKE '{$qEsc}'
      OR c.Objeto_Da_Obra LIKE '{$qEsc}'
      OR c.Empresa LIKE '{$qEsc}'
      OR {$DIR_REF_INBOX} LIKE '{$qEsc}'
      OR a.payload_json LIKE '{$qEsc}'
  )";
}

$sqlInbox = $selectInbox . (count($whereInbox)?(" WHERE ".implode(' AND ', $whereInbox)):'');
$sqlInbox .= " ORDER BY a.contrato_id DESC, a.created_at DESC, a.id DESC";

/* ============================================================
   CONSULTA 2 — AUDITORIA (emop_contratos_log)
   (também filtrada pela diretoria do contrato)
============================================================ */
ensure_contratos_log_schema($conn);

$DIR_REF_LOG = "COALESCE(NULLIF(c.Diretoria,''), NULLIF(l.diretoria,''))";

$selectLog = "SELECT
    'LOG' AS src,
    l.id AS row_id,
    l.contrato_id,
    'APROVADO' AS status,
    l.created_at,
    NULL AS updated_at,
    NULL AS payload_json,
    c.Objeto_Da_Obra AS objeto,
    c.Empresa AS empresa,
    c.Diretoria AS diretoria_contrato,
    l.diretoria AS diretoria_solicitante,
    {$DIR_REF_LOG} AS diretoria_ref,
    NULL AS fiscal_nome,
    NULL AS fiscal_email,
    l.usuario_nome AS usuario_nome_log,
    l.acao AS acao_log,
    l.detalhes AS detalhes_log
  FROM emop_contratos_log l
  LEFT JOIN emop_contratos c ON c.id = l.contrato_id";

$whereLog = [];

if ($contrato_id > 0) {
  $whereLog[] = "l.contrato_id = ".(int)$contrato_id;
} elseif ($role >= 4) {
  if ($diretoria !== '' && strtolower($diretoria) !== 'todas') {
    $whereLog[] = "{$DIR_REF_LOG} = '".$conn->real_escape_string($diretoria)."'";
  }
} else {
  $dirSess = trim((string)($_SESSION['diretoria'] ?? ''));
  $whereLog[] = "{$DIR_REF_LOG} = '".$conn->real_escape_string($dirSess)."'";
}

// LOG representa alteração direta (sem fluxo). Consideramos como "APROVADO".
// Então: aparece em TODOS e em APROVADO; não aparece em PENDENTE/REJEITADO.
if ($status !== 'TODOS' && $status !== 'APROVADO') {
  $whereLog[] = "1=0";
}


if ($de !== ''  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
  $whereLog[] = "DATE(l.created_at) >= '".$conn->real_escape_string($de)."'";
}
if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
  $whereLog[] = "DATE(l.created_at) <= '".$conn->real_escape_string($ate)."'";
}

if ($q !== '') {
  $qEsc = '%'.$conn->real_escape_string($q).'%';
  $whereLog[] = "(
      CAST(l.contrato_id AS CHAR) LIKE '{$qEsc}'
      OR c.Objeto_Da_Obra LIKE '{$qEsc}'
      OR c.Empresa LIKE '{$qEsc}'
      OR {$DIR_REF_LOG} LIKE '{$qEsc}'
      OR l.usuario_nome LIKE '{$qEsc}'
      OR l.acao LIKE '{$qEsc}'
      OR l.detalhes LIKE '{$qEsc}'
  )";
}

$sqlLog = $selectLog . (count($whereLog)?(" WHERE ".implode(' AND ', $whereLog)):'');
$sqlLog .= " ORDER BY l.contrato_id DESC, l.created_at DESC, l.id DESC";

/* ============================================================
   EXECUÇÃO — merge INBOX + LOG
============================================================ */
$rowsInbox = [];
if ($rs = $conn->query($sqlInbox)) {
  while ($r = $rs->fetch_assoc()) $rowsInbox[] = $r;
  $rs->free();
}

$rowsLog = [];
if ($rs = $conn->query($sqlLog)) {
  while ($r = $rs->fetch_assoc()) $rowsLog[] = $r;
  $rs->free();
}

$rows = array_merge($rowsInbox, $rowsLog);

// ordenação final consistente (contrato desc, data desc, src, id desc)
usort($rows, function($a, $b){
  $ca = (int)($a['contrato_id'] ?? 0);
  $cb = (int)($b['contrato_id'] ?? 0);
  if ($ca !== $cb) return $cb <=> $ca;

  $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
  $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
  if ($ta !== $tb) return $tb <=> $ta;

  // INBOX primeiro (para decisões), LOG depois
  $sa = (string)($a['src'] ?? '');
  $sb = (string)($b['src'] ?? '');
  if ($sa !== $sb) return ($sa === 'INBOX') ? -1 : 1;

  $ia = (int)($a['row_id'] ?? 0);
  $ib = (int)($b['row_id'] ?? 0);
  return $ib <=> $ia;
});

// KPIs (TOTAL inclui INBOX + LOG; status/tempo continuam só INBOX)
$kpiTotalRegistros    = count($rows);       // INBOX + LOG
$kpiTotalSolicitacoes = count($rowsInbox);  // apenas INBOX
$kpiTotalAuditoria    = count($rowsLog);    // apenas LOG
$kpiAprovadosDiretos = $kpiTotalAuditoria;

$kpiPendentes    = 0;
$kpiAprovados    = 0;
$kpiRejeitados   = 0;
$kpiTempoSegSum  = 0;
$kpiTempoQtde    = 0;

// updated_at existe?
$hasUpdatedAt = false;
if ($rsColsInbox = $conn->query("SHOW COLUMNS FROM coordenador_inbox")) {
  while ($rc = $rsColsInbox->fetch_assoc()) {
    if ($rc['Field'] === 'updated_at') { $hasUpdatedAt = true; break; }
  }
  $rsColsInbox->free();
}

foreach ($rowsInbox as $r) {
  $st = strtoupper(trim((string)($r['status'] ?? 'PENDENTE')));
  if ($st === 'PENDENTE')  $kpiPendentes++;
  if ($st === 'APROVADO')  $kpiAprovados++;
  if ($st === 'REJEITADO') $kpiRejeitados++;

  if ($st === 'APROVADO' && $hasUpdatedAt && !empty($r['created_at']) && !empty($r['updated_at'])) {
    $t1 = strtotime((string)$r['created_at']);
    $t2 = strtotime((string)$r['updated_at']);
    if ($t1 && $t2 && $t2 > $t1) {
      $kpiTempoSegSum += ($t2 - $t1);
      $kpiTempoQtde++;
    }
  }
}

// ✅ AQUI é o “final do cálculo” do INBOX
$kpiAprovados += (int)$kpiAprovadosDiretos;

$kpiTempoMedioSeg = ($kpiTempoQtde > 0) ? (int)round($kpiTempoSegSum / $kpiTempoQtde) : 0;


/* ============================================================
   AGRUPAR POR CONTRATO + CACHE do "antes"
============================================================ */
$byContrato = [];
foreach ($rows as $r) { $byContrato[(int)$r['contrato_id']][] = $r; }

$cacheAntes = [];
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
  :root{
    --muted:#6b7280;
    --chip:#f1f5f9;
    --chip-bd:#e2e8f0;
    --pill-bd:#e5e7eb;
    --pill-bg:#f8fafc;
    --after-bg:#e8fff1;
    --after-bd:#c8f0d6;
    --kpi-bg:#f9fafb;
    --kpi-bd:#e5e7eb;
  }
  .page { max-width: 1180px; margin: 1rem auto; }
  .sticky-head{ position: sticky; top:0; z-index:5; background:#fff; padding:.5rem 0 .25rem; }
  .toolbar { gap:.5rem; }
  .chip{
    background:var(--chip);
    border:1px solid var(--chip-bd);
    padding:.15rem .5rem;
    border-radius:999px;
    font-size:.75rem;
  }
  .pill{
    display:inline-block;
    padding:.2rem .5rem;
    border-radius:999px;
    font-size:.85rem;
    line-height:1;
    border:1px solid var(--pill-bd);
    background:var(--pill-bg);
  }
  .pill.before s{ opacity:.65; }
  .pill.after{
    background:var(--after-bg);
    border-color:var(--after-bd);
    font-weight:700;
  }
  .arrow{ margin:0 .35rem; opacity:.6; }

  .kpi-grid{
    display:grid;
    grid-template-columns: repeat(5, minmax(0,1fr));
    gap:.75rem;
    margin:1rem 0 1.25rem;
  }
  @media (max-width: 991.98px){ .kpi-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
  @media (max-width: 575.98px){ .kpi-grid{ grid-template-columns: minmax(0,1fr); } }
  .kpi-card{
    border-radius:.75rem;
    border:1px solid var(--kpi-bd);
    background:var(--kpi-bg);
    padding:.6rem .75rem;
    display:flex;
    flex-direction:column;
    gap:.15rem;
  }
  .kpi-label{
    font-size:.75rem;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:var(--muted);
  }
  .kpi-value{ font-size:1.25rem; font-weight:700; }
  .kpi-sub{ font-size:.75rem; color:var(--muted); }

  .badge-aud{ background:#0ea5e9 !important; }
</style>

<div class="page">
  <div class="sticky-head">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-2">Histórico de Alterações de Contratos — <?= $scope_title ?></h5>
      <div class="toolbar d-flex">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExpandAll">Expandir todos</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCollapseAll">Recolher todos</button>
      </div>
    </div>

    <form class="row search-row g-2 align-items-center" method="get" action="">
      <?php if($contrato_id>0): ?>
        <input type="hidden" name="contrato_id" value="<?= (int)$contrato_id ?>">
      <?php endif; ?>

      <?php if($role >= 4): ?>
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
          <?php foreach(['TODOS','APROVADO','REJEITADO','PENDENTE'] as $opt): ?>
            <option value="<?= $opt ?>" <?= $status===$opt?'selected':'' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col">
        <label class="form-label mb-0 small">Busca livre</label>
        <input type="text" class="form-control form-control-sm" name="q" placeholder="Empresa, objeto, campo, valor..." value="<?= h($q) ?>">
      </div>
      <div class="col-auto">
        <label class="form-label mb-0">&nbsp;</label>
        <div><button class="btn btn-primary btn-sm">Aplicar</button></div>
      </div>
    </form>
  </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Total de registros</div>
        <div class="kpi-value"><?= (int)$kpiTotalRegistros ?></div>
        <div class="kpi-sub">solicitações + auditoria</div>
      </div>
    
      <div class="kpi-card">
        <div class="kpi-label">Solicitações</div>
        <div class="kpi-value"><?= (int)$kpiTotalSolicitacoes ?></div>
        <div class="kpi-sub">no período selecionado</div>
      </div>
    
      <div class="kpi-card">
        <div class="kpi-label">Pendentes de aprovação</div>
        <div class="kpi-value"><?= (int)$kpiPendentes ?></div>
        <div class="kpi-sub">
          <?php
            $denBase = ((int)$kpiTotalSolicitacoes > 0) ? (int)$kpiTotalSolicitacoes : (int)$kpiTotalRegistros;
            $pctPend = ($denBase > 0) ? round(((int)$kpiPendentes)*100/$denBase, 1) : 0;
            $txtBase = ((int)$kpiTotalSolicitacoes > 0) ? 'das solicitações' : 'dos registros';
            echo $pctPend.'% '.$txtBase;
          ?>
        </div>
      </div>
    
      <div class="kpi-card">
        <div class="kpi-label">Aprovadas</div>
        <div class="kpi-value"><?= (int)$kpiAprovados ?></div>
        <div class="kpi-sub">
          <?php
            $denBase = ((int)$kpiTotalSolicitacoes > 0) ? (int)$kpiTotalSolicitacoes : (int)$kpiTotalRegistros;
            $pctApr  = ($denBase > 0) ? round(((int)$kpiAprovados)*100/$denBase, 1) : 0;
            $txtBase = ((int)$kpiTotalSolicitacoes > 0) ? 'das solicitações' : 'dos registros';
            echo $pctApr.'% '.$txtBase;
          ?>
        </div>
      </div>
    
      <div class="kpi-card">
        <div class="kpi-label">Rejeitadas</div>
        <div class="kpi-value"><?= (int)$kpiRejeitados ?></div>
        <div class="kpi-sub">
          <?php
            $denBase = ((int)$kpiTotalSolicitacoes > 0) ? (int)$kpiTotalSolicitacoes : (int)$kpiTotalRegistros;
            $pctRej  = ($denBase > 0) ? round(((int)$kpiRejeitados)*100/$denBase, 1) : 0;
            $txtBase = ((int)$kpiTotalSolicitacoes > 0) ? 'das solicitações' : 'dos registros';
            echo $pctRej.'% '.$txtBase;
          ?>
        </div>
      </div>
    
      <div class="kpi-card">
        <div class="kpi-label">Tempo médio de aprovação</div>
        <div class="kpi-value"><?= h(fmt_tempo_medio($kpiTempoMedioSeg)) ?></div>
        <div class="kpi-sub">entre solicitação e decisão</div>
      </div>
    </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info mt-3">Nenhum registro de alterações encontrado com os filtros aplicados.</div>
  <?php else: ?>
    <?php foreach ($byContrato as $cid => $items): ?>
      <div class="contract-card mb-3 border rounded">
        <div class="contract-head bg-white border-bottom p-2 d-flex justify-content-between align-items-center">
          <div class="contract-title fw-bold">Contrato <?= (int)$cid ?></div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="chip"><?= h($items[0]['empresa'] ?? '') ?></span>
            <span class="chip">Diretoria: <?= h($items[0]['diretoria_ref'] ?? '') ?></span>
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
            $src = (string)($row['src'] ?? 'INBOX');

            $payload = ($src === 'INBOX')
              ? json_decode((string)($row['payload_json'] ?? ''), true)
              : null;

            $statusR  = strtoupper(trim((string)($row['status'] ?? 'PENDENTE')));

            if ($src === 'LOG') {
              $badge = 'success'; // LOG agora é APROVADO
            } else {
              $badge = ($statusR==='APROVADO'?'success':($statusR==='REJEITADO'?'danger':'secondary'));
            }

            $itemId  = 'c'.$cid.'-'.$src.'-'.$row['row_id'];

            // Solicitante / autor
            if ($src === 'LOG') {
              $autor = trim((string)($row['usuario_nome_log'] ?? '')) ?: 'Usuário não identificado';
              $dirAutor = trim((string)($row['diretoria_solicitante'] ?? ''));
              $fiscal = $autor . ($dirAutor !== '' ? ' — Diretoria '.$dirAutor : '');
            } else {
              $fiscal = '';
              foreach (['fiscal_nome','fiscal_email'] as $k) {
                if (!empty($row[$k])) { $fiscal = trim((string)$row[$k]); break; }
              }
              if ($fiscal === '') $fiscal = resolve_solicitante($row, $payload);
            }

            $decisorNome = ($src === 'INBOX') ? resolve_decisor($row, $payload) : null;
            $decisaoEm   = ($src === 'INBOX') ? resolve_decision_datetime($row, $payload) : null;

            // mudanças estruturadas (somente INBOX)
            $changes = [];
            if ($src === 'INBOX' && is_array($payload) && isset($payload['campos']) && is_array($payload['campos'])) {
              foreach ($payload['campos'] as $col => $novo) {
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

            // Auditoria: pega "último campo" igual ao form_contratos
            $ultimoCampo = '';
            if ($src === 'LOG' && !empty($row['detalhes_log'])) {
              $detLines = preg_split('/\r\n|\r|\n/', (string)$row['detalhes_log']);
              if (is_array($detLines) && count($detLines) > 0) {
                $ultimoCampo = trim((string)end($detLines));
              }
              if ($ultimoCampo === '') $ultimoCampo = trim((string)$row['detalhes_log']);
            }
          ?>
          <div class="accordion-item border-0 border-top">
            <h2 class="accordion-header" id="h-<?= $itemId ?>">
              <button class="accordion-button collapsed bg-white" type="button"
                      data-bs-toggle="collapse" data-bs-target="#b-<?= $itemId ?>"
                      aria-expanded="false" aria-controls="b-<?= $itemId ?>">
                <div class="w-100 d-flex justify-content-between align-items-center">
                  <div class="text-truncate text-muted">
                    <?= h($row['objeto'] ?? '') ?>
                    <?php if ($src === 'LOG'): ?>
                      <span class="badge badge-aud ms-2">AUDITORIA</span>
                    <?php endif; ?>
                  </div>
                  <span class="badge bg-<?= $badge ?>"><?= h($statusR) ?></span>
                </div>
              </button>
            </h2>

            <div id="b-<?= $itemId ?>" class="accordion-collapse collapse"
                 aria-labelledby="h-<?= $itemId ?>" data-bs-parent="#acc-<?= (int)$cid ?>">
              <div class="accordion-body pt-2 small">
                <div class="mb-2">
                  <div class="text-muted">
                    <?= ($src === 'LOG') ? 'Registrado por' : 'Solicitado por' ?>
                    <strong><?= h($fiscal ?: '—') ?></strong>
                    <?php if (!empty($row['created_at'])): ?>
                      em <?= h(date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?>
                    <?php endif; ?>
                  </div>

                  <?php if ($src === 'INBOX'): ?>
                    <?php if ($statusR === 'APROVADO' || $statusR === 'REJEITADO'): ?>
                      <div class="text-muted">
                        <?= ($statusR==='APROVADO'?'Aprovado':'Rejeitado') ?>
                        <?php if ($decisorNome): ?> por <strong><?= h($decisorNome) ?></strong><?php endif; ?>
                        <?php if ($decisaoEm): ?> em <?= h(date('d/m/Y H:i', strtotime((string)$decisaoEm))) ?><?php endif; ?>
                      </div>
                    <?php else: ?>
                      <div class="text-muted">Aguardando decisão dos níveis competentes.</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if (!empty($row['acao_log'])): ?>
                      <div class="text-muted">Ação: <strong><?= h((string)$row['acao_log']) ?></strong></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>

                <?php if ($src === 'INBOX'): ?>
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
                <?php else: ?>
                  <?php if ($ultimoCampo !== ''): ?>
                    <ul class="list-group list-group-flush mb-2">
                      <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div class="fw-semibold">Último campo</div>
                        <div class="ms-auto text-end">
                          <span class="pill after"><?= h($ultimoCampo) ?></span>
                        </div>
                      </li>
                    </ul>
                  <?php endif; ?>
                  <?php if (!empty($row['detalhes_log'])): ?>
                    <div class="alert alert-light border mb-0">
                      <div class="text-muted mb-1">Detalhes</div>
                      <div style="white-space:pre-wrap"><?= h((string)$row['detalhes_log']) ?></div>
                    </div>
                  <?php endif; ?>
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
