<?php
// form_contratos.php — EMOP • Contratos

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ===== Sessão / Auth =====
require_once __DIR__ . '/php/require_auth.php';
require_once __DIR__ . '/php/session_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['cpf']) && empty($_SESSION['user_id'])) {
  header('Location: /login_senha.php'); exit;
}

// ===== DB / Roles / Guards =====
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/php/conn.php';
require_once __DIR__ . '/php/diretoria_guard.php';
require_once __DIR__ . '/php/roles.php';
require_once __DIR__ . '/php/flash.php';

// ===== Libs específicas =====
require_once __DIR__ . '/php/medicoes_lib.php';
require_once __DIR__ . '/php/aditivos_lib.php';
require_once __DIR__ . '/php/reajustes_lib.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===== Usuário / nível / diretoria =====
function fetch_user_row(mysqli $conn): array {
  $uid  = (int)($_SESSION['user_id'] ?? 0);
  $cpf  = trim((string)($_SESSION['cpf'] ?? ''));
  $mail = trim((string)($_SESSION['email'] ?? ''));
  foreach ([
    ['id=?','i',$uid],
    ['cpf=?','s',$cpf],
    ['email=?','s',$mail],
  ] as $q) {
    if (!$q[2]) continue;
    if ($st=$conn->prepare("SELECT id,nome,diretoria,access_level,email,cpf FROM usuarios_cohidro_sigce WHERE {$q[0]} LIMIT 1")) {
      $st->bind_param($q[1], $q[2]); $st->execute();
      $rs=$st->get_result(); $row=$rs?($rs->fetch_assoc()?:[]):[];
      $st->close(); if ($row) return $row;
    }
  }
  return [];
}
$_USER      = fetch_user_row($conn);
$user_level = (int)($_USER['access_level'] ?? ($_SESSION['role'] ?? 0));
$user_dir   = trim((string)($_USER['diretoria'] ?? ($_SESSION['diretoria'] ?? '')));
if ($user_dir === 'DIRIM') $user_dir = 'DIRM';
if ($user_level > 0) $_SESSION['role'] = $user_level;
if ($user_dir   !== '') $_SESSION['diretoria'] = $user_dir;

// ===== Escopo de SELECT por diretoria =====
function build_scope_for_select_simple(mysqli $conn, string $alias, int $access_level): string {
  if (in_array($access_level, [4,5], true)) return '1=1';
  $scopeDir = trim((string)diretoria_guard_where($conn, $alias));
  if ($scopeDir === '') return '1=0';
  return preg_replace('/^\s*AND\s+/i', '', $scopeDir) ?: '1=0';
}

// ===== Estado =====
$HIDE_FORM   = $HIDE_FORM ?? false;
$contrato_id = (int)($_GET['id'] ?? $_POST['contrato_id'] ?? 0);
$is_new      = isset($_GET['new']);
$can_edit_immediately = in_array($user_level, [2,5], true) || (function_exists('can_edit_immediately') ? (bool)can_edit_immediately() : false);

// =========================================================================
// [AUXILIAR] Limpeza de Moeda (BR -> US) e JSON
// =========================================================================
function coh_clean_currency($val) {
    if (empty($val)) return null;
    if (is_numeric($val)) return $val; // Já é numero
    // Remove tudo que não é digito, ponto, virgula ou sinal de menos
    $val = preg_replace('/[^\d.,\-]/', '', $val);
    if ($val === '') return null;
    
    // Se tem vírgula (ex: 1.000,00 ou 1000,00), troca pra ponto
    if (strpos($val, ',') !== false) {
        $val = str_replace('.', '', $val); // Tira ponto de milhar
        $val = str_replace(',', '.', $val); // Troca virgula decimal por ponto
    }
    return $val;
}

$decode_json_array = function($v){
  if (is_array($v)) return $v;
  if (empty($v) || !is_string($v)) return [];
  $v = trim($v);
  if ($v === '' || $v === '[]') return [];

  // Tenta decode direto
  $d = json_decode($v, true);
  
  // Tenta tratar double-encoding
  if (is_string($d)) $d = json_decode($d, true);

  // Tenta stripslashes se falhar
  if (json_last_error() !== JSON_ERROR_NONE) {
      $v_clean = stripslashes($v);
      $d = json_decode($v_clean, true);
  }
  return is_array($d) ? $d : [];
};

