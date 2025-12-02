<?php
// form_contratos.php — EMOP • Contratos

ini_set('display_errors', '1');
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
// [AUXILIAR] Limpeza de Moeda e JSON
// =========================================================================
function coh_clean_currency($val) {
    if (empty($val) && $val !== '0') return null;
    $val = preg_replace('/\xc2\xa0/', '', $val);
    $val = trim($val);
    if (is_numeric($val)) return $val; 
    $val = preg_replace('/[^\d.,\-]/', '', $val);
    if ($val === '') return null;
    if (strpos($val, ',') !== false) {
        $val = str_replace('.', '', $val); 
        $val = str_replace(',', '.', $val); 
    }
    return $val;
}

$decode_json_array = function($v){
  if (is_array($v)) return $v;
  if (empty($v) || !is_string($v)) return [];
  $v = trim($v);
  if ($v === '' || $v === '[]') return [];
  $d = json_decode($v, true);
  if (is_string($d)) $d = json_decode($d, true);
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

// === COMPARAÇÃO DE VALORES ===
function coh_values_are_equal($new, $old) {
    if (is_null($new)) $new = ''; 
    if (is_null($old)) $old = '';
    
    $new = trim((string)$new);
    $old = trim((string)$old);
    
    $new = html_entity_decode($new, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $old = html_entity_decode($old, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    if ($new === $old) return true;
    
    if (str_replace(["\r\n", "\r"], "\n", $new) === str_replace(["\r\n", "\r"], "\n", $old)) return true;

    // Números
    $isNewNumeric = is_numeric(str_replace(['.',','], '', $new)); 
    if ($isNewNumeric) {
        $newNum = coh_clean_currency($new);
        $oldNum = coh_clean_currency($old);
        if (is_numeric($newNum) && is_numeric($oldNum)) {
            if (abs((float)$newNum - (float)$oldNum) < 0.01) return true;
        }
        if (($new === '' && is_numeric($old) && abs((float)$old) == 0) ||
            ($old === '' && is_numeric($new) && abs((float)$new) == 0)) {
            return true;
        }
    }

    // Datas
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $new, $m)) {
        $newIso = "{$m[3]}-{$m[2]}-{$m[1]}";
        if ($newIso === $old) return true;
        if (substr($old, 0, 10) === $newIso) return true;
    }
    return false;
}

// === HELPER DE RASCUNHOS ===
function coh_process_draft_arrays($get_array_fn, &$alteracoes_log) {
    $m_raw = $get_array_fn(['novas_medicoes_json','novas_medicoes']);
    $m = [];
    if(is_array($m_raw)){
        foreach($m_raw as $it){
            if(!empty($it['data']) || !empty($it['valor_rs'])){
                $m[]=$it; $alteracoes_log[] = "Nova Medição.";
            }
        }
    }
    $a_raw = $get_array_fn(['novos_aditivos_json','novos_aditivos']);
    $a = [];
    if(is_array($a_raw)){
        foreach($a_raw as $it){
            $num = $it['numero_aditivo'] ?? $it['numero'] ?? $it['num_aditivo'] ?? '';
            $val = $it['valor_aditivo_total'] ?? $it['valor'] ?? '';
            $prz = $it['prazo_dias'] ?? $it['prazo'] ?? '';
            if(strlen((string)$num) > 0 || !empty($val) || !empty($prz)){
                if(empty($it['numero_aditivo'])) $it['numero_aditivo'] = $num ?: 'S/N';
                $a[] = $it; $alteracoes_log[] = "Novo Aditivo" . ($num ? " #$num" : "") . ".";
            }
        }
    }
    $r_raw = $get_array_fn(['novos_reajustes_json','novos_reajustes']);
    $r = [];
    if(is_array($r_raw)){
        foreach($r_raw as $it){
            $db = $it['data_base'] ?? $it['mes_ref'] ?? $it['mes'] ?? '';
            if(!empty($db) || !empty($it['indice'])){
                if(empty($it['data_base'])) $it['data_base'] = $db;
                $r[] = $it; $alteracoes_log[] = "Novo Reajuste.";
            }
        }
    }
    return [$m, $a, $r];
}

// ===== Garantia schema inbox =====
function ensure_coordenador_inbox_schema(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS coordenador_inbox(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, contrato_id INT NOT NULL, diretoria VARCHAR(100), fiscal_id INT, payload_json LONGTEXT, status VARCHAR(20) DEFAULT 'PENDENTE', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, processed_by INT NULL, processed_at DATETIME NULL, reason TEXT NULL, KEY (contrato_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $is_ai = false; $is_pk = false;
  if ($rs = $conn->query("SHOW COLUMNS FROM coordenador_inbox LIKE 'id'")) {
      $col = $rs->fetch_assoc();
      if ($col) {
          if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) $is_ai = true;
          if (($col['Key'] ?? '') === 'PRI') $is_pk = true;
      }
  }
  if (!$is_ai) {
      if (!$is_pk) $conn->query("ALTER TABLE coordenador_inbox ADD PRIMARY KEY (id)");
      $conn->query("ALTER TABLE coordenador_inbox MODIFY id INT NOT NULL AUTO_INCREMENT");
  }
}

// =====================================================
// POST — salvar direto (2/5) e workflow (1)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  $rowNow = [];
  if ($contrato_id > 0 && ($st=$conn->prepare("SELECT * FROM emop_contratos WHERE id=? LIMIT 1"))) {
    $st->bind_param("i",$contrato_id); $st->execute();
    $rs=$st->get_result(); $rowNow = ($rs && $rs->num_rows) ? $rs->fetch_assoc() : []; $st->close();
  }
  $cols=[]; if ($rs=$conn->query("SHOW COLUMNS FROM emop_contratos")){ while($r=$rs->fetch_assoc()) $cols[]=$r['Field']; $rs->free(); }

  // === LISTA DE CAMPOS A IGNORAR ===
  $ignoreList = [
      'id', 'created_at', 'updated_at', 'Ultima_Alteracao', // SISTEMA
      'Percentual_Executado',
      'Valor_Liquidado_Acumulado',
      'Valor_Liquidado_Na_Medicao_RS',
      'Medicao_Anterior_Acumulada_RS',
      'Saldo_Contratual_Atualizado',
      'Valor_Total_Aditivos',
      'Valor_Total_Reajustes'
  ];

  // ===== Salvar direto (2/5) =====
  if ($can_edit_immediately && in_array($action, ['salvar','salvar_direto'], true)) {
    $alteracoes_realizadas = [];
    try {
      if ($contrato_id > 0 && !empty($cols)) {
        $sets=[]; $params=[]; $types='';
        
        foreach ($cols as $c) {
          if (in_array($c, $ignoreList, true)) continue; 
          if (!array_key_exists($c, $_POST)) continue;
          
          $v = $_POST[$c];
          if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          else $v = trim((string)$v);

          // Tratamento de Data: Se vazio, VIRA NULL (Correção do Erro Incorrect date value)
          if (preg_match('/(Data|Inicio|Fim|Termino|Assinatura|Prazo)/i', $c)) {
             if ($v === '') {
                 $v = null;
             } elseif (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $v, $mData)) {
                 $v = "{$mData[3]}-{$mData[2]}-{$mData[1]}";
             }
          }

          if ((stripos($c, 'Valor_')===0 || stripos($c, 'Percentual_')===0 || stripos($c, 'Saldo_')===0) && $v!=='' && $v!==null) {
             $clean = coh_clean_currency($v);
             if ($clean !== null) $v = $clean;
          }

          $oldVal = isset($rowNow[$c]) ? (string)$rowNow[$c] : '';
          if (!coh_values_are_equal($v, $oldVal)) {
              $alteracoes_realizadas[] = "Campo <strong>".str_replace('_',' ',$c)."</strong> atualizado.";
          }

          $sets[]="`{$c}`=?"; $params[]=$v; $types.='s';
        }

        if ($sets) {
          $sqlU="UPDATE emop_contratos SET ".implode(', ',$sets)." WHERE id=? LIMIT 1";
          if ($stU=$conn->prepare($sqlU)) {
            $types.='i'; $params[]=$contrato_id;
            // Unpack params
            $stU->bind_param($types,...$params); 
            if (!$stU->execute()) {
                throw new Exception("Erro no Banco ao atualizar: " . $stU->error);
            }
            $stU->close();
          } else {
             throw new Exception("Erro ao preparar query: " . $conn->error);
          }
        }
      }

      list($m, $a, $r) = coh_process_draft_arrays($get_array_from_post, $alteracoes_realizadas);
      if($m) coh_insert_medicoes_from_array($conn,$contrato_id,$m);
      if($a) coh_insert_aditivos_from_array($conn,$contrato_id,$a);
      if($r) coh_insert_reajustes_from_array($conn,$contrato_id,$r);
      
      $conn->query("UPDATE emop_contratos SET Valor_Liquidado_Acumulado = (SELECT SUM(valor_rs) FROM emop_medicoes WHERE contrato_id=$contrato_id) WHERE id=$contrato_id");

      if (!empty($alteracoes_realizadas)) $_SESSION['feedback_changes'] = $alteracoes_realizadas;
      
      flash_set('success','Alterações salvas com sucesso.');
      header("Location: form_contratos.php?id=" . $contrato_id . "&t=" . time());
      exit;

    } catch (Throwable $e) { flash_set('danger','Erro ao salvar: '.$e->getMessage()); }
  }

  // ===== Workflow Fiscal (1) - SOLICITAR APROVAÇÃO =====
  if (!$can_edit_immediately && $action === 'solicitar_aprovacao') {
    $alteracoes_debug = []; $campos=[];
    foreach($cols as $c){
      if (in_array($c, $ignoreList, true)) continue;
      if (!array_key_exists($c, $_POST)) continue;
      
      $rawNew = $_POST[$c];
      $new = is_array($rawNew) ? json_encode($rawNew, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : trim((string)$rawNew);
      $old = isset($rowNow[$c]) ? (string)$rowNow[$c] : '';
      
      if (coh_values_are_equal($new, $old)) continue; 

      if (preg_match('/(Data|Inicio|Fim|Termino|Assinatura|Prazo)/i', $c)) {
         if ($new === '') {
             $new = null;
         } elseif (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $new, $mData)) {
             $new = "{$mData[3]}-{$mData[2]}-{$mData[1]}";
         }
      }

      if ((stripos($c, 'Valor_')===0 || stripos($c, 'Percentual_')===0 || stripos($c, 'Saldo_')===0) && $new!=='' && $new!==null) {
         $clean = coh_clean_currency($new);
         if ($clean !== null) $new = $clean;
      }
      if (coh_values_are_equal($new, $old)) continue;
      $campos[$c] = $new;
    }

    list($m, $a, $r) = coh_process_draft_arrays($get_array_from_post, $alteracoes_debug);
    $payload = ['contrato_id'=>$contrato_id, 'campos'=>$campos];
    if ($m) $payload['novas_medicoes']  = $m;
    if ($a) $payload['novos_aditivos']  = $a;
    if ($r) $payload['novos_reajustes'] = $r;

    if (!empty($campos) || !empty($m) || !empty($a) || !empty($r)) {
      ensure_coordenador_inbox_schema($conn);
      $dir = (string)($rowNow['Diretoria'] ?? $_POST['Diretoria'] ?? $user_dir);
      if ($dir === 'DIRIM') $dir = 'DIRM';
      $fiscal_id = (int)($_SESSION['user_id'] ?? 0);
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

      $st=$conn->prepare("INSERT INTO coordenador_inbox(contrato_id,diretoria,fiscal_id,payload_json,status) VALUES (?,?,?,?, 'PENDENTE')");
      if ($st) {
          $st->bind_param("isis", $contrato_id, $dir, $fiscal_id, $json);
          $success = $st->execute();
          if (!$success && $conn->errno == 1364) { 
             $conn->query("ALTER TABLE coordenador_inbox MODIFY id INT NOT NULL AUTO_INCREMENT");
             $success = $st->execute();
          }
          if ($success) {
            $req_id = $st->insert_id;
            $_SESSION['APROV_PAYLOAD'] = $payload;
            $HIDE_FORM = true;
            $__success_ctx = ['req_id' => $req_id];
            require __DIR__ . '/php/solicitacao_aprov_sucesso.php';
            exit;
          } else { flash_set('danger', 'Erro ao salvar solicitação: ' . $st->error); }
          $st->close();
      }
    } else { flash_set('warning','Nenhuma alteração real foi detectada.'); }
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

/* =====================================================================
   REAPLICAR CAMPOS SOLICITADOS PELO FISCAL QUANDO HOUVER REVISÃO
   - Quando status em coordenador_inbox for REVISAO_SOLICITADA ou REJEITADO
   - E o usuário atual for o fiscal (nível 1), o form deve mostrar
     exatamente os valores que ele havia pedido na última solicitação.
===================================================================== */
if ($contrato_id > 0 && !$can_edit_immediately && $user_level === 1 && !empty($row)) {
    $fiscalId = (int)($_SESSION['user_id'] ?? 0);
    if ($fiscalId > 0) {
        if ($stRev = $conn->prepare("
            SELECT payload_json
              FROM coordenador_inbox
             WHERE contrato_id = ?
               AND fiscal_id   = ?
               AND status IN ('REVISAO_SOLICITADA','REJEITADO')
             ORDER BY id DESC
             LIMIT 1
        ")) {
            $stRev->bind_param('ii', $contrato_id, $fiscalId);
            $stRev->execute();
            $rsRev = $stRev->get_result();
            if ($rsRev && $rsRev->num_rows) {
                $rowRev = $rsRev->fetch_assoc();
                $payloadJson = $rowRev['payload_json'] ?? '';
                if (!empty($payloadJson)) {
                    $payload = json_decode($payloadJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $payload = json_decode(stripslashes((string)$payloadJson), true);
                    }
                    if (is_array($payload) && !empty($payload['campos']) && is_array($payload['campos'])) {
                        foreach ($payload['campos'] as $campo => $valorNovo) {
                            // Sobrescreve o valor do contrato com o que o fiscal tinha solicitado
                            $row[$campo] = $valorNovo;
                        }
                    }
                }
            }
            $stRev->close();
        }
    }
}

// ===== Layout =====
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/topbar.php';
require_once __DIR__ . '/partials/sidebar.php';

echo '<main class="container my-4">';
if ($contrato_id > 0 && empty($row)) { echo '<div class="alert alert-warning mb-3">Contrato não encontrado ou sem permissão.</div>'; }
if (isset($_SESSION['feedback_changes']) && is_array($_SESSION['feedback_changes'])) {
    echo '<div class="alert alert-info alert-dismissible fade show shadow-sm mb-3" role="alert"><h6 class="alert-heading"><i class="bi bi-info-circle-fill"></i> Resumo das alterações realizadas:</h6><ul class="mb-0 small">';
    foreach ($_SESSION['feedback_changes'] as $msg) { echo "<li>{$msg}</li>"; }
    echo '</ul><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    unset($_SESSION['feedback_changes']);
}
if (isset($_SESSION['flash_messages'])) {
     foreach ($_SESSION['flash_messages'] as $f) {
         $type = ($f['type'] === 'error') ? 'danger' : $f['type'];
         echo '<div class="alert alert-'.$type.' alert-dismissible fade show mb-3" role="alert">' . $f['message'] . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
     }
     unset($_SESSION['flash_messages']);
}

// [NOVO] Exibir alerta de Rejeição ou Revisão no topo do formulário
if ($contrato_id > 0) {
    // Busca a última mensagem de revisão/rejeição pendente
    $sqlMsg = "SELECT reason, review_notes, motivo_rejeicao, status 
               FROM coordenador_inbox 
               WHERE contrato_id = {$contrato_id} 
                 AND status IN ('REVISAO_SOLICITADA', 'REJEITADO') 
               ORDER BY id DESC LIMIT 1";
               
    if ($rsMsg = $conn->query($sqlMsg)) {
        if ($rowMsg = $rsMsg->fetch_assoc()) {
            // Tenta pegar o motivo na ordem de prioridade das colunas
            $msgReason = $rowMsg['reason'] ?? $rowMsg['review_notes'] ?? $rowMsg['motivo_rejeicao'] ?? '';
            $msgStatus = $rowMsg['status'];
            
            // Só exibe se tiver algum texto de motivo
            if (!empty($msgReason)) {
                $alertType = ($msgStatus === 'REJEITADO') ? 'danger' : 'warning';
                $icon = ($msgStatus === 'REJEITADO') ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill';
                $titulo = ($msgStatus === 'REJEITADO') ? 'Solicitação Rejeitada' : 'Revisão Solicitada pelo Coordenador';
                
                echo "<div class='alert alert-{$alertType} alert-dismissible fade show shadow-sm mb-4' role='alert'>
                        <h5 class='alert-heading'><i class='bi {$icon}'></i> {$titulo}</h5>
                        <p class='mb-0'><strong>Motivo:</strong> " . nl2br(htmlspecialchars($msgReason)) . "</p>
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                      </div>";
            }
        }
    }
}

if (!$HIDE_FORM && ($contrato_id || $is_new)) require_once __DIR__ . '/partials/form_emop_contratos.php';
else if (!$HIDE_FORM) require_once __DIR__ . '/partials/form_contratos_busca.php';
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
      med.value = JSON.stringify(D.medicoes || []); adi.value = JSON.stringify(D.aditivos || []); rea.value = JSON.stringify(D.reajustes || []);
    } catch(e){ console.error('Erro preparando draft:', e); }
  }
  var btnSalvar = document.getElementById('btnSalvarContrato');
  if (btnSalvar) { btnSalvar.addEventListener('click', function(e){ e.preventDefault(); hidden.value = 'salvar'; ensureDraftInputsAndFill(); form.submit(); }); }
  var btnAprovar = document.getElementById('btnSolicitarAprovacao') || document.querySelector('button[name="action"][value="solicitar_aprovacao"]');
  if (btnAprovar) { btnAprovar.addEventListener('click', function(e){ e.preventDefault(); hidden.value = 'solicitar_aprovacao'; ensureDraftInputsAndFill(); if(confirm('Confirma o envio?')) { form.submit(); } }); }
});
(function () {
  if (!window.COH || !COH.draft) return;
  function renderMed(){ if (window.cohRenderDraft) window.cohRenderDraft('draft-list-medicoes',  COH.draft.medicoes); }
  function renderAdi(){ if (window.cohRenderDraft) window.cohRenderDraft('draft-list-aditivos',  COH.draft.aditivos); }
  function renderRea(){ if (window.cohRenderDraft) window.cohRenderDraft('draft-list-reajustes', COH.draft.reajustes); }
  function syncHidden(){ if (window.cohSetHiddenDraft) window.cohSetHiddenDraft(); }
  window.cohAddMedicao = function(p){ COH.draft.medicoes.push(p); syncHidden(); renderMed(); };
  window.cohAddAditivo = function(p){ if(!p.numero_aditivo && p.numero) p.numero_aditivo = p.numero; if(!p.numero_aditivo && p.num_aditivo) p.numero_aditivo = p.num_aditivo; COH.draft.aditivos.push(p); syncHidden(); renderAdi(); };
  window.cohAddReajuste = function(p){ if(!p.data_base && p.mes) p.data_base = p.mes; COH.draft.reajustes.push(p); syncHidden(); renderRea(); };
})();
window.cohDeleteDbItem = function(tipo, id) { if (!confirm('Deseja excluir?')) return; const fd = new FormData(); fd.append('action','delete_item'); fd.append('type',tipo); fd.append('id',id); fetch('ajax/delete_contract_item.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if(d.success) location.reload(); else alert('Erro: ' + d.message); }); };
window.cohEditDbItem = function(tipo, item) { let mEl = document.getElementById((tipo === 'medicao') ? 'modalMedicao' : ((tipo === 'aditivo') ? 'modalAditivo' : 'modalReajuste')); if(mEl){ bootstrap.Modal.getOrCreateInstance(mEl).show(); setTimeout(()=> alert('MODO DE EDIÇÃO:\n1. Crie NOVO.\n2. Exclua ANTIGO.'), 300); } };
document.addEventListener('DOMContentLoaded', function() { setInterval(() => { document.querySelectorAll('.timer-24h').forEach(span => { let s = parseInt(span.getAttribute('data-seconds'), 10); if (s <= 0) { span.innerHTML = "Esgotado"; return; } s--; span.setAttribute('data-seconds', s); span.textContent = new Date(s * 1000).toISOString().substr(11, 8); }); }, 1000); });
</script>
