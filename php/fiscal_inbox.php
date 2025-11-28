<?php
// php/fiscal_inbox.php — Inbox do Fiscal (rejeições e revisões solicitadas)
// Compatível com o layout e parsing do coordenador_inbox.php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== Helpers de schema ===== */
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

/* ===== Rótulos/formatadores/normalização ===== */
function column_label_map(){ return [
  'Objeto_Da_Obra'=>'Objeto da Obra','Fonte_De_Recursos'=>'Fonte de Recursos','Aditivo_N'=>'Aditivo Nº',
  'Processo_SEI'=>'Processo SEI','Diretoria'=>'Diretoria','Secretaria'=>'Secretaria','Municipio'=>'Município',
  'Empresa'=>'Empresa','Valor_Do_Contrato'=>'Valor do Contrato','Data_Inicio'=>'Data de Início',
  'Data_Fim_Prevista'=>'Data de Fim Prevista','Status'=>'Status','Observacoes'=>'Observações',
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
function column_label($col){ $map=column_label_map(); return $map[$col] ?? prettify_column($col); }
function to_num($v){ if ($v===null) return null; $s=trim((string)$v); if($s==='')return null; $s=str_replace(['.',' '],'',$s); $s=str_replace(',','.',$s); return is_numeric($s)?(float)$s:null; }
function to_date($v){ $s=trim((string)$v); if($s==='')return null; if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s))return $s; if(preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$s,$m))return "{$m[3]}-{$m[2]}-{$m[1]}"; return null; }
function eq_relaxed($a,$b){
  if ((string)$a === (string)$b) return true;
  $da=to_date($a); $db=to_date($b); if($da && $db) return $da===$db;
  $na=to_num($a);  $nb=to_num($b);  if($na!==null && $nb!==null) return abs($na-$nb) < 1e-9;
  $sa=preg_replace('/\s+/',' ', trim((string)$a)); $sb=preg_replace('/\s+/',' ', trim((string)$b));
  return $sa === $sb;
}
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
function emop_contratos_columns(mysqli $c){
  static $cols=null; if($cols!==null) return $cols;
  $cols=[]; if($rs=$c->query("SHOW COLUMNS FROM emop_contratos")){ while($r=$rs->fetch_assoc()) $cols[]=$r['Field']; $rs->free(); }
  return $cols;
}