$get_array_from_post = function(array $keys) use ($decode_json_array) {
  foreach ($keys as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') {
      return $decode_json_array($_POST[$k]);
    }
  }
  return [];
};

// === FUNÇÃO DE PERMISSÃO (REGRA 24H) ===
if (!function_exists('coh_pode_alterar')) {
    function coh_pode_alterar($created_at, $tem_permissao_nivel = true) {
        if (empty($created_at)) return true;
        if (!$tem_permissao_nivel) return false;
        $timestamp_criacao = strtotime($created_at);
        if ($timestamp_criacao === false || $timestamp_criacao < 0) return false;
        return (time() - $timestamp_criacao) <= 86400; 
    }
}

// ===== Garantia mínima do schema do inbox =====
function ensure_coordenador_inbox_schema(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS coordenador_inbox(
    id INT NOT NULL AUTO_INCREMENT,
    contrato_id INT NOT NULL,
    diretoria VARCHAR(100),
    fiscal_id INT,
    payload_json LONGTEXT,
    status VARCHAR(20) DEFAULT 'PENDENTE',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_by INT NULL,
    processed_at DATETIME NULL,
    reason TEXT NULL,
    PRIMARY KEY (id),
    KEY (contrato_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// =====================================================
// POST — salvar direto (2/5) e workflow (1)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // Snapshot do contrato + colunas
  $rowNow = [];
  if ($contrato_id > 0 && ($st=$conn->prepare("SELECT * FROM emop_contratos WHERE id=? LIMIT 1"))) {
    $st->bind_param("i",$contrato_id); $st->execute();
    $rs=$st->get_result(); $rowNow = ($rs && $rs->num_rows) ? $rs->fetch_assoc() : []; $st->close();
  }
  $cols=[]; if ($rs=$conn->query("SHOW COLUMNS FROM emop_contratos")){ while($r=$rs->fetch_assoc()) $cols[]=$r['Field']; $rs->free(); }

  // ===== Salvar direto (2/5) =====
  if ($can_edit_immediately && in_array($action, ['salvar','salvar_direto'], true)) {

    try {
      // 1. UPDATE dados principais do contrato
      if ($contrato_id > 0 && !empty($cols)) {
        $sets   = [];
        $params = [];
        $types  = '';
        $numericPrefixes = ['Valor_', 'Percentual_'];
        $skipCols = ['id','Percentual_Executado','Valor_Liquidado_Na_Medicao_RS','Valor_Liquidado_Acumulado','Medicao_Anterior_Acumulada_RS'];

        foreach ($cols as $c) {
          if (in_array($c, $skipCols, true)) continue;
          if (!array_key_exists($c, $_POST)) continue;

          $v = $_POST[$c];
          if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          else $v = trim((string)$v);

          // Tratamento de Datas
          $isDateLike = (stripos($c, '_Data') !== false) || (stripos($c, 'Data_') === 0) || preg_match('~_Data$~i', $c) || in_array($c, ['Data','Data_Inicio','Data_Fim','Data_Inicial','Data_Final'], true);
          if ($isDateLike) {
            if ($v === '') $v = null;
            elseif (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $v, $mData)) $v = "{$mData[3]}-{$mData[2]}-{$mData[1]}";
          }

          // Tratamento de Números
          foreach ($numericPrefixes as $prefix) {
            if (stripos($c, $prefix) === 0) {
              $v = coh_clean_currency($v);
              break;
            }
          }

          $sets[]   = "`{$c}` = ?";
          $params[] = $v;
          $types   .= 's';
        }

        if ($sets) {
          $sqlU = "UPDATE emop_contratos SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
          if ($stU = $conn->prepare($sqlU)) {
            $types   .= 'i';
            $params[] = $contrato_id;
            $stU->bind_param($types, ...$params);
            $stU->execute();
            $stU->close();
          }
        }
      }

      // =======================================================
      // 2. PROCESSAMENTO DE RASCUNHOS (Arrays JSON)
      // =======================================================
      $m_raw = $get_array_from_post(['novas_medicoes_json','novas_medicoes']);
      $a_raw = $get_array_from_post(['novos_aditivos_json','novos_aditivos']);
      $r_raw = $get_array_from_post(['novos_reajustes_json','novos_reajustes']);

      // --- FILTRAGEM E LIMPEZA DE MEDIÇÕES ---
      $m = []; 
      if (is_array($m_raw)) {
        foreach ($m_raw as $it) {
            // Verifica se tem dados mínimos
            $temData = !empty($it['data_medicao']) || !empty($it['data']);
            $temValor = isset($it['valor_rs']) && trim((string)$it['valor_rs']) !== '';
            
            if ($temData || $temValor) {
                // LIMPEZA CRÍTICA: converte moeda BR para US antes de salvar
                if (isset($it['valor_rs'])) $it['valor_rs'] = coh_clean_currency($it['valor_rs']);
                if (isset($it['acumulado_rs'])) $it['acumulado_rs'] = coh_clean_currency($it['acumulado_rs']);
                if (isset($it['percentual'])) $it['percentual'] = coh_clean_currency($it['percentual']);
                
                $m[] = $it;
            }
        }
      }

      // --- FILTRAGEM E LIMPEZA DE ADITIVOS ---
      $a = []; 
      if (is_array($a_raw)) {
        foreach ($a_raw as $it) {
            if (!empty($it['numero_aditivo']) || !empty($it['valor_aditivo_total'])) {
                if (isset($it['valor_aditivo_total'])) $it['valor_aditivo_total'] = coh_clean_currency($it['valor_aditivo_total']);
                if (isset($it['valor_total_apos_aditivo'])) $it['valor_total_apos_aditivo'] = coh_clean_currency($it['valor_total_apos_aditivo']);
                $a[] = $it;
            }
        }
      }

      // --- FILTRAGEM E LIMPEZA DE REAJUSTES ---
      $r = []; 
      if (is_array($r_raw)) {
        foreach ($r_raw as $it) {
            if (!empty($it['indice']) || !empty($it['valor_total_apos_reajuste'])) {
                if (isset($it['percentual'])) $it['percentual'] = coh_clean_currency($it['percentual']);
                if (isset($it['valor_total_apos_reajuste'])) $it['valor_total_apos_reajuste'] = coh_clean_currency($it['valor_total_apos_reajuste']);
                $r[] = $it;
            }
        }
      }

      // INSERÇÃO
      if ($m) coh_insert_medicoes_from_array($conn,$contrato_id,$m);
      if ($a) coh_insert_aditivos_from_array($conn,$contrato_id,$a);
      if ($r) coh_insert_reajustes_from_array($conn,$contrato_id,$r);

      // Deletes individuais
      if (!empty($_POST['delete_medicao_id']))  coh_delete_medicao($conn,$contrato_id,(int)$_POST['delete_medicao_id']);
      if (!empty($_POST['delete_aditivo_id']))  coh_delete_aditivo($conn,$contrato_id,(int)$_POST['delete_aditivo_id']);
      if (!empty($_POST['delete_reajuste_id'])) coh_delete_reajuste($conn,$contrato_id,(int)$_POST['delete_reajuste_id']);

      // =========================================================
      // 3. ATUALIZAÇÃO DOS TOTAIS DO CONTRATO
      // =========================================================
      if ($contrato_id > 0) {
        $baseContrato = 0.0;
        if ($stB = $conn->prepare("SELECT COALESCE(Valor_Do_Contrato, 0) FROM emop_contratos WHERE id=?")) {
          $stB->bind_param('i', $contrato_id); $stB->execute(); $stB->bind_result($baseContrato); $stB->fetch(); $stB->close();
        }

        $totalAditivos = 0.0; $valorAposAditivos = 0.0;
        if ($stA = $conn->prepare("SELECT valor_aditivo_total, valor_total_apos_aditivo FROM emop_aditivos WHERE contrato_id=? ORDER BY created_at ASC, id ASC")) {
          $stA->bind_param('i', $contrato_id); $stA->execute(); $resA = $stA->get_result();
          while ($ra = $resA->fetch_assoc()) {
            $totalAditivos += (float)($ra['valor_aditivo_total'] ?? 0);
            $vApos = (float)($ra['valor_total_apos_aditivo'] ?? 0);
            if ($vApos > 0) $valorAposAditivos = $vApos;
          }
          $stA->close();
        }
        if ($valorAposAditivos <= 0 && ($baseContrato > 0 || $totalAditivos > 0)) $valorAposAditivos = $baseContrato + $totalAditivos;

        $valorAposReajustes = 0.0;
        if ($stR = $conn->prepare("SELECT valor_total_apos_reajuste FROM emop_reajustamento WHERE contrato_id=? ORDER BY created_at ASC, id ASC")) {
          $stR->bind_param('i', $contrato_id); $stR->execute(); $resR = $stR->get_result();
          while ($rr = $resR->fetch_assoc()) {
            $vAposR = (float)($rr['valor_total_apos_reajuste'] ?? 0);
            if ($vAposR > 0) $valorAposReajustes = $vAposR;
          }
          $stR->close();
        }
        $totalReajustes = 0.0;
        if ($valorAposReajustes > 0) {
          $totalReajustes = ($valorAposAditivos > 0) ? ($valorAposReajustes - $valorAposAditivos) : ($valorAposReajustes - ($baseContrato + $totalAditivos));
          if ($totalReajustes < 0) $totalReajustes = 0.0;
        }

        $valorTotalAtualContrato = ($valorAposReajustes > 0) ? $valorAposReajustes : (($valorAposAditivos > 0) ? $valorAposAditivos : ($baseContrato + $totalAditivos + $totalReajustes));

        $vtc = $valorTotalAtualContrato > 0 ? $valorTotalAtualContrato : $baseContrato;
        $ult = coh_fetch_medicoes_with_prev($conn, $contrato_id);
        if (!empty($ult)) {
          $acum = 0.0;
          foreach ($ult as $mm) {
            if (isset($mm['acumulado_rs']) && is_numeric($mm['acumulado_rs'])) $acum = (float)$mm['acumulado_rs'];
            else $acum += (float)($mm['valor_rs'] ?? 0);
          }
          $last = end($ult);
          $vlr_med = (float)($last['valor_rs'] ?? 0);
          if ($acum <= 0 && isset($last['acumulado_rs']) && is_numeric($last['acumulado_rs'])) $acum = (float)$last['acumulado_rs'];
          $perc_exec = $vtc > 0 ? ($acum / $vtc) * 100.0 : 0.0;

          $conn->query("UPDATE emop_contratos SET Valor_Liquidado_Na_Medicao_RS = '{$vlr_med}', Valor_Liquidado_Acumulado = '{$acum}', Percentual_Executado = '{$perc_exec}' WHERE id = {$contrato_id}");
        }

        $conn->query("UPDATE emop_contratos SET Aditivos_RS = '{$totalAditivos}', Contrato_Apos_Aditivo_Valor_Total_RS = '{$valorAposAditivos}', Valor_Dos_Reajustes_RS = '{$totalReajustes}', Valor_Total_Do_Contrato_Novo = '{$valorTotalAtualContrato}' WHERE id = {$contrato_id}");
      }

      flash_set('success','Alterações salvas com sucesso.');

    } catch (Throwable $e) {
      flash_set('danger','Erro ao salvar: '.$e->getMessage());
    }
  }

  // ===== Workflow Fiscal (1) =====
  if (!$can_edit_immediately && $action === 'solicitar_aprovacao') {
    $campos=[];
    foreach($cols as $c){
      if (!array_key_exists($c, $_POST)) continue;
      $new = is_array($_POST[$c]) ? json_encode($_POST[$c], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$_POST[$c];
      $old = isset($rowNow[$c]) ? (string)$rowNow[$c] : null;
      if (trim((string)$new) !== trim((string)$old)) $campos[$c] = $new;
    }
    $m = $get_array_from_post(['novas_medicoes_json','novas_medicoes']);
    $a = $get_array_from_post(['novos_aditivos_json','novos_aditivos']);
    $r = $get_array_from_post(['novos_reajustes_json','novos_reajustes']);
    $payload = ['contrato_id'=>$contrato_id,'campos'=>$campos];
    if ($m) $payload['novas_medicoes']  = $m;
    if ($a) $payload['novos_aditivos']  = $a;
    if ($r) $payload['novos_reajustes'] = $r;

    if (empty($campos) && empty($m) && empty($a) && empty($r)) {
      flash_set('warning','Nenhuma alteração detectada.');
    } else {
      ensure_coordenador_inbox_schema($conn);
      $dir = (string)($rowNow['Diretoria'] ?? $_POST['Diretoria'] ?? $user_dir);
      if ($dir === 'DIRIM') $dir = 'DIRM';
      $fiscal_id = (int)($_SESSION['user_id'] ?? 0);
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

      $st=$conn->prepare("INSERT INTO coordenador_inbox(contrato_id,diretoria,fiscal_id,payload_json,status) VALUES (?,?,?,?, 'PENDENTE')");
      $st->bind_param("isis", $contrato_id, $dir, $fiscal_id, $json);
      if ($st->execute()) {
        $_SESSION['APROV_PAYLOAD'] = $payload;
        $HIDE_FORM = true;
        require_once __DIR__ . '/php/solicitacao_aprov_sucesso.php';
        exit;
      }
      $st->close();
    }
  }
}

// ===== SELECT do contrato =====
$row = [];
$id  = $contrato_id = (int)$contrato_id;
if ($contrato_id > 0) {
  $alias = 'c';
  $whereScope = build_scope_for_select_simple($conn, $alias, $user_level);
  if ($st = $conn->prepare("SELECT {$alias}.* FROM emop_contratos {$alias} WHERE {$alias}.id=? AND ({$whereScope}) LIMIT 1")) {
    $st->bind_param('i', $contrato_id); $st->execute();
    $rs = $st->get_result(); $row = ($rs && $rs->num_rows) ? $rs->fetch_assoc() : []; $st->close();
  }
}

// ===== Layout =====
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/topbar.php';
require_once __DIR__ . '/partials/sidebar.php';

echo '<main class="container my-4">';
if ($contrato_id > 0 && empty($row)) echo '<div class="alert alert-warning">Contrato não encontrado ou sem permissão.</div>';
if (!$HIDE_FORM && ($contrato_id || $is_new)) require_once __DIR__ . '/partials/form_emop_contratos.php';
else require_once __DIR__ . '/partials/form_contratos_busca.php';
echo '</main>';

require_once __DIR__ . '/partials/modal_coord_inbox.php';
require_once __DIR__ . '/partials/footer.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var form = document.querySelector('form[data-form="emop-contrato"]') || document.getElementById('coh-form');
  if (!form) return;

  var hidden = form.querySelector('input[name="action"]');
  if (!hidden) { hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='action'; form.appendChild(hidden); }

  function ensureDraftInputsAndFill(){
    try {
      var med = form.querySelector('input[name="novas_medicoes_json"]'); if (!med) { med=document.createElement('input'); med.type='hidden'; med.name='novas_medicoes_json'; form.appendChild(med); }
      var adi = form.querySelector('input[name="novos_aditivos_json"]'); if (!adi) { adi=document.createElement('input'); adi.type='hidden'; adi.name='novos_aditivos_json'; form.appendChild(adi); }
      var rea = form.querySelector('input[name="novos_reajustes_json"]'); if (!rea) { rea=document.createElement('input'); rea.type='hidden'; rea.name='novos_reajustes_json'; form.appendChild(rea); }
      
      var D = (window.COH && COH.draft) ? COH.draft : {medicoes:[],aditivos:[],reajustes:[]};
      med.value = JSON.stringify(D.medicoes || []);
      adi.value = JSON.stringify(D.aditivos || []);
      rea.value = JSON.stringify(D.reajustes || []);
    } catch(e){}
  }

  var btnSalvar = document.getElementById('btnSalvarContrato');
  if (btnSalvar) btnSalvar.addEventListener('click', function(e){ e.preventDefault(); hidden.value = 'salvar'; ensureDraftInputsAndFill(); form.submit(); });
});
</script>

