<?php require __DIR__ . '/php/roles.php'; ?>
<?php
// ===== Autenticação / sessão =====
require __DIR__ . '/php/require_auth.php';
require __DIR__ . '/php/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
    header('Location: /login_senha.php'); exit;
}
require __DIR__ . '/php/guard_index_fiscal.php';

// ===== DB =====
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/php/conn.php';            // $conn (mysqli)
require_once __DIR__ . '/php/diretoria_guard.php'; // diretoria_guard_where()

// Se suas queries usam alias "c" para emop_contratos, passe 'c'. Caso contrário, passe ''.
$__SCOPE_SQL = diretoria_guard_where($conn, '');   // ou 'c' se suas consultas usam "FROM emop_contratos c"

// ==== Helpers básicos ====
function numbr($v){ return number_format((float)$v, 2, ',', '.'); }
function q(mysqli $c, $v){ return $c->real_escape_string(trim((string)$v)); }
function db_scalar(mysqli $conn, string $sql){
    $res = $conn->query($sql);
    if(!$res){ throw new Exception("SQL scalar falhou: ".$conn->error); }
    $row = $res->fetch_row();
    return $row ? $row[0] : null;
}

/** Converte GET em array normalizado (sem strings vazias) */
function coh_to_array($v): array {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $item) {
            $item = trim((string)$item);
            if ($item !== '') $out[] = $item;
        }
        return $out;
    }
    $v = trim((string)$v);
    return $v === '' ? [] : [$v];
}

/** Escape HTML seguro */
if (!function_exists('e')) {
  function e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

/** strtoupper seguro mesmo sem mbstring */
function strtoupper_u(string $s): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}
/** Remove acentos de forma robusta (intl > iconv > fallback) */
function strip_accents_u(string $s): string {
    if ($s === '') return $s;
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $s);
        if (is_string($t)) return $t;
    }
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t)) return $t;
    }
    $map = [
      'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
      'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
      'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
      'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
      'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
      'Ç'=>'C','ç'=>'c'
    ];
    return strtr($s, $map);
}
/** Normalização canônica p/ comparar municípios */
function norm_mun(string $s): string {
    return strtoupper_u(strip_accents_u(trim($s)));
}

/** Trunca texto e adiciona “… mais” se passar do limite */
if (!function_exists('coh_truncate')) {
  function coh_truncate($text, int $limit = 90): string {
    $text = trim((string)($text ?? ''));
    if ($text === '' || $limit <= 0) return $text;

    if (function_exists('mb_strlen')) {
      if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
      $cut = mb_substr($text, 0, $limit, 'UTF-8');
    } else {
      if (strlen($text) <= $limit) return $text;
      $cut = substr($text, 0, $limit);
    }
    return rtrim($cut) . '… mais';
  }
}

/** Badge colorida para Status do contrato */
if (!function_exists('coh_status_badge')) {
  function coh_status_badge($status_raw): string {
    $status = trim((string)$status_raw);
    $class = 'badge';

    switch ($status) {
      case 'EM VIGOR':
      case 'EM EXECUÇÃO':
        $class .= ' bg-success';
        break;
      case 'SUSPENSO':
        $class .= ' bg-warning text-dark';
        break;
      case 'ENCERRADO':
      case 'ENCERRADA':
        $class .= ' bg-primary';
        break;
      case 'RESCINDIDO':
        $class .= ' bg-danger';
        break;
      default:
        $class .= ' bg-secondary';
        break;
    }
    return '<span class="' . $class . '">' . e($status) . '</span>';
  }
}

/** Divide um campo de municípios multi-valor */
function split_municipios(string $raw): array {
    if ($raw === '') return [];
    $tokens = preg_split('/[,;\/\|\r\n]+|\s[–—-]\s/u', $raw);
    $out = [];
    foreach($tokens as $t){
        $t = trim($t);
        if ($t !== '') $out[] = $t;
    }
    return $out;
}

/** Condição SQL p/ município multi-valor */
function sql_cond_municipio_contains(mysqli $conn, string $col, string $municipioRaw): string {
    $mun  = strtoupper(q($conn, $municipioRaw));
    $colU = "UPPER(COALESCE($col,''))";
    $csv  = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($colU, ';', ','), '/', ','), ' - ', ','), ' – ', ','), ' — ', ',')";
    $csv2 = "REPLACE(REPLACE($csv, ', ', ','), ' ,', ',')";
    $wrapped = "CONCAT(',', $csv2, ',')";
    return "$wrapped LIKE '%," . $mun . ",%'";
}

/** WHERE com suporte a multi-seleção */
function build_where(mysqli $conn, array $filters, string $yearExpr, array $extra = []) : string {
    $conds = [];

    // Diretoria (multi)
    if (!empty($filters['diretoria'])) {
        $dirs = array_values(array_filter((array)$filters['diretoria'], fn($v)=>trim((string)$v) !== ''));
        if ($dirs) {
            $in = implode("','", array_map(fn($v)=>q($conn, $v), $dirs));
            $conds[] = "`Diretoria` IN ('{$in}')";
        }
    }

    // Secretaria (multi)
    if (!empty($filters['secretaria'])) {
        $secs = array_values(array_filter((array)$filters['secretaria'], fn($v)=>trim((string)$v) !== ''));
        if ($secs) {
            $in = implode("','", array_map(fn($v)=>q($conn, $v), $secs));
            $conds[] = "`Secretaria` IN ('{$in}')";
        }
    }

    // Município (multi)
    if (!empty($filters['municipio'])) {
        $muns = array_values(array_filter((array)$filters['municipio'], fn($v)=>trim((string)$v) !== ''));
        if ($muns) {
            $munConds = [];
            foreach ($muns as $m) {
                $munConds[] = sql_cond_municipio_contains($conn, "`Municipio`", $m);
            }
            if ($munConds) {
                $conds[] = '(' . implode(' OR ', $munConds) . ')';
            }
        }
    }

    // Ano (multi)
    if (!empty($filters['ano'])) {
        $anosRaw = array_values((array)$filters['ano']);
        $anos = [];
        foreach ($anosRaw as $a) {
            $a = trim((string)$a);
            if ($a !== '' && ctype_digit($a)) $anos[] = (int)$a;
        }
        if ($anos) {
            $conds[] = "$yearExpr IN (" . implode(',', $anos) . ")";
        }
    }

    // Status (multi)
    if (!empty($filters['status'])) {
        $sts = array_values(array_filter((array)$filters['status'], fn($v)=>trim((string)$v) !== ''));
        if ($sts) {
            $in = implode("','", array_map(fn($v)=>q($conn, $v), $sts));
            $conds[] = "`Status` IN ('{$in}')";
        }
    }

    if (!empty($extra)) $conds = array_merge($conds, $extra);
    return $conds ? (" WHERE ".implode(" AND ", $conds)) : "";
}

/** * DISTINCT simples corrigido: 
 * - Usa array completo de filtros (para dependência correta)
 * - Evita filtrar por si mesmo (resolve o problema de sumir opções)
 */
