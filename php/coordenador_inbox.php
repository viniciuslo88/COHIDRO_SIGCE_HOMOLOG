<?php
// Inbox do Coordenador — com labels do form e filtro de mudanças reais
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/diretoria_guard.php';
require_once __DIR__ . '/roles.php';
date_default_timezone_set('America/Sao_Paulo');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Converte valor possivelmente array/objeto em string legível (evita Array to string conversion) */
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

function emop_contratos_columns(mysqli $c){
  static $cols=null; if($cols!==null) return $cols;
  $cols=[]; if($rs=$c->query("SHOW COLUMNS FROM emop_contratos")){ while($r=$rs->fetch_assoc()) $cols[]=$r['Field']; $rs->free(); }
  return $cols;
}

/* ===== Fiscal/Solicitante helpers ===== */
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

/** Resolve o solicitante combinando campos diretos, payload e lookup em usuarios_cohidro_sigce (se houver id) */
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
  $uid = null;
  foreach (['fiscal_id','user_id','usuario_id','created_by','solicitante_id'] as $k){
    if (isset($row[$k]) && is_scalar($row[$k]) && ctype_digit((string)$row[$k])) { $uid = (int)$row[$k]; break; }
  }
  if ($uid){
    if ($rs=$conn->query("SHOW TABLES LIKE 'usuarios_cohidro_sigce'")){
      $has = ($rs->num_rows>0); $rs->free();
      if ($has){
        if ($rs2 = $conn->query("SELECT nome, email FROM usuarios_cohidro_sigce WHERE id=".$uid." LIMIT 1")){
          if ($u = $rs2->fetch_assoc()){
            foreach (['nome','email'] as $k) {
              if (!empty($u[$k])) { $rs2->free(); return trim((string)$u[$k]); }
            }
          }
          $rs2->free();
        }
      }
    }
  }
  return '—';
}

/* ============= RÓTULOS DO FORM ============= */
function column_label_map(){
  return [
    'Objeto_Da_Obra'                  => 'Objeto da Obra',
    'Fonte_De_Recursos'               => 'Fonte de Recursos',
    'Aditivo_N'                       => 'Aditivo Nº',
    'Processo_SEI'                    => 'Processo SEI',
    'Diretoria'                       => 'Diretoria',
    'Secretaria'                      => 'Secretaria',
    'Municipio'                       => 'Município',
    'Empresa'                         => 'Empresa',
    'Valor_Do_Contrato'               => 'Valor do Contrato',
    'Data_Inicio'                     => 'Data de Início',
    'Data_Fim_Prevista'               => 'Data de Fim Prevista',
    'Status'                          => 'Status',
    'Observacoes'                     => 'Observações',
    'Percentual_Executado'            => '% Executado',
    'Valor_Liquidado_Acumulado'       => 'Valor Liquidado (Acum.)',
    'Data_Da_Medicao_Atual'           => 'Data da Medição Atual',
    'Valor_Liquidado_Na_Medicao_RS'   => 'Valor da Medição (R$)',
  ];
}
function prettify_column($col){
  $label = str_replace('_',' ', $col);
  $label = ucwords(strtolower($label));
  $label = str_replace([' Sei',' Rj',' N '],[' SEI',' RJ',' Nº '], $label);
  $label = str_replace(' Nº  ',' Nº ', $label);
  return $label;
}
function column_label($col){
  $map = column_label_map();
  return $map[$col] ?? prettify_column($col);
}

/* ============= COMPARAÇÃO TOLERANTE ============= */
function to_num($v){
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '') return null;
  $s = str_replace(['.', ' '], '', $s);
  $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : null;
}
function to_date($v){
  $s = trim((string)$v);
  if ($s==='') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  return null;
}
function eq_relaxed($a, $b){
  if ((string)$a === (string)$b) return true;
  $da = to_date($a); $db = to_date($b);
  if ($da && $db) return $da === $db;
  $na = to_num($a);  $nb = to_num($b);
  if ($na !== null && $nb !== null) return abs($na - $nb) < 1e-9;
  $sa = preg_replace('/\s+/',' ', trim((string)$a));
  $sb = preg_replace('/\s+/',' ', trim((string)$b));
  return $sa === $sb;
}