<script>
(function () {
  if (!window.COH || !COH.draft) return;

  function renderMed(){ if (window.cohRenderDraft) window.cohRenderDraft('draft-list-medicoes',  COH.draft.medicoes); }
  function renderAdi(){ if (window.cohRenderDraft) window.cohRenderDraft('draft-list-aditivos',  COH.draft.aditivos); }
  function renderRea(){ if (window.cohRenderDraft) window.cohRenderDraft('draft-list-reajustes', COH.draft.reajustes); }
  function syncHidden(){ if (window.cohSetHiddenDraft) window.cohSetHiddenDraft(); }

  window.cohAddMedicao = function(p){
    const obj = Object.assign({ _label: `Medição ${p.data_medicao}`, _desc: `Valor: ${p.valor_rs}` }, p);
    COH.draft.medicoes.push(obj); syncHidden(); renderMed();
  };
  window.cohAddAditivo = function(p){
    const obj = Object.assign({ _label: `Aditivo ${p.numero_aditivo}`, _desc: `Valor: ${p.valor_aditivo_total}` }, p);
    COH.draft.aditivos.push(obj); syncHidden(); renderAdi();
  };
  window.cohAddReajuste = function(p){
    const obj = Object.assign({ _label: `Reajuste ${p.indice}`, _desc: `Perc: ${p.percentual}` }, p);
    COH.draft.reajustes.push(obj); syncHidden(); renderRea();
  };
})();