/* ===== Controle de acesso ===== */
$role      = (int)($_SESSION['role'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_cpf  = trim((string)($_SESSION['cpf'] ?? ''));
$user_name = trim((string)($_SESSION['nome'] ?? $_SESSION['name'] ?? ''));

// Fiscal (1) e Dev (6)
if (!in_array($role, [1,6], true)) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Acesso negado']); exit;
}

/* ===== POST: remover da inbox do fiscal (mantém histórico, remove da inbox) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $action = $_POST['action'] ?? '';

  if ($action === 'dismiss') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['ok'=>false,'error'=>'ID inválido']);
      exit;
    }

    // Busca o registro antes de excluir (para salvar no histórico)
    $res = $conn->query("SELECT * FROM coordenador_inbox WHERE id={$id} LIMIT 1");
    if (!$res || !$res->num_rows) {
      echo json_encode(['ok'=>false,'error'=>'Registro não encontrado']);
      exit;
    }
    $row = $res->fetch_assoc();
    $res->free();

    // Apenas rejeitados podem ser removidos
    if (strtoupper((string)$row['status']) !== 'REJEITADO') {
      echo json_encode(['ok'=>false,'error'=>'Apenas itens REJEITADOS podem ser removidos']);
      exit;
    }

    // Copia para histórico (se existir)
    if (table_exists($conn,'historico_alteracoes_contratos')) {
      $contrato_id = (int)($row['contrato_id'] ?? 0);
      $usuario_id  = (int)($row['usuario_id'] ?? $user_id);
      $dados_json  = json_encode($row, JSON_UNESCAPED_UNICODE);

      $stmt = $conn->prepare("
        INSERT INTO historico_alteracoes_contratos
        (contrato_id, usuario_id, acao, dados_json, criado_em)
        VALUES (?, ?, 'REMOVIDO_DA_INBOX', ?, NOW())
      ");
      if ($stmt) {
        $stmt->bind_param('iis', $contrato_id, $usuario_id, $dados_json);
        $stmt->execute();
        $stmt->close();
      }
    }

    // Remove fisicamente da inbox
    $sqlDel = "DELETE FROM coordenador_inbox WHERE id = {$id} LIMIT 1";
    if (!$conn->query($sqlDel)) {
      echo json_encode(['ok'=>false,'error'=>'Erro MySQL: '.$conn->error]);
      exit;
    }

    echo json_encode(['ok'=>true]);
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Ação inválida']);
  exit;
}

/* ===== WHERE base ===== */
// No banco continua 'REVISAO_SOLICITADA' (sem acento/underscore).
$table = 'coordenador_inbox';
$status_where = "UPPER(a.status) IN ('REJEITADO','REVISAO_SOLICITADA')";

/* Filtro por fiscal; se zerar, fallback sem fiscal */
$fiscal_parts = [];
if (col_exists($conn,$table,'fiscal_id') && $user_id>0)            $fiscal_parts[] = "a.fiscal_id={$user_id}";
if (col_exists($conn,$table,'requested_by_id') && $user_id>0)      $fiscal_parts[] = "a.requested_by_id={$user_id}";
if (col_exists($conn,$table,'requested_by_cpf') && $user_cpf!=='') $fiscal_parts[] = "a.requested_by_cpf='".$conn->real_escape_string($user_cpf)."'";
if (col_exists($conn,$table,'requested_by') && $user_name!==''){
  $nm = $conn->real_escape_string($user_name);
  $fiscal_parts[] = "a.requested_by='{$nm}'";
  $fiscal_parts[] = "a.requested_by LIKE '%{$nm}%'";
}
$fiscal_where = $fiscal_parts ? '('.implode(' OR ', $fiscal_parts).')' : '';

/* Excluir itens já “removidos” pelo fiscal, se colunas existirem */
$dismiss_ex = [];
if (col_exists($conn,$table,'dismissed_by_cpf') && $user_cpf!=='') {
  $dismiss_ex[] = "COALESCE(a.dismissed_by_cpf,'') <> '".$conn->real_escape_string($user_cpf)."'";
}
if (col_exists($conn,$table,'dismissed_by_id') && $user_id>0) {
  $dismiss_ex[] = "(a.dismissed_by_id IS NULL OR a.dismissed_by_id <> ".(int)$user_id.")";
}
if (col_exists($conn,$table,'dismissed_by')) {
  // tenta olhar no JSON por cpf
  $dismiss_ex[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dismissed_by, '$.cpf')),'') <> '".$conn->real_escape_string($user_cpf)."'";
}
$dismiss_filter = $dismiss_ex ? (' AND '.implode(' AND ',$dismiss_ex)) : '';

/* ===== COUNT ===== */
if (($_GET['mode'] ?? null) === 'count') {
  header('Content-Type: application/json; charset=UTF-8');
  $sql = "SELECT COUNT(*) AS n
          FROM coordenador_inbox a
          WHERE {$status_where}".($fiscal_where?" AND {$fiscal_where}":"").$dismiss_filter;
  $n=0; if($rs=$conn->query($sql)){ $row=$rs->fetch_assoc(); $n=(int)($row['n']??0); $rs->free(); }
  if ($n===0 && $fiscal_where!=='') {
    $sql2 = "SELECT COUNT(*) AS n FROM coordenador_inbox a WHERE {$status_where}".$dismiss_filter;
    if($rs=$conn->query($sql2)){ $row=$rs->fetch_assoc(); $n=(int)($row['n']??0); $rs->free(); }
  }
  echo json_encode(['count'=>$n], JSON_UNESCAPED_UNICODE); exit;
}