/* ============= EXTRACT (para listas) ============= */
function extract_change($raw){
  if (!is_array($raw)) return ['campo'=>'—','antes'=>'—','depois'=>'—','label'=>'—'];
  $campo  = $raw['campo']  ?? ($raw['field'] ?? ($raw['coluna'] ?? ($raw['nome'] ?? ($raw['chave'] ?? '—'))));
  $antes  = $raw['antes']  ?? ($raw['old']   ?? ($raw['antigo'] ?? ($raw['de'] ?? ($raw['from'] ?? null))));
  $depois = $raw['depois'] ?? ($raw['new']   ?? ($raw['novo']   ?? ($raw['para'] ?? ($raw['to'] ?? ($raw['valor'] ?? null)))));
  if (is_array($antes)  || is_object($antes))  $antes  = json_encode($antes,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if (is_array($depois) || is_object($depois)) $depois = json_encode($depois, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $label = column_label($campo);
  if ($campo===''||$campo===null) { $campo='—'; $label='—'; }
  return ['campo'=>$campo,'antes'=>$antes??'—','depois'=>$depois??'—','label'=>$label];
}

/* ============= CHECK ROLE ============= */
$role = (int)($_SESSION['role'] ?? 0);
if ($role < 2) {
  if (($_GET['mode'] ?? '') === 'count') { header('Content-Type: application/json'); echo json_encode(['count'=>0]); exit; }
  header('Content-Type: text/html; charset=UTF-8'); echo '<div class="alert alert-danger m-3">Acesso negado.</div>'; exit;
}

/* ============= COUNT MODE ============= */
$mode = $_GET['mode'] ?? null;
if ($mode === 'count'){
  $sql = "SELECT COUNT(*) FROM coordenador_inbox WHERE UPPER(status)='PENDENTE'";
  $dir = $_SESSION['diretoria'] ?? null; if ($dir){ $d=$conn->real_escape_string($dir); $sql .= " AND diretoria='{$d}'"; }
  $n=0; if($rs=$conn->query($sql)){ $r=$rs->fetch_row(); $n=(int)$r[0]; $rs->free(); }
  header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['count'=>$n], JSON_UNESCAPED_UNICODE); exit;
}

/* ============= LISTAGEM ============= */
header('Content-Type: text/html; charset=UTF-8');

$rows = [];
// LEFT JOIN apenas com colunas existentes (nome/email)
$sql = "SELECT a.*,
               c.Objeto_Da_Obra AS objeto,
               c.Empresa AS empresa,
               u.nome  AS fiscal_nome,
               u.email AS fiscal_email
        FROM coordenador_inbox a
        LEFT JOIN emop_contratos c ON c.id=a.contrato_id
        LEFT JOIN usuarios_cohidro_sigce u ON u.id = a.fiscal_id
        WHERE UPPER(a.status)='PENDENTE'
        ORDER BY a.created_at ASC, a.id ASC";
if ($rs=$conn->query($sql)){ while($r=$rs->fetch_assoc()) $rows[]=$r; if($rs) $rs->free(); }

$cols = emop_contratos_columns($conn);
$cacheAntes = []; // contrato_id => linha atual
$USER_DIRETORIA = (string)($_SESSION['diretoria'] ?? '');
?>
<style>
/* Botões contornados com preenchimento no hover (cores ajustáveis) */
.btn-outline-approve{
  --btn-color:#16a34a; /* verde */
  color:var(--btn-color);
  border-color:var(--btn-color);
  background:transparent;
}
.btn-outline-approve:hover,
.btn-outline-approve:focus{
  color:#fff;
  background:var(--btn-color);
  border-color:var(--btn-color);
}
.btn-outline-reject{
  --btn-color:#dc2626; /* vermelho */
  color:var(--btn-color);
  border-color:var(--btn-color);
  background:transparent;
}
.btn-outline-reject:hover,
.btn-outline-reject:focus{
  color:#fff;
  background:var(--btn-color);
  border-color:var(--btn-color);
}
</style>

<div class="container-fluid">
<?php if (!$rows): ?>
  <div class="alert alert-success my-3">Nenhuma solicitação pendente. 👌</div>
<?php else: ?>
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr><th>Contrato</th><th>Diretoria</th><th>Fiscal</th><th>Objeto</th><th>Empresa</th><th>Alterações</th><th>Ações</th></tr>
    </thead>
    <tbody>
    <?php foreach($rows as $r):
      $id=(int)$r['id']; $contrato_id=(int)$r['contrato_id'];
      $diretoria=$r['diretoria']??'—'; $obj=$r['objeto']??''; $emp=$r['empresa']??'';
      $payload = json_decode((string)($r['payload_json'] ?? ''), true);

      // Fiscal / Solicitante — prioriza dados do JOIN (nome ou email), fallback para resolve_solicitante
      $fiscal = '';
      foreach (['fiscal_nome','fiscal_email'] as $k) {
        if (!empty($r[$k])) { $fiscal = trim((string)$r[$k]); break; }
      }
      if ($fiscal === '') {
        $fiscal = resolve_solicitante($r, $payload, $conn) ?? '—';
      }

      $changes=[]; $medicoes=[]; $aditivos=[]; $reajustes=[];

      // Se `campos` é OBJETO {col:novoValor}, gera linhas com label do form e filtra só se mudou
      if (is_array($payload) && isset($payload['campos']) && is_array($payload['campos']) && array_values($payload['campos']) !== $payload['campos']){
        if (!isset($cacheAntes[$contrato_id])){
          $res=$conn->query("SELECT * FROM emop_contratos WHERE id=".$contrato_id." LIMIT 1");
          $cacheAntes[$contrato_id]=($res && $res->num_rows)?$res->fetch_assoc():[];
          if($res) $res->free();
        }
        $rowAntes=$cacheAntes[$contrato_id];
        foreach($payload['campos'] as $col=>$novo){
          $col_db = $col;
          foreach ($cols as $c) { if (strcasecmp($c, $col)===0) { $col_db=$c; break; } }
          $antes = $rowAntes[$col_db] ?? null;
          if (eq_relaxed($antes, $novo)) continue;
          if (is_array($novo)||is_object($novo)) $novo=json_encode($novo,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $changes[]=['campo'=>$col_db,'label'=>column_label($col_db),'antes'=>($antes===null?'—':$antes),'depois'=>($novo===null?'—':$novo)];
        }
      } else {
        if (isset($payload['changes'])     && is_array($payload['changes']))     $changes=$payload['changes'];
        elseif (isset($payload['alteracoes']) && is_array($payload['alteracoes'])) $changes=$payload['alteracoes'];
        elseif (isset($payload['differences'])&& is_array($payload['differences']))$changes=$payload['differences'];
        elseif (isset($payload['campos']) && is_array($payload['campos']))       $changes=$payload['campos'];
        elseif (isset($payload[0]) && is_array($payload[0]))                      $changes=$payload;
        foreach($changes as &$c){ if (!isset($c['label']) && isset($c['campo'])) $c['label']=column_label($c['campo']); }
        unset($c);
      }

      // Medições
      if (is_array($payload) && isset($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])){
        foreach($payload['novas_medicoes'] as $m){
          if (!is_array($m)) continue;
          $medicoes[]=[
            'data'=>$m['data']??($m['data_medicao']??null),
            'valor_rs'=>$m['valor_rs']??($m['valor']??null),
            'acumulado_rs'=>$m['acumulado_rs']??null,
            'percentual'=>$m['percentual']??null,
            'obs'=>$m['obs']??($m['observacao']??null),
          ];
        }
      }

      // Aditivos
      if (is_array($payload) && isset($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])){
        foreach($payload['novos_aditivos'] as $a){
          if (!is_array($a)) continue;
          $aditivos[] = [
            'numero'          => $a['numero_aditivo'] ?? null,
            'data'            => $a['data'] ?? null,
            'tipo'            => $a['tipo'] ?? null,
            'valor_total'     => $a['valor_aditivo_total'] ?? null,
            'valor_total_apos'=> $a['valor_total_apos_aditivo'] ?? null,
            'obs'             => $a['observacao'] ?? null,
          ];
        }
      }

      // Reajustes
      if (is_array($payload) && isset($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])){
        foreach($payload['novos_reajustes'] as $rj){
          if (!is_array($rj)) continue;
          $reajustes[] = [
            'indice'          => $rj['indice'] ?? null,
            'percentual'      => $rj['percentual'] ?? null,
            'data_base'       => $rj['data_base'] ?? null,
            'valor_total_apos'=> $rj['valor_total_apos_reajuste'] ?? null,
            'obs'             => $rj['observacao'] ?? null,
          ];
        }
      }

      $changes_count = (is_array($changes)?count($changes):0)
                     + (is_array($medicoes)?count($medicoes):0)
                     + (is_array($aditivos)?count($aditivos):0)
                     + (is_array($reajustes)?count($reajustes):0);
    ?>
      <tr data-id="<?= $id ?>" data-contrato-id="<?= (int)$contrato_id ?>">
        <td><a href="/form_contratos.php?id=<?= (int)$contrato_id ?>" class="link-underline link-underline-opacity-0">Contrato <?= (int)$contrato_id ?></a></td>
        <td><?= h($diretoria?:'—') ?></td>
        <td><?= h($fiscal ?: '—') ?></td>
        <td class="text-truncate" style="max-width:280px"><?= h($obj?:'—') ?></td>
        <td class="text-truncate" style="max-width:200px"><?= h($emp?:'—') ?></td>
        <td>
          <button class="btn btn-outline-primary btn-sm js-ver-alteracoes" data-target="#cr-changes-<?= $id ?>" aria-expanded="false">
            Ver alterações <span class="badge bg-primary-subtle text-primary"><?= (int)$changes_count ?></span>
          </button>
        </td>
        <td>
          <div class="btn-group" role="group">
            <button class="btn btn-sm btn-outline-approve js-acao" data-action="approve" data-id="<?= $id ?>">Aprovar</button>
            <button class="btn btn-sm btn-outline-reject  js-acao" data-action="reject"  data-id="<?= $id ?>">Rejeitar</button>
          </div>
        </td>
      </tr>
      <tr id="cr-changes-<?= $id ?>" class="d-none">
        <td colspan="7">
          <div class="small text-muted mb-2">
            Solicitado por <strong><?= h($fiscal ?: '—') ?></strong>
            <?php if (!empty($r['created_at'])): ?> em <?= h(date('d/m/Y H:i', strtotime((string)$r['created_at']))) ?><?php endif; ?>
          </div>
          <?php if ($changes_count===0): ?>
            <div class="alert alert-warning mb-2">Nenhuma alteração detalhada foi reconhecida. Abaixo, o payload bruto:</div>
            <pre class="small bg-body-tertiary p-2 border rounded" style="white-space:pre-wrap"><?= h(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
          <?php else: ?>
            <?php if (!empty($changes)): ?>
            <div class="table-responsive mb-2">
              <table class="table table-sm mb-0">
                <thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead>
                <tbody>
                  <?php foreach($changes as $c): if(!isset($c['campo'])) $c=extract_change($c); ?>
                    <tr>
                      <td><?= h($c['label'] ?? column_label($c['campo'])) ?></td>
                      <td><?= nl2br(h((string)($c['antes']  ?? '—'))) ?></td>
                      <td><?= nl2br(h((string)($c['depois'] ?? '—'))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($medicoes)): ?>
            <div class="table-responsive mb-2">
              <table class="table table-sm mb-0">
                <thead>
                  <tr><th colspan="5">Novas medições</th></tr>
                  <tr><th>Data</th><th>Valor (R$)</th><th>Acumulado (R$)</th><th>%</th><th>Obs</th></tr>
                </thead>
                <tbody>
                  <?php foreach($medicoes as $m): ?>
                    <tr>
                      <td><?= h($m['data']??'—') ?></td>
                      <td><?= h((string)($m['valor_rs']??'—')) ?></td>
                      <td><?= h((string)($m['acumulado_rs']??'—')) ?></td>
                      <td><?= h((string)($m['percentual']??'—')) ?></td>
                      <td><?= h((string)($m['obs']??'')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($aditivos)): ?>
            <div class="table-responsive mb-2">
              <table class="table table-sm mb-0">
                <thead>
                  <tr><th colspan="6">Novos aditivos</th></tr>
                  <tr><th>Nº</th><th>Data</th><th>Tipo</th><th>Valor do Aditivo</th><th>Valor Total Após</th><th>Obs</th></tr>
                </thead>
                <tbody>
                  <?php foreach($aditivos as $a): ?>
                    <tr>
                      <td><?= h((string)($a['numero']??'—')) ?></td>
                      <td><?= h((string)($a['data']??'—')) ?></td>
                      <td><?= h((string)($a['tipo']??'—')) ?></td>
                      <td><?= h((string)($a['valor_total']??'—')) ?></td>
                      <td><?= h((string)($a['valor_total_apos']??'—')) ?></td>
                      <td><?= h((string)($a['obs']??'')) ?></td>
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
                      <td><?= h((string)($rj['indice']??'—')) ?></td>
                      <td><?= h((string)($rj['percentual']??'—')) ?></td>
                      <td><?= h((string)($rj['data_base']??'—')) ?></td>
                      <td><?= h((string)($rj['valor_total_apos']??'—')) ?></td>
                      <td><?= h((string)($rj['obs']??'')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<!-- Link para a página completa de histórico (fora do modal) -->
<?php
$USER_ROLE = (int)($_SESSION['role'] ?? 0);
$USER_DIRETORIA = trim((string)($_SESSION['diretoria'] ?? ''));
$target_diretoria = ($USER_ROLE >= 4) ? 'todas' : $USER_DIRETORIA;
?>
<div class="d-flex justify-content-start mt-2">
  <a class="btn btn-outline-secondary btn-sm"
     href="/php/historico_alteracoes_contratos.php?diretoria=<?= urlencode($target_diretoria) ?>">
    Histórico de alterações
  </a>
</div>