// === FUNÇÕES DE AÇÃO NO BANCO (Exclusão e Edição de itens salvos) ===

// 1. Exclusão
window.cohDeleteDbItem = function(tipo, id) {
    if (!confirm('ATENÇÃO: Você está prestes a excluir um registro salvo no banco.\n\nSe este item afetar o acumulado, os valores serão recalculados.\nDeseja continuar?')) return;
    const fd = new FormData(); fd.append('action','delete_item'); fd.append('type',tipo); fd.append('id',id);
    
    fetch('ajax/delete_contract_item.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.success) { alert('Excluído com sucesso!'); location.reload(); }
        else alert('Erro: ' + (d.message||'Desconhecido'));
    })
    .catch(e => alert('Erro de conexão.'));
};

// 2. Edição (Carregar no Modal)
window.cohEditDbItem = function(tipo, item) {
    let modalId = '';
    const fmt = v => (typeof v === 'number') ? v.toLocaleString('pt-BR',{minimumFractionDigits:2}) : (v||'');

    if (tipo === 'medicao') {
        modalId = 'modalMedicao';
        let root = document.getElementById(modalId);
        if(root){
            let inpData = root.querySelector('input[name="data_medicao"]');
            let inpValor = root.querySelector('input[name="valor_rs"]');
            let txtObs = root.querySelector('textarea[name="observacao"]');

            if(inpData) inpData.value = item.data_medicao ? item.data_medicao.split(' ')[0] : '';
            if(inpValor) inpValor.value = fmt(item.valor_rs);
            if(txtObs) txtObs.value = item.observacao || '';
            
            // Dispara input para recalcular acumulado e percentual
            if(inpValor) inpValor.dispatchEvent(new Event('input', {bubbles:true}));
        }
    } 
    else if (tipo === 'aditivo') {
        modalId = 'modalAditivo';
        let root = document.getElementById(modalId);
        if(root){
            let inpNum = root.querySelector('input[name="numero_aditivo"]');
            let inpData = root.querySelector('input[name="data"]');
            let selTipo = root.querySelector('select[name="tipo"]');
            let inpValAd = root.querySelector('input[name="valor_aditivo_total"]');
            let inpValTot = root.querySelector('input[name="valor_total_apos_aditivo"]');
            let txtObs = root.querySelector('textarea[name="observacao"]');

            if(inpNum) inpNum.value = item.numero_aditivo || '';
            if(inpData) inpData.value = item.data || (item.created_at ? item.created_at.split(' ')[0] : '');
            if(selTipo) selTipo.value = item.tipo || '';
            if(inpValAd) inpValAd.value = fmt(item.valor_aditivo_total);
            if(inpValTot) inpValTot.value = fmt(item.valor_total_apos_aditivo);
            if(txtObs) txtObs.value = item.observacao || '';
            
            if(inpValAd) inpValAd.dispatchEvent(new Event('input', {bubbles:true}));
        }
    } 
    else if (tipo === 'reajuste') {
        modalId = 'modalReajuste';
        let root = document.getElementById(modalId);
        if(root){
            let inpInd = root.querySelector('input[name="indice"]');
            let inpPerc = root.querySelector('input[name="percentual"]');
            let inpData = root.querySelector('input[name="data_base"]');
            let inpValTot = root.querySelector('input[name="valor_total_apos_reajuste"]');
            let txtObs = root.querySelector('textarea[name="observacao"]');

            if(inpInd) inpInd.value = item.indice || '';
            if(inpPerc) inpPerc.value = item.percentual || (item.reajustes_percentual ? String(item.reajustes_percentual).replace('.',',') : '');
            if(inpData) inpData.value = item.data_base || '';
            if(inpValTot) inpValTot.value = fmt(item.valor_total_apos_reajuste);
            if(txtObs) txtObs.value = item.observacao || '';
        }
    }

    if(modalId) {
        let m = bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId));
        m.show();
        setTimeout(()=> alert('MODO DE EDIÇÃO:\n\n1. Dados carregados no formulário.\n2. Faça os ajustes e salve como NOVO.\n3. Exclua o item antigo da lista abaixo.'), 300);
    }
};
</script>