/* ===== LISTAGEM (embed) ===== */
if ((int)($_GET['embed'] ?? 0) === 1) {
  header('Content-Type: text/html; charset=UTF-8');

  // SELECT com nome e diretoria do coordenador (se existirem)
  $sel = "a.*, c.Objeto_Da_Obra AS objeto, c.Empresa AS empresa";
  if (col_exists($conn,$table,'requested_by')) $sel .= ", a.requested_by";
  if (col_exists($conn,$table,'processed_by')) $sel .= ", a.processed_by";

  $join = " LEFT JOIN emop_contratos c ON c.id=a.contrato_id ";
  if (table_exists($conn,'usuarios_cohidro_sigce')
      && col_exists($conn,'usuarios_cohidro_sigce','id')
      && col_exists($conn,'usuarios_cohidro_sigce','nome')) {
    $sel  .= ", uc.nome AS processed_by_name";
    if (col_exists($conn,'usuarios_cohidro_sigce','diretoria')) {
      $sel .= ", uc.diretoria AS processed_by_dir";
    }
    $join .= " LEFT JOIN usuarios_cohidro_sigce uc ON uc.id = a.processed_by ";
  }

  $sql = "SELECT {$sel}
          FROM coordenador_inbox a
          {$join}
          WHERE {$status_where}".($fiscal_where?" AND {$fiscal_where}":"").$dismiss_filter."
          ORDER BY a.processed_at DESC, a.id DESC
          LIMIT 300";

  $rows=[]; if($rs=$conn->query($sql)){ while($r=$rs->fetch_assoc()) $rows[]=$r; $rs->free(); }

  // Fallback sem filtro por fiscal
  if (!$rows && $fiscal_where!==''){
    $sql = "SELECT {$sel}
            FROM coordenador_inbox a
            {$join}
            WHERE {$status_where}{$dismiss_filter}
            ORDER BY a.processed_at DESC, a.id DESC
            LIMIT 300";
    if($rs=$conn->query($sql)){ while($r=$rs->fetch_assoc()) $rows[]=$r; $rs->free(); }
  }

  $cols = emop_contratos_columns($conn);
  $cacheAntes = [];
  ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Contrato</th>
          <th>Status</th>
          <th>Coordenador</th>
          <th>Diretoria (Coord.)</th>
          <th>Motivo / Observação</th>
          <th>Solicitado em</th>
          <th>Processado em</th>
          <th>Alterações</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-muted text-center py-4">Nenhuma rejeição ou revisão pendente.</td></tr>
      <?php else: foreach($rows as $r):
        $id=(int)$r['id']; $contrato_id=(int)($r['contrato_id'] ?? 0);
        $status_raw = strtoupper(trim((string)($r['status'] ?? ''))); // valor do banco
        $status_label = ($status_raw==='REVISAO_SOLICITADA') ? 'REVISÃO SOLICITADA' : $status_raw;

        $motivo = (string)($r['review_notes'] ?? $r['motivo_rejeicao'] ?? '');
        $dtc = $r['created_at'] ?? '';
        $dtp = $r['processed_at'] ?? '';

        $coord = '—';
        if (!empty($r['processed_by_name']))      $coord = $r['processed_by_name'];
        elseif (!empty($r['requested_by']))       $coord = $r['requested_by'];
        elseif (!empty($r['processed_by']))       $coord = 'ID '.$r['processed_by'];

        $coord_dir = isset($r['processed_by_dir']) ? (string)$r['processed_by_dir'] : '—';

        // === payload
        $payload = [];
        if (isset($r['payload_json'])) {
          $pl = trim((string)$r['payload_json']);
          if ($pl !== '') { $payload = json_decode($pl, true); if(!is_array($payload)) $payload=[]; }
        }

        // changes + extras
        $changes=[]; $medicoes=[]; $aditivos=[]; $reajustes=[];
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

        if (is_array($payload) && isset($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])){
          foreach($payload['novas_medicoes'] as $m){
            if (!is_array($m)) continue;
            $medicoes[]=['data'=>$m['data']??($m['data_medicao']??null),'valor_rs'=>$m['valor_rs']??($m['valor']??null),
                         'acumulado_rs'=>$m['acumulado_rs']??null,'percentual'=>$m['percentual']??null,'obs'=>$m['obs']??($m['observacao']??null)];
          }
        }
        if (is_array($payload) && isset($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])){
          foreach($payload['novos_aditivos'] as $a){
            if (!is_array($a)) continue;
            $aditivos[] = [
              'numero'=>$a['numero_aditivo']??null,'data'=>$a['data']??null,'tipo'=>$a['tipo']??null,
              'valor_total'=>$a['valor_aditivo_total']??null,'valor_total_apos'=>$a['valor_total_apos_aditivo']??null,
              'obs'=>$a['observacao']??null,
            ];
          }
        }
        if (is_array($payload) && isset($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])){
          foreach($payload['novos_reajustes'] as $rj){
            if (!is_array($rj)) continue;
            $reajustes[] = [
              'indice'=>$rj['indice']??null,'percentual'=>$rj['percentual']??null,'data_base'=>$rj['data_base']??null,
              'valor_total_apos'=>$rj['valor_total_apos_reajuste']??null,'obs'=>$rj['observacao']??null,
            ];
          }
        }

        $changes_count = (is_array($changes)?count($changes):0)
                       + (is_array($medicoes)?count($medicoes):0)
                       + (is_array($aditivos)?count($aditivos):0)
                       + (is_array($reajustes)?count($reajustes):0);

        $badge = 'secondary';
        if ($status_raw === 'REVISAO_SOLICITADA') $badge='warning';
        if ($status_raw === 'REJEITADO')          $badge='danger';
      ?>
        <tr data-id="<?= $id ?>" data-contrato-id="<?= (int)$contrato_id ?>">
          <td>
            <?php if ($contrato_id): ?>
              <a href="/form_contratos.php?id=<?= (int)$contrato_id ?>" class="link-underline link-underline-opacity-0">Contrato <?= (int)$contrato_id ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $badge ?>"><?= h($status_label) ?></span></td>
          <td><?= h($coord) ?></td>
          <td><?= h($coord_dir ?: '—') ?></td>
          <td style="max-width:420px"><div class="small text-muted"><?= nl2br(h($motivo ?: '—')) ?></div></td>
          <td class="text-nowrap"><?= h($dtc ?: '—') ?></td>
          <td class="text-nowrap"><?= h($dtp ?: '—') ?></td>
          <td>
            <button class="btn btn-outline-primary btn-sm js-ver-alteracoes" data-target="#fi-changes-<?= $id ?>" aria-expanded="false">
              Ver alterações <span class="badge bg-primary-subtle text-primary"><?= (int)$changes_count ?></span>
            </button>
          </td>
          <td class="text-end">
            <?php if ($status_raw === 'REJEITADO'): ?>
              <button class="btn btn-sm btn-outline-danger js-dismiss" data-id="<?= $id ?>" title="Remover da sua inbox">Remover</button>
            <?php elseif ($status_raw === 'REVISAO_SOLICITADA' && $contrato_id): ?>
              <a class="btn btn-sm btn-outline-success" href="/form_contratos.php?id=<?= (int)$contrato_id ?>" title="Abrir para revisar">Revisar</a>
            <?php endif; ?>
          </td>
        </tr>
        <tr id="fi-changes-<?= $id ?>" class="d-none">
          <td colspan="9">
            <?php if ($changes_count===0): ?>
              <div class="alert alert-warning mb-2">Nenhuma alteração detalhada foi reconhecida.</div>
              <?php if (!empty($payload)): ?>
                <pre class="small bg-body-tertiary p-2 border rounded" style="white-space:pre-wrap"><?= h(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
              <?php endif; ?>
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
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Handler INLINE para abrir/fechar "Ver alterações" (garante funcionamento para o Fiscal) -->
  <script>
  (function(){
    const body = document.getElementById('fiscalInboxBody') || document.currentScript.closest('.modal-body') || document.body;
    if (!body) return;
    body.addEventListener('click', function(ev){
      const btn = ev.target.closest('.js-ver-alteracoes');
      if (!btn) return;
      ev.preventDefault();
      let sel = btn.getAttribute('data-target');
      if (sel && !sel.startsWith('#')) sel = '#'+sel;
      let row = sel ? document.querySelector(sel) : null;
      if (!row) {
        const tr = btn.closest('tr');
        if (tr && tr.nextElementSibling && tr.nextElementSibling.id && tr.nextElementSibling.id.startsWith('fi-changes-')) {
          row = tr.nextElementSibling;
        }
      }
      if (!row) return;
      const hidden = row.classList.contains('d-none') || row.style.display==='none';
      if (hidden) {
        row.classList.remove('d-none');
        if (row.tagName === 'TR') row.style.display = 'table-row';
        btn.setAttribute('aria-expanded','true');
        btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary');
      } else {
        if (row.tagName === 'TR') row.style.display = '';
        row.classList.add('d-none');
        btn.setAttribute('aria-expanded','false');
        btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary');
      }
    });
  })();
  </script>
  <?php
  exit;
}

/* ===== Página direta (fallback) ===== */
header('Content-Type: text/html; charset=UTF-8');
?>
<div class="container py-4">
  <h5>Inbox do Fiscal</h5>
  <div id="fiscalInboxBody"></div>
  <script>
    fetch('/php/fiscal_inbox.php?embed=1', {headers:{'X-Fragment':'1'}})
      .then(r => r.text())
      .then(html => { document.getElementById('fiscalInboxBody').innerHTML = html; })
      .catch(()=>{ document.getElementById('fiscalInboxBody').innerHTML = '<div class="alert alert-danger">Falha ao carregar.</div>'; });
  </script>
</div>