function distinct_options(mysqli $conn, string $field, array $filters, string $yearExpr, array $scope = [], array $post = []) : array {
    global $__SCOPE_SQL;

    // Se $scope estiver vazio, não filtra nada. Se tiver itens, filtra apenas pelo que está no escopo.
    $shouldFilter = fn($key) => !empty($scope) && in_array($key, $scope);

    $f = [
        'diretoria' => $shouldFilter('diretoria')  ? ($filters['diretoria'] ?? '') : '',
        'secretaria'=> $shouldFilter('secretaria') ? ($filters['secretaria'] ?? '') : '',
        'municipio' => $shouldFilter('municipio')  ? ($filters['municipio'] ?? '') : '',
        'ano'       => $shouldFilter('ano')        ? ($filters['ano'] ?? '') : '',
        'status'    => $shouldFilter('status')     ? ($filters['status'] ?? []) : [],
    ];
    $where = build_where($conn, $f, $yearExpr, $post);

    if ($where === '') { $where = " WHERE 1=1 $__SCOPE_SQL"; }
    else { $where .= " $__SCOPE_SQL"; }

    $sql = "SELECT DISTINCT TRIM(COALESCE(`$field`,'')) AS v
            FROM emop_contratos $where
            HAVING v <> ''
            ORDER BY v";
    $out = [];
    if ($r = $conn->query($sql)) while($row = $r->fetch_assoc()) $out[] = $row['v'];
    return $out;
}

/** DISTINCT especial para Município corrigido */
function distinct_municipios(mysqli $conn, array $filters, string $yearExpr, array $scope = [], array $post = []) : array {
    global $__SCOPE_SQL;

    $shouldFilter = fn($key) => !empty($scope) && in_array($key, $scope);

    $f = [
        'diretoria' => $shouldFilter('diretoria')  ? ($filters['diretoria'] ?? '') : '',
        'secretaria'=> $shouldFilter('secretaria') ? ($filters['secretaria'] ?? '') : '',
        'municipio' => '', // Nunca filtra município ao gerar lista de municípios
        'ano'       => $shouldFilter('ano')        ? ($filters['ano'] ?? '') : '',
        'status'    => $shouldFilter('status')     ? ($filters['status'] ?? []) : [],
    ];
    $where = build_where($conn, $f, $yearExpr, $post);
    if ($where === '') { $where = " WHERE 1=1 $__SCOPE_SQL"; }
    else { $where .= " $__SCOPE_SQL"; }

    $sql = "SELECT TRIM(COALESCE(`Municipio`,'')) AS m
            FROM emop_contratos $where
            AND TRIM(COALESCE(`Municipio`,'')) <> ''";
    $set = [];
    if($r = $conn->query($sql)){
        while($row = $r->fetch_assoc()){
            foreach (split_municipios($row['m']) as $mun){
                $k = norm_mun($mun);
                if ($k !== '') $set[$k] = $set[$k] ?? trim($mun);
            }
        }
    }
    $list = array_values($set);
    usort($list, function($a,$b){
        return strnatcasecmp(strip_accents_u($a), strip_accents_u($b));
    });
    return $list;
}

// ==== Filtros (GET) ====
// Multi-seleção
$F_dir_arr    = coh_to_array($_GET['diretoria']  ?? []);
$F_sec_arr    = coh_to_array($_GET['secretaria'] ?? []);
$F_mun_arr    = coh_to_array($_GET['municipio']  ?? []);
$F_ano_arr    = coh_to_array($_GET['ano']        ?? []);

// Status
if (array_key_exists('status', $_GET)) {
    $F_status_arr = coh_to_array($_GET['status']);
} else {
    $F_status_arr = ['EM VIGOR'];
}

// Versões single (mantidas para compatibilidade e evitar erro de variável indefinida)
$F_dir_first    = $F_dir_arr[0]    ?? '';
$F_sec_first    = $F_sec_arr[0]    ?? '';
$F_mun_first    = $F_mun_arr[0]    ?? '';
$F_ano_first    = $F_ano_arr[0]    ?? '';
$F_status_first = $F_status_arr[0] ?? 'EM VIGOR';

// Compatibilidade
$F_dir    = $F_dir_first;
$F_sec    = $F_sec_first;
$F_mun    = $F_mun_first;
$F_ano    = $F_ano_first;
$F_status = $F_status_first;

// Filtros Array (O PRINCIPAL para o WHERE)
$filters = [
    'diretoria' => $F_dir_arr,
    'secretaria'=> $F_sec_arr,
    'municipio' => $F_mun_arr,
    'ano'       => $F_ano_arr,
    'status'    => $F_status_arr,
];

// Filtros Single (Apenas para evitar Warning se usado em views antigas, mas NÃO usado na lógica de distinct)
$filters_single = [
    'diretoria' => $F_dir_first,
    'secretaria'=> $F_sec_first,
    'municipio' => $F_mun_first,
    'ano'       => $F_ano_first,
    'status'    => $F_status_arr, // Status sempre array no nosso logic
];

// Expressão de ano
$yearExpr = "YEAR(`Data_Inicio`)";

// WHERE principal
$WHERE = build_where($conn, $filters, $yearExpr);
if ($WHERE === '') {
    $WHERE = " WHERE 1=1 $__SCOPE_SQL";
} else {
    $WHERE .= " $__SCOPE_SQL";
}

// ==== Opções para selects ====
// CORREÇÃO: Passamos $filters (array), e não $filters_single.
// O array $scope define quais filtros DEVEM afetar esta lista.
// Ex: A lista de Diretorias deve ser filtrada por Secretaria, Municipio e Ano, mas NÃO por Diretoria (para não esconder as outras opções).

$opts_diretoria  = distinct_options($conn, 'Diretoria',  $filters, $yearExpr, ['secretaria','municipio','ano']);
$opts_secretaria = distinct_options($conn, 'Secretaria', $filters, $yearExpr, ['diretoria','municipio','ano']);

$opts_ano        = (function() use ($conn, $filters, $yearExpr, $__SCOPE_SQL){
    // Para Ano, queremos que respeite todos os filtros, exceto o próprio Ano
    $f = [
        'diretoria' => $filters['diretoria'] ?? [],
        'secretaria'=> $filters['secretaria'] ?? [],
        'municipio' => $filters['municipio'] ?? [],
        'ano'       => [], // Vazio para listar todos os anos disponíveis dentro dos outros filtros
        'status'    => $filters['status'] ?? [],
    ];
    $where = build_where($conn, $f, $yearExpr, ["$yearExpr >= 2021"]);
    if ($where === '') { $where = " WHERE 1=1 $__SCOPE_SQL"; }
    else { $where .= " $__SCOPE_SQL"; }
    
    $sql = "SELECT DISTINCT $yearExpr AS ano FROM emop_contratos $where ORDER BY ano DESC";
    $out = [];
    if ($r = $conn->query($sql)) while($row = $r->fetch_assoc()) if(!empty($row['ano'])) $out[] = (string)$row['ano'];
    return $out;
})();

$opts_municipio  = distinct_municipios($conn, $filters, $yearExpr, ['diretoria','secretaria','ano']);

// Opções de Status
$opts_status = [];
$sqlStatus = "SELECT DISTINCT TRIM(COALESCE(`Status`,'')) AS v
              FROM emop_contratos
              WHERE 1=1 $__SCOPE_SQL
              HAVING v <> ''
              ORDER BY v";
if ($r = $conn->query($sqlStatus)) {
    while ($row = $r->fetch_assoc()) $opts_status[] = $row['v'];
}

// ==== Opções para selects ====
$opts_diretoria  = distinct_options($conn, 'Diretoria',  $filters_single, $yearExpr, ['secretaria','municipio','ano']);
$opts_secretaria = distinct_options($conn, 'Secretaria', $filters_single, $yearExpr, ['diretoria','municipio','ano']);
$opts_ano        = (function() use ($conn, $filters_single, $yearExpr, $__SCOPE_SQL){
    $f = [
        'diretoria'=>$filters_single['diretoria']??'',
        'secretaria'=>$filters_single['secretaria']??'',
        'municipio'=>$filters_single['municipio']??'',
        'ano'=>'',
        'status'=>$filters_single['status'] ?? [],
    ];
    $where = build_where($conn, $f, $yearExpr, ["$yearExpr >= 2021"]);
    if ($where === '') { $where = " WHERE 1=1 $__SCOPE_SQL"; }
    else { $where .= " $__SCOPE_SQL"; }
    $sql = "SELECT DISTINCT $yearExpr AS ano FROM emop_contratos $where ORDER BY ano DESC";
    $out = [];
    if ($r = $conn->query($sql)) while($row = $r->fetch_assoc()) if(!empty($row['ano'])) $out[] = (string)$row['ano'];
    return $out;
})();
$opts_municipio  = distinct_municipios($conn, $filters_single, $yearExpr, ['diretoria','secretaria','ano']);

// Opções de Status
$opts_status = [];
$sqlStatus = "SELECT DISTINCT TRIM(COALESCE(`Status`,'')) AS v
              FROM emop_contratos
              WHERE 1=1 $__SCOPE_SQL
              HAVING v <> ''
              ORDER BY v";
if ($r = $conn->query($sqlStatus)) {
    while ($row = $r->fetch_assoc()) $opts_status[] = $row['v'];
}

// ==== Consultas principais ====
$error_msg = null;
$pizza_rows = $pizza_labels = $pizza_values = [];
$municipio_counts = [];
$matriz_rows = [];

$total_contratos = 0;
$valor_total = 0.0;
$num_secretarias = 0;
$num_municipios = 0;

try {
    $total_contratos = (int) db_scalar($conn, "SELECT COUNT(*) FROM emop_contratos" . $WHERE);
    $valor_total     = (float) db_scalar($conn, "SELECT COALESCE(SUM(`Valor_Do_Contrato`),0) FROM emop_contratos" . $WHERE);
    $num_secretarias = (int) db_scalar($conn, "SELECT COUNT(DISTINCT `Secretaria`) FROM emop_contratos" . $WHERE);

    // Pizza por Diretoria (respeita os MESMOS filtros da matriz)
    $sql_pie = "
        SELECT COALESCE(`Diretoria`, 'Não informada') AS diretoria, COUNT(*) AS qtd
        FROM emop_contratos $WHERE
        GROUP BY COALESCE(`Diretoria`, 'Não informada')
        ORDER BY qtd DESC";
    if(!$res = $conn->query($sql_pie)) throw new Exception("SQL pizza falhou: ".$conn->error);
    while($row = $res->fetch_assoc()) $pizza_rows[] = $row;

    // Mapa/KPI Municípios — usa exatamente o mesmo WHERE da matriz
    $WHERE_map = $WHERE . " AND TRIM(COALESCE(`Municipio`,'')) <> ''";

    $sql_map = "SELECT `Municipio` AS m FROM emop_contratos $WHERE_map";
    $municipio_counts = [];
    $uniq = [];

    if(!$res = $conn->query($sql_map)) throw new Exception("SQL municipios falhou: ".$conn->error);
    while($row = $res->fetch_assoc()){
        foreach (split_municipios($row['m'] ?? '') as $mun){
            $norm = norm_mun($mun);
            if ($norm === '') continue;
            $displayKey = strtoupper_u(trim($mun));
            $municipio_counts[$displayKey] = ($municipio_counts[$displayKey] ?? 0) + 1;
            $uniq[$norm] = true;
        }
    }
    $num_municipios = count($uniq);

    // Matriz
    $sql_tbl = "
        SELECT
            `id`,
            COALESCE(`No_do_Contrato`,'')   AS numero_contrato,
            COALESCE(`Objeto_Da_Obra`,'')   AS Objeto_Da_Obra,
            COALESCE(`Diretoria`,'')        AS diretoria,
            COALESCE(`Secretaria`,'')       AS secretaria,
            COALESCE(`Municipio`,'')        AS municipio,
            COALESCE(`Empresa`,'')          AS empresa,
            COALESCE(`Valor_Do_Contrato`,0) AS valor_total,
            COALESCE(`Status`,'')           AS status
        FROM emop_contratos
        $WHERE
        ORDER BY `Diretoria`, `Municipio`, `No_do_Contrato`";
    if(!$res = $conn->query($sql_tbl)) throw new Exception("SQL matriz falhou: ".$conn->error);
    while($row = $res->fetch_assoc()) $matriz_rows[] = $row;

    $pizza_labels = array_column($pizza_rows, 'diretoria');
    $pizza_values = array_map('intval', array_column($pizza_rows, 'qtd'));
} catch (Throwable $e) {
    $error_msg = $e->getMessage();
}

// Última atualização
$ultima_data = '—';
if ($result = $conn->query("
  SELECT UPDATE_TIME 
  FROM information_schema.tables 
  WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'emop_contratos'
")) {
    if ($row = $result->fetch_assoc()) {
        if (!empty($row['UPDATE_TIME'])) $ultima_data = date('d/m/Y H:i', strtotime($row['UPDATE_TIME']));
    }
}

// ==== Partials: abre .coh-content no header ====
require __DIR__ . "/partials/header.php";
include __DIR__ . '/php/lgpd_guard.php';

$__role_int = (int)($_SESSION['role'] ?? 0);
$__just = (int)($_SESSION['just_logged_in'] ?? 0);
?>
<script>
(function(){
  const ROLE = <?= json_encode($__role_int) ?>;
  const JUST_LOGGED_IN = <?= $__just ? 'true' : 'false' ?>;

  async function fetchPending(){
    try{
      const r = await fetch('/php/notificacoes_reset_count.php', {cache:'no-store', credentials:'same-origin'});
      const j = await r.json();
      return (j && typeof j.pending === 'number') ? j.pending : 0;
    }catch(e){ console.warn('count pend fail', e); return 0; }
  }

  async function ensureModalAndOpen(){
    if (typeof bootstrap === 'undefined') { return; }
    const modalEl = document.getElementById('modalResetInbox');
    if (!modalEl){ return; }
    const cont = document.getElementById('modalResetContent');
    if (cont){
      cont.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-warning" role="status"></div><br>Carregando...</div>';
      try{
        const r = await fetch('/php/reset_admin_inbox.php', {cache:'no-store', headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'});
        cont.innerHTML = await r.text();
      }catch(e){
        cont.innerHTML = '<div class="alert alert-danger m-3">Falha ao carregar as solicitações.</div>';
      }
    }
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  }

  document.addEventListener('DOMContentLoaded', async ()=>{
    if (!(ROLE >= 5 && JUST_LOGGED_IN)) return;
    const pending = await fetchPending();
    if (pending > 0){ await ensureModalAndOpen(); }
  });
})();
</script>

<div class="coh-page">

  <div class="sidebar-backdrop" aria-hidden="true"></div>

  <!-- libs -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <!-- Choices.js -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

  <style>
    :root{
      --coh-bg:#ffffff; --coh-surface:#f8fafc; --coh-border:#e5e7eb;
      --coh-text:#111827; --coh-muted:#6b7280; --coh-primary:#2563eb;
      --coh-topbar-h: 56px;
    }

    /* Labels dos filtros em uma linha (inclui Ano) */
    #filtersForm .form-label{
      white-space: nowrap;
    }

    /* Colunas de filtro com layout consistente */
    #filtersForm .filter-col{
      display:flex;
      flex-direction:column;
    }
    #filtersForm .filter-col .choices{
      flex:1 1 auto;
      width:100%;
      margin-bottom:0;
    }
    #filtersForm .filter-col .choices__inner{
      width:100%;
    }

    /* ===== HERO ===== */
    .coh-content .page-hero{
      background: linear-gradient(180deg,#f5f7fb 0%,#ffffff 100%);
      border-bottom: 1px solid var(--coh-border);
      padding: 20px 0;
    }
    .coh-content .page-title{
      font-weight: 800; letter-spacing: .2px; font-size: clamp(20px, 2.6vw, 32px);
      color: var(--coh-text); margin: 0; display: block; text-align: center;
    }
    .coh-content .page-badge{ margin-top: 8px; text-align:center; }

    /* ===== KPIs ===== */
    .kpi-grid { --kpi-radius: 16px; }
    .kpi-card{
      height: 100%;
      border: 1px solid var(--coh-border); border-radius: var(--kpi-radius);
      background: linear-gradient(180deg, #f9fbff 0%, #f3f6fb 100%);
      padding: 18px 18px 16px;
      box-shadow: 0 2px 10px rgba(16,24,40,0.06);
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      text-align: center; gap: 10px;
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .kpi-card:hover{ transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15,23,42,0.10); }
    .kpi-icon{ width: 44px; height: 44px; border-radius: 12px; background: #e8f1ff; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; color: #1d4ed8; }
    .kpi-label{ color: var(--coh-muted); font-size: 13px; font-weight: 600; letter-spacing: .2px; }
    .kpi-number{ display: inline-flex; align-items: baseline; justify-content: center; gap: 6px; margin-top: 2px; flex-wrap: wrap; }
    .kpi-currency{ font-size: clamp(12px, 1.1vw, 15px); font-weight: 800; color: #1f2937; opacity: .9; line-height: 1; }
    .kpi-value{ font-size: clamp(24px, 2.4vw, 36px); font-weight: 800; line-height: 1.1; color: #0f172a; letter-spacing: .2px; }
    
    /* ===== Ajustes visuais dos filtros (Choices) ===== */
    .coh-filters .choices__inner {
      min-height: 44px;
      padding: 4px 8px;
    }
    .coh-filters .form-select {
      min-height: 44px;
    }

    /* ===== GRÁFICOS / MAPA ===== */
    .chart-card{
      background:var(--coh-surface); border:1px solid var(--coh-border); border-radius:16px;
      padding:14px; min-height:520px; height:auto; overflow:visible;
      display:flex; flex-direction:column;
    }
    #pieDiretoria{ display:block; width:100% !important; height:520px !important; }
    #map{
      display:block; width:100%; height:100%; min-height:520px;
      border-radius:12px; background:#eef2ff; flex:1 1 auto;
    }
    .leaflet-control-attribution{ display:none; }
    .info{
      padding:10px 14px; background:#fff; color:var(--coh-text);
      border-radius:8px; border:1px solid var(--coh-border);
      box-shadow:0 4px 12px rgba(0,0,0,.06);
      font:14px/1.2 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
    }

    /* ===== Tabela Matriz ===== */
    .table-card{
      background:var(--coh-surface);
      border:1px solid var(--coh-border);
      border-radius:16px;
      padding:12px 14px;
      box-shadow:0 4px 14px rgba(15,23,42,0.06);
    }
    
    /* área rolável */
    .table-wrap{
      max-height:70vh;
      overflow-y:auto;
      overflow-x:hidden;             /* sem scroll horizontal */
      -webkit-overflow-scrolling: touch;
      border-radius: 12px;
      position: relative;            /* importante pro truque do header */
    }
    
    /* tabela */
    .table-matriz{
      width:100%;
      table-layout:auto;
      border-collapse:separate !important;
      border-spacing:0;
    }
    
    /* cabeçalho */
    .table-matriz thead th{
      font-size:0.78rem;
      text-transform:uppercase;
      letter-spacing:.04em;
      color:var(--coh-muted);
      background:linear-gradient(180deg, #f9fafb 0%, #eef2ff 100%);
      border-bottom:1px solid var(--coh-border);
      padding:.40rem .55rem;
      border-right:1px solid rgba(148,163,184,0.6);
    }
    .table-matriz thead th:last-child{
      border-right:none;
    }
    
    /* corpo: sem grade nas linhas */
    .table-matriz tbody td{
      font-size:0.86rem;
      line-height:1.35;
      vertical-align:top;
      padding:.45rem .55rem;
      border-top:none;
      border-right:none;
    }
    
    .table-matriz tbody tr:hover{
      background:rgba(37,99,235,0.04);
    }
    
    /* Nº DO CONTRATO: título normal, linhas azuis */
    .table-matriz thead th.sei-cell{
      width:145px;
      max-width:145px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:clip;
    }
    
    .table-matriz tbody td.sei-cell{
      width:145px;
      max-width:145px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:clip;
      font-size:0.80rem;
      line-height:1.25;
      font-weight:600;
      color:var(--coh-primary); /* azul só nas linhas */
    }
    .table-matriz .diretoria-cell{
      max-width:90px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .table-matriz .secretaria-cell{
      max-width:110px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .table-matriz .objeto-cell,
    .table-matriz .empresa-cell{
      max-width:240px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .table-matriz .municipio-cell{
      max-width:170px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .table-matriz .valor-cell{
      white-space:nowrap;
      font-weight:600;
      text-align:right;
      font-variant-numeric: tabular-nums;
    }
    .table-matriz .status-cell{
      text-align:center;
      white-space:nowrap;
    }
    .table-matriz .status-cell .badge{
      font-size:0.72rem;
      padding:0.20rem 0.70rem;
      border-radius:999px;
      letter-spacing:.05em;
    }

    /* ===== Resize de colunas na Matriz ===== */
    .table-matriz thead th .col-resizer{
      position: absolute;
      top: 0;
      right: -3px;
      width: 6px;
      height: 100%;
      cursor: col-resize;
      user-select: none;
      background: transparent;
    }
    .table-matriz thead th .col-resizer::before{
      content: '';
      position:absolute;
      top: 15%;
      bottom: 15%;
      left: 50%;
      width: 1px;
      background: rgba(148,163,184,0.6);
    }
    html.col-resize-active,
    html.col-resize-active *{
      cursor: col-resize !important;
    }

    /* ===== Choices.js ===== */
    .choices__inner{
      min-height: 2.4rem;
      border-radius: .375rem;
      border-color: var(--coh-border);
      padding-top: .25rem;
      padding-bottom: .25rem;
    }
    .choices.is-focused .choices__inner{
      border-color:#2563eb;
      box-shadow:0 0 0 .15rem rgba(37,99,235,.15);
    }
    .choices__list--multiple .choices__item{
      background-color:#e0f2fe;
      border-radius:999px;
      border:1px solid #bfdbfe;
      color:#0f172a;
      font-size:.78rem;
      margin-bottom:2px;
    }
    .choices__list--dropdown{
      z-index:998;
    }
  </style>

  <!-- ===== HERO ===== -->
  <div class="page-hero py-4" style="background: linear-gradient(180deg, #f8f9fb 0%, #ffffff 100%); border-bottom: 2px solid #e3e6ea;">
    <div class="coh-wrap fit center">
      <div class="hero-inner text-center">
        <div class="mb-3">
          <img src="assets/emop-cohidro.jpg" alt="Logo EMOP Cohidro" style="height:120px; max-width:100%; object-fit:contain;">
        </div>
        <h1 class="page-title fw-bold mb-2 text-center" 
            style="font-size: 2.2rem; color:#0d47a1; text-shadow: 0 1px 2px rgba(0,0,0,0.1); letter-spacing: 0.5px;">
          <div class="title-lines" style="display:flex; flex-direction:column; align-items:center; line-height:1.25;">
            <span class="sigla" style="font-size:2.3rem; font-weight:800; color:#0d47a1;">SIGCE</span>
            <span class="nome" style="font-size:1.3rem; color:#1a237e;">Sistema de Informação Gerencial de Contratos EMOP</span>
          </div>
        </h1>
        <div class="page-badge mt-2">
          <span class="badge text-bg-primary fw-semibold px-2 py-1" 
                style="font-size:0.9rem; border-radius:10px; background-color:#1976d2;">
            Última atualização <?= e($ultima_data) ?>
          </span>
        </div>
      </div>
    </div>
  </div>

  <div class="coh-wrap fit center py-4">

    <?php if($error_msg): ?>
      <div class="alert alert-danger"><strong>Erro:</strong> <?= e($error_msg) ?></div>
    <?php endif; ?>

    <!-- Filtros -->
    <form id="filtersForm" class="row g-2 mb-3 justify-content-center coh-filters" method="get" action="">
      <?php
        $userDir  = $_SESSION['diretoria'] ?? '';
        $userRole = (int)($_SESSION['role'] ?? 0);
        $liberaTodas = in_array($userDir, ['PRES','GER','DEV'], true) || $userRole >= 5;
      ?>

      <!-- Diretoria -->
      <div class="col-12 col-lg-2 filter-col">
        <label class="form-label">Diretoria</label>
        <?php if ($liberaTodas): ?>
          <select name="diretoria[]" class="form-select js-choices-multi" multiple
                  data-placeholder="Todas as diretorias">
            <?php foreach($opts_diretoria as $opt): ?>
              <option value="<?= e($opt) ?>" <?= in_array($opt, $F_dir_arr, true) ? 'selected' : ''; ?>>
                <?= e($opt) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <select name="diretoria" class="form-select" disabled>
            <option value="<?= e($userDir) ?>" selected><?= e($userDir) ?></option>
          </select>
          <input type="hidden" name="diretoria" value="<?= e($userDir) ?>">
        <?php endif; ?>
      </div>

      <!-- Secretaria -->
      <div class="col-12 col-lg-2 filter-col">
        <label class="form-label">Secretaria</label>
        <select name="secretaria[]" class="form-select js-choices-multi" multiple
                data-placeholder="Todas as secretarias">
          <?php foreach ($opts_secretaria as $opt): ?>
            <option value="<?= e($opt) ?>" <?= in_array($opt, $F_sec_arr, true) ? 'selected' : ''; ?>>
              <?= e($opt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Município -->
      <div class="col-12 col-lg-2 filter-col">
        <label class="form-label">Município</label>
        <select name="municipio[]" class="form-select js-choices-multi" multiple
                data-placeholder="Todos os municípios">
          <?php
            $normSelected = array_map('norm_mun', $F_mun_arr);
            $alreadyPrintedExtra = [];
            if (!empty($F_mun_arr)) {
                foreach ($F_mun_arr as $munSel) {
                    $n = norm_mun($munSel);
                    $exists = false;
                    foreach ($opts_municipio as $opt) {
                        if (norm_mun($opt) === $n) { $exists = true; break; }
                    }
                    if (!$exists && !isset($alreadyPrintedExtra[$n])) {
                        $alreadyPrintedExtra[$n] = true;
                        echo '<option value="'.e($munSel).'" selected>'.e($munSel).'</option>';
                    }
                }
            }
            foreach ($opts_municipio as $opt):
              $selected = in_array(norm_mun($opt), $normSelected, true) ? 'selected' : '';
          ?>
              <option value="<?= e($opt) ?>" <?= $selected ?>><?= e($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Ano -->
      <div class="col-12 col-lg-2 filter-col">
        <label class="form-label">Ano (Data de Início)</label>
        <select name="ano[]" class="form-select js-choices-multi" multiple
                data-placeholder="Todos os anos">
          <?php foreach ($opts_ano as $ano): ?>
            <option value="<?= e($ano) ?>" <?= in_array((string)$ano, $F_ano_arr, true) ? 'selected' : '' ?>>
              <?= e($ano) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Status -->
      <div class="col-12 col-lg-2 filter-col">
        <label class="form-label">Status</label>
        <select name="status[]" class="form-select js-choices-multi" multiple
                data-placeholder="Status dos contratos">
          <?php foreach ($opts_status as $opt): ?>
            <option value="<?= e($opt) ?>" <?= in_array($opt, $F_status_arr, true) ? 'selected' : '' ?>>
              <?= e($opt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Botões: Aplicar + Limpar -->
      <div class="col-12 col-lg-auto d-flex justify-content-center justify-content-lg-end mt-2 mt-lg-0">
        <div class="btn-group w-100 w-lg-auto">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-funnel"></i> Aplicar filtros
          </button>
          <a class="btn btn-outline-secondary"
             href="<?= strtok($_SERVER["REQUEST_URI"],'?') ?>">
            Limpar filtros
          </a>
        </div>
      </div>
    </form>

    <!-- KPIs -->
    <div class="row g-3 kpi-grid">
      <div class="col-12 col-md-6 col-lg-3">
        <div class="kpi-card">
          <div class="kpi-icon"><i class="bi bi-folder2-open"></i></div>
          <div class="kpi-label">Total de contratos</div>
          <div class="kpi-number"><span class="kpi-value"><?= (int)$total_contratos ?></span></div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-3">
        <div class="kpi-card">
          <div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div>
          <div class="kpi-label">Valor total de contratos</div>
          <div class="kpi-number"><span class="kpi-currency">R$</span><span class="kpi-value"><?= numbr($valor_total) ?></span></div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-3">
        <div class="kpi-card">
          <div class="kpi-icon"><i class="bi bi-buildings"></i></div>
          <div class="kpi-label">Secretarias atendidas</div>
          <div class="kpi-number"><span class="kpi-value"><?= (int)$num_secretarias ?></span></div>
        </div>
      </div>
      <div class="col-12 col-md-6 col-lg-3">
        <div class="kpi-card">
          <div class="kpi-icon"><i class="bi bi-geo-alt"></i></div>
          <div class="kpi-label">Municípios atendidos</div>
          <div class="kpi-number"><span class="kpi-value"><?= (int)$num_municipios ?></span></div>
        </div>
      </div>
    </div>

    <!-- Gráficos -->
    <div class="row g-3 mt-1">
      <div class="col-12 col-lg-4">
        <h5 class="chart-title">Contratos por Diretoria</h5>
        <div class="chart-card">
          <canvas id="pieDiretoria"></canvas>
        </div>
      </div>
      <div class="col-12 col-lg-8">
        <h5 class="chart-title">Contratos por Municípios</h5>
        <div class="chart-card">
          <div id="map"></div>
        </div>
      </div>
    </div>

    <!-- Matriz -->
    <div class="row g-3 mt-2">
      <div class="col-12">
        <div class="table-card">
          <h5 class="mb-3 d-flex justify-content-between align-items-center">
            <span>Matriz de Contratos</span>
            <span class="badge bg-light text-muted border" style="font-weight:500;">
              <?= count($matriz_rows) ?> contrato(s)
            </span>
          </h5>
          <div class="table-wrap">
            <table class="table table-sm table-hover align-middle table-matriz">
              <thead>
                <tr>
                  <th class="sei-cell" data-sort-type="text">Nº do Contrato</th>
                  <th data-sort-type="text">Objeto da Obra</th>
                  <th class="diretoria-cell" data-sort-type="text">Diretoria</th>
                  <th class="secretaria-cell" data-sort-type="text">Secretaria</th>
                  <th data-sort-type="text">Município</th>
                  <th data-sort-type="text">Empresa</th>
                  <th data-sort-type="num">Valor (R$)</th>
                  <th data-sort-type="text">Status</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!empty($matriz_rows)): foreach($matriz_rows as $row): ?>
                <tr class="contrato-row" data-id="<?= (int)($row['id'] ?? 0) ?>" style="cursor:pointer">
                  <td class="sei-cell">
                    <span title="<?= e($row['numero_contrato'] ?? '') ?>">
                      <?= e($row['numero_contrato'] ?? '') ?>
                    </span>
                  </td>
                  <td class="objeto-cell">
                    <span title="<?= e($row['Objeto_Da_Obra'] ?? '') ?>">
                      <?= e(coh_truncate($row['Objeto_Da_Obra'] ?? '', 90)) ?>
                    </span>
                  </td>
                  <td class="diretoria-cell"><?= e($row['diretoria'] ?? '') ?></td>
                  <td class="secretaria-cell"><?= e($row['secretaria'] ?? '') ?></td>
                  <td class="municipio-cell">
                    <span title="<?= e($row['municipio'] ?? '') ?>">
                      <?= e(coh_truncate($row['municipio'] ?? '', 60)) ?>
                    </span>
                  </td>
                  <td class="empresa-cell">
                    <span title="<?= e($row['empresa'] ?? '') ?>">
                      <?= e(coh_truncate($row['empresa'] ?? '', 60)) ?>
                    </span>
                  </td>
                  <td class="valor-cell">
                    <?= numbr($row['valor_total'] ?? 0) ?>
                  </td>
                  <td class="status-cell">
                    <?= coh_status_badge($row['status'] ?? '') ?>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center text-muted">Nenhum contrato encontrado para os filtros aplicados.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Exportar Tabela (CSV) -->
    <?php
      $userRole = (int)($_SESSION['role'] ?? 0);
      $userDir  = $_SESSION['diretoria'] ?? '';
      $canExportAll = in_array($userRole, [4,5], true); // 4=Presidente, 5=Admin
    ?>
    <div class="row g-3 mt-2">
      <div class="col-12 d-flex justify-content-start">
        <div class="card border-0 shadow-sm" style="max-width: 520px; width: 100%;">
          <div class="card-body p-3">
            <div class="d-flex align-items-center mb-2">
              <div class="me-2 d-inline-flex align-items-center justify-content-center rounded-3"
                   style="width:34px;height:34px;border:1px solid var(--coh-border,#e5e7eb);">
                <i class="bi bi-download"></i>
              </div>
              <div class="fw-semibold">Exportar Tabela</div>
            </div>

            <form action="/php/export_emop_contratos_csv.php" method="get"
                  class="d-flex flex-wrap align-items-end gap-2">
              <div class="flex-grow-1" style="min-width: 180px;">
                <label for="exportDiretoria" class="form-label mb-1 small text-muted">Diretoria</label>
                <?php if ($canExportAll): ?>
                  <select id="exportDiretoria" name="diretoria" class="form-select form-select-sm">
                    <option value="" <?= ($F_dir===''?'selected':'') ?>>Todas</option>
                    <?php foreach ($opts_diretoria as $opt): ?>
                      <option value="<?= e($opt) ?>" <?= ($F_dir===$opt?'selected':'') ?>>
                        <?= e($opt) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="hidden" name="diretoria" value="<?= e($userDir) ?>">
                  <select id="exportDiretoria" class="form-select form-select-sm" disabled>
                    <option selected><?= e($userDir) ?></option>
                  </select>
                <?php endif; ?>
              </div>
              <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-filetype-csv me-1"></i> Baixar CSV
              </button>
            </form>

            <div class="text-muted small mt-2">
              <i class="bi bi-filetype-csv me-1"></i> Exportar tabela CSV.
            </div>
          </div>
        </div>
      </div>
    </div>

  </div> <!-- /.container -->

  <!-- MODAL: Detalhes do Contrato -->
  <div class="modal fade" id="contratoModal" tabindex="-1" aria-labelledby="contratoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="contratoModalLabel">Detalhes do Contrato</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div id="contratoModalBody">
            <div class="text-center text-muted py-5" id="contratoLoading">
              <div class="spinner-border" role="status" aria-hidden="true"></div>
              <div class="mt-2">Carregando...</div>
            </div>
          </div>
        </div>
        <div class="modal-footer gap-2">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
          <a id="btnEditarContrato" class="btn btn-primary" href="#" target="_self" rel="noopener">Editar</a>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: Inbox do Coordenador -->
  <div class="modal fade" id="coordenadorInboxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-clipboard-check me-2"></i> Solicitações para Aprovação
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body" id="coordenadorInboxBody"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  </div>

  <script type="module">
    import { loadCoordenadorInbox, refreshInboxCount, wireInboxModal } from '/assets/js/coordenador_inbox.js';
    document.addEventListener('DOMContentLoaded', () => {
      refreshInboxCount('#notifBadge');
      wireInboxModal('#btnOpenInboxModal', '#coordenadorInboxBody');
      const modalEl = document.getElementById('coordenadorInboxModal');
      if (modalEl) {
        modalEl.addEventListener('show.bs.modal', () => {
          loadCoordenadorInbox('#coordenadorInboxBody');
        });
      }
    });
  </script>

  <script>
  // ===== Dados do PHP =====
  const pieLabels = <?= json_encode($pizza_labels ?? [], JSON_UNESCAPED_UNICODE) ?>;
  const pieValues = <?= json_encode($pizza_values ?? [], JSON_UNESCAPED_UNICODE) ?>;
  const countsByMunicipio = <?= json_encode($municipio_counts ?? [], JSON_UNESCAPED_UNICODE) ?>;
  const currentFilters = {
    diretoria: <?= json_encode($F_dir_first, JSON_UNESCAPED_UNICODE) ?>,
    secretaria: <?= json_encode($F_sec_first, JSON_UNESCAPED_UNICODE) ?>,
    municipio: <?= json_encode($F_mun_first, JSON_UNESCAPED_UNICODE) ?>,
    ano: <?= json_encode($F_ano_first, JSON_UNESCAPED_UNICODE) ?>
  };

  function norm(s){ return (s||'').toString().normalize('NFD').replace(/\p{Diacritic}/gu,'').toUpperCase().trim(); }
  const countsNorm = {}; Object.entries(countsByMunicipio).forEach(([k,v]) => { countsNorm[norm(k)] = parseInt(v||0,10); });

  function cssVar(name, fallback){
    return getComputedStyle(document.documentElement).getPropertyValue(name)?.trim() || fallback;
  }

  /* ---------- Chart.js (Pie) ---------- */
  let pieChart = null;
  function initPie(){
    const el = document.getElementById('pieDiretoria');
    if(!el) return;
    if (pieChart) { try { pieChart.destroy(); } catch(e){} }
    const dataOk = Array.isArray(pieValues) && pieValues.length > 0;
    const textColor   = cssVar('--coh-text', '#111827');
    const borderColor = '#e5e7eb';
    const bgTooltip   = 'rgba(255,255,255,.95)';
    Chart.defaults.color = textColor;
    pieChart = new Chart(el.getContext('2d'), {
      type: 'pie',
      data: { labels: pieLabels, datasets: [{ data: dataOk ? pieValues : [1] }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position:'bottom', labels:{ color: textColor } },
          tooltip: { titleColor: textColor, bodyColor:  textColor, backgroundColor: bgTooltip, borderColor: borderColor, borderWidth: 1 }
        },
        animation: { duration: 250 }
      }
    });
    window.pieChart = pieChart;
  }

  /* ---------- Leaflet (Mapa) ---------- */
  let map = null, geojsonLayer = null, info = null, lastBounds = null, ro = null;

  function getFitPadding(el){
    try{
      const cs = getComputedStyle(el);
      const t = parseFloat(cs.paddingTop)    || 0;
      const r = parseFloat(cs.paddingRight)  || 0;
      const b = parseFloat(cs.paddingBottom) || 0;
      const l = parseFloat(cs.paddingLeft)   || 0;
      const extra = 8;
      return { t: t + extra, r: r + extra, b: b + extra, l: l + extra };
    }catch(_){ return { t:12, r:12, b:12, l:12 }; }
  }

  function getColor(d){
    // Escala fixa para tema claro
    return d > 50 ? '#0b4f9c'
         : d > 20 ? '#2e7dd1'
         : d > 10 ? '#58a6ff'
         : d > 5  ? '#93c5fd'
         : d > 0  ? '#cfe1ff'
         : '#eef2ff';
  }
  function style(feature){
    const nome = feature.properties.name || feature.properties.NOME || feature.properties.NM_MUN || '';
    const qtd  = countsNorm[norm(nome)] || 0;
    return {
      weight: 1, opacity: 1, color:'#9ca3af',
      fillOpacity: 0.9,
      fillColor: getColor(qtd)
    };
  }
  function highlightFeature(e){
    const l = e.target;
    l.setStyle({ weight:2, color:'#111827', fillOpacity: 0.98 });
    l.bringToFront();
    info.update(l.feature.properties);
  }
  function resetHighlight(e){
    geojsonLayer && geojsonLayer.resetStyle(e.target);
    info.update();
  }
  function onEachFeature(feature, layer){
    const nomeRaw = feature.properties.name || feature.properties.NOME || feature.properties.NM_MUN || '';
    const qtd  = countsNorm[norm(nomeRaw)] || 0;
    layer.bindTooltip(`${nomeRaw} — ${qtd} contrato(s)`, { direction:'auto' });
    layer.on({
      mouseover: highlightFeature,
      mouseout: resetHighlight,
      click: () => {
        const params = new URLSearchParams(window.location.search);
        if(currentFilters.diretoria) params.set('diretoria[]', currentFilters.diretoria); else params.delete('diretoria[]');
        if(currentFilters.secretaria) params.set('secretaria[]', currentFilters.secretaria); else params.delete('secretaria[]');
        if(currentFilters.ano) params.set('ano[]', currentFilters.ano); else params.delete('ano[]');
        params.set('municipio[]', nomeRaw);
        window.location.search = params.toString();
      }
    });
  }

  function mapInvalidateHard(){
    if (!map) return;
    try {
      map.invalidateSize();
      requestAnimationFrame(()=> map.invalidateSize());
      setTimeout(()=> map.invalidateSize(), 180);
    } catch(e){}
  }
  function mapFit(forceBounds=false){
    if (!map) return;
    const el = document.getElementById('map');
    if(!el) return;
    try{
      if (geojsonLayer && (forceBounds || !lastBounds)) {
        lastBounds = geojsonLayer.getBounds();
      }
      if (lastBounds && lastBounds.isValid()){
        const pad = getFitPadding(el);
        map.invalidateSize();
        map.fitBounds(lastBounds, {
          paddingTopLeft:     [pad.l, pad.t],
          paddingBottomRight: [pad.r, pad.b],
          animate: false
        });
      } else {
        map.setView([-22.5,-43.5], 7);
      }
      mapInvalidateHard();
    }catch(e){}
  }

  function initMap(){
    const el = document.getElementById('map');
    if(!el) return;

    if(!map){
      map = L.map('map', {
        scrollWheelZoom: false,
        zoomControl: true,
        zoomSnap: 0.25,
        zoomDelta: 0.5
      });
      window.map = map;
    }
    if (geojsonLayer){ try { geojsonLayer.remove(); } catch(e){} geojsonLayer = null; }
    if (info){ try { info.remove(); } catch(e){} info = null; }

    info = L.control({position:'topright'});
    info.onAdd = function(){ this._div = L.DomUtil.create('div', 'info'); this.update(); return this._div; };
    info.update = function(props){
      if(!props){ this._div.innerHTML = '<b>Mapa</b><div>Passe o mouse sobre um município</div>'; return; }
      const nome = props.name || props.NOME || props.NM_MUN || '';
      const qtd  = countsNorm[norm(nome)] || 0;
      this._div.innerHTML = `<b>${nome}</b><div>Contratos: <strong>${qtd}</strong></div>`;
    };
    info.addTo(map);

    try {
      if (ro) { ro.disconnect(); ro = null; }
      let raf = 0;
      ro = new ResizeObserver(() => {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(() => { mapInvalidateHard(); mapFit(false); });
      });
      ro.observe(el);
    } catch(e){}

    fetch('https://raw.githubusercontent.com/tbrugz/geodata-br/master/geojson/geojs-33-mun.json')
      .then(r => r.json())
      .then(geo => {
        geojsonLayer = L.geoJson(geo, { style, onEachFeature }).addTo(map);
        lastBounds = geojsonLayer.getBounds();
        const doFit = () => { mapFit(true); };
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(() => requestAnimationFrame(doFit));
        } else {
          requestAnimationFrame(doFit);
        }
      })
      .catch(err => console.error('Falha ao carregar GeoJSON:', err));

    setTimeout(mapInvalidateHard, 50);
    setTimeout(() => mapFit(false), 180);
    setTimeout(mapInvalidateHard, 400);
  }

  function updateTopbarHeight(){
    const top = document.querySelector('.coh-topbar');
    const h = top ? top.offsetHeight : 56;
    document.documentElement.style.setProperty('--coh-topbar-h', h + 'px');
  }
  function reflowVisuals(){
    try { if (window.pieChart) window.pieChart.resize(); } catch(e){}
    mapInvalidateHard();
    mapFit(false);
  }
  function boot(){ updateTopbarHeight(); initPie(); initMap(); setTimeout(reflowVisuals, 250); }

  // ===== Inicializa Choices.js (mantém seleções do PHP) =====
  document.addEventListener('DOMContentLoaded', function(){
    const selects = document.querySelectorAll('.js-choices-multi');
    selects.forEach(sel => {
      const placeholder = sel.getAttribute('data-placeholder') || 'Selecione...';
      new Choices(sel, {
        removeItemButton: true,
        shouldSort: false,
        placeholder: true,
        placeholderValue: placeholder,
        searchResultLimit: 100,
        itemSelectText: '',
        allowHTML: false
      });
    });
  });

  (function () {
    function reflowNow(){
      try { if (window.pieChart) window.pieChart.resize(); } catch(e){}
      mapInvalidateHard();
      setTimeout(()=> mapFit(false), 140);
    }
    const bodyObs = new MutationObserver((muts) => {
      for (const m of muts) if (m.type === 'attributes' && m.attributeName === 'class') { reflowNow(); break; }
    });
    bodyObs.observe(document.body, { attributes: true });

    const sidebar = document.querySelector('.coh-sidebar');
    if (sidebar) {
      sidebar.addEventListener('transitionend', (ev) => {
        if (['width','transform','padding','margin'].includes(ev.propertyName)) reflowNow();
      });
    }
    document.addEventListener('click', (ev) => {
      const btn = ev.target.closest('.coh-sidebar-toggle, [data-sidebar-toggle], #sidebarToggle, .coh-sidebar-fab');
      if (btn) setTimeout(reflowNow, 180);
    });
  })();

  window.addEventListener('load', boot);
  window.addEventListener('resize', () => {
    updateTopbarHeight();
    reflowVisuals();
    setTimeout(()=> mapFit(false), 120);
  });

  (function(){
    const body = document.body;
    const html = document.documentElement;
    const COOKIE = 'coh_sidebar_state';
    const LS     = 'coh.sidebar.state';

    function setCookie(name, value, days){
      try{
        const maxAge = days * 24 * 60 * 60;
        document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax';
      }catch(e){}
    }

    // ===== Ordenação + Resize das colunas da Matriz =====
    (function(){
      const table = document.querySelector('.table-matriz');
      if (!table) return;
      const tbody = table.tBodies[0];
      if (!tbody) return;

      const ths = table.querySelectorAll('thead th');
      let currentSort = { index: null, dir: 1 };

      ths.forEach((th, index) => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function(ev){
          if (ev.target.classList.contains('col-resizer')) return;

          const type = th.getAttribute('data-sort-type') || 'text';
          const rows = Array.from(tbody.querySelectorAll('tr'));
          const dir = (currentSort.index === index ? -currentSort.dir : 1);
          currentSort = { index, dir };

          rows.sort((a, b) => {
            const aCell = (a.children[index]?.innerText || '').trim();
            const bCell = (b.children[index]?.innerText || '').trim();

            if (type === 'num') {
              const toNum = (s) => {
                const n = parseFloat(s.replace(/\./g,'').replace(',','.'));
                return isNaN(n) ? 0 : n;
              };
              const av = toNum(aCell);
              const bv = toNum(bCell);
              return dir * (av - bv);
            }
            return dir * aCell.localeCompare(bCell, 'pt-BR', { numeric:true, sensitivity:'base' });
          });

          rows.forEach(r => tbody.appendChild(r));
        });
      });

      let startX = 0;
      let startWidth = 0;
      let currentTh = null;

      function initResize(ev){
        currentTh = ev.target.parentElement;
        startX = ev.pageX;
        startWidth = currentTh.offsetWidth;

        document.documentElement.classList.add('col-resize-active');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', stopResize);
        ev.preventDefault();
        ev.stopPropagation();
      }

      function onMouseMove(ev){
        if (!currentTh) return;
        const delta = ev.pageX - startX;
        const newWidth = Math.max(80, startWidth + delta);
        currentTh.style.width    = newWidth + 'px';
        currentTh.style.maxWidth = newWidth + 'px';
      }

      function stopResize(){
        document.documentElement.classList.remove('col-resize-active');
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', stopResize);
        currentTh = null;
      }

      ths.forEach((th) => {
        th.style.position = th.style.position || 'relative';
        const resizer = document.createElement('span');
        resizer.className = 'col-resizer';
        resizer.addEventListener('mousedown', initResize);
        th.appendChild(resizer);
      });
    })();

    function toggleSidebar(){
      const willCollapse = !body.classList.contains('sidebar-collapsed');
      body.classList.toggle('sidebar-collapsed', willCollapse);
      html.classList.toggle('sidebar-collapsed', willCollapse);
      setCookie(COOKIE, willCollapse ? '1' : '0', 365);
      try { localStorage.setItem(LS, willCollapse ? '1' : '0'); } catch(e){}

      try { if (window.pieChart) window.pieChart.resize(); } catch(e){}
      if (typeof mapInvalidateHard === 'function') mapInvalidateHard();
      if (typeof mapFit === 'function') setTimeout(()=> mapFit(false), 140);
    }

    document.addEventListener('click', function(ev){
      const btn = ev.target.closest('[data-sidebar-toggle], .coh-sidebar-fab, .coh-sidebar-toggle, #sidebarToggle');
      if (!btn) return;
      ev.preventDefault();
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
      toggleSidebar();
    }, true);
  })();
  </script>

  <!-- JS: Abrir modal ao clicar na linha -->
  <script>
  (function(){
    const tbody = document.querySelector('.table-wrap table tbody');
    if (!tbody) return;

    const modalEl  = document.getElementById('contratoModal');
    const modalLbl = document.getElementById('contratoModalLabel');
    const modalBody= document.getElementById('contratoModalBody');
    const btnEditar= document.getElementById('btnEditarContrato');

    function showLoading(){
      modalBody.innerHTML = `
        <div class="text-center text-muted py-5">
          <div class="spinner-border" role="status" aria-hidden="true"></div>
          <div class="mt-2">Carregando...</div>
        </div>`;
    }

    async function abrirContrato(id){
      if(!id) return;
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modalLbl.textContent = 'Carregando…';
      showLoading();
      modal.show();
      try{
        const resp = await fetch('php/contrato_details.php?id=' + encodeURIComponent(id), {
          headers: { 'Accept': 'text/html' },
          cache: 'no-store',
          credentials: 'same-origin'
        });
        const html = await resp.text();

        if (!resp.ok){
          const snippet = html.replace(/\s+/g,' ').trim().slice(0,400);
          modalLbl.textContent = 'Erro';
          modalBody.innerHTML = `<div class="alert alert-danger">
            Falha ao carregar (HTTP ${resp.status}).<br>
            <code>${snippet.replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]))}</code>
          </div>`;
          return;
        }

        modalLbl.textContent = 'Detalhes do Contrato';
        modalBody.innerHTML = html;

        const editUrl = 'form_contratos.php?id=' + encodeURIComponent(id);
        btnEditar.href = editUrl;
        btnEditar.classList.remove('disabled');
        btnEditar.removeAttribute('disabled');
        btnEditar.onclick = (ev) => {
          ev.preventDefault();
          try { bootstrap.Modal.getOrCreateInstance(modalEl).hide(); } catch(_){}
          window.location.assign(editUrl);
        };

      }catch(err){
        modalLbl.textContent = 'Erro';
        modalBody.innerHTML = `<div class="alert alert-danger">
          Não foi possível carregar os detalhes. ${err?.message || ''}
        </div>`;
        console.error(err);
      }
    }

    tbody.addEventListener('click', (ev) => {
      const tr = ev.target.closest('tr.contrato-row');
      if (!tr) return;
      const id = tr.getAttribute('data-id');
      abrirContrato(id);
    });
    tbody.addEventListener('mouseover', (ev) => {
      const tr = ev.target.closest('tr.contrato-row');
      if (tr) tr.classList.add('table-active');
    });
    tbody.addEventListener('mouseout', (ev) => {
      const tr = ev.target.closest('tr.contrato-row');
      if (tr) tr.classList.remove('table-active');
    });
  })();
  </script>

  <!-- Funções de envio dos filtros (bloqueia Enter genérico) -->
  <script>
    (function(){
      const form = document.getElementById('filtersForm');
      if (!form) return;
      form.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') {
          const tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toUpperCase() : '';
          if (['INPUT','TEXTAREA','SELECT'].includes(tag)) ev.preventDefault();
        }
      }, { passive: false });
    })();
  </script>
  
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const wrap  = document.querySelector('.table-wrap');
      if (!wrap) return;
    
      const thead = wrap.querySelector('.table-matriz thead');
      if (!thead) return;
    
      // garante que o wrapper é o container de rolagem
      if (!wrap.style.position) {
        wrap.style.position = 'relative';
      }
    
      // fixa visualmente o thead no topo enquanto o wrapper rola
      wrap.addEventListener('scroll', function () {
        const st = wrap.scrollTop || 0;
        thead.style.transform   = 'translateY(' + st + 'px)';
        thead.style.zIndex      = '5';
        thead.style.willChange  = 'transform';
      });
    });
    </script>

    </div> <?php require __DIR__ . "/partials/footer.php"; ?>

</div> <!-- /coh-page -->
