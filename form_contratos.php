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

// === REGRAS DE PERMISSÃO / FLUXO HIERÁRQUICO ===
// Nível 1 (Fiscal) -> só solicita aprovação
// Nível 5 (Gerenciamento) -> salva direto
$can_edit_immediately = ($user_level === 5); // Gerente/Admin
$can_request_approval = ($user_level === 1); // Fiscal
$is_read_only = !($can_edit_immediately || $can_request_approval);


// =========================================================================
// [AUXILIAR] Limpeza de Moeda (BR -> US) e JSON
// =========================================================================
function coh_clean_currency($val) {
    if (empty($val)) return null;
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

// === FUNÇÃO DE FILTRAGEM DE ITENS (Remove vazios) ===
function coh_filter_items($raw_list, $type) {
    $clean_list = [];
    if (!is_array($raw_list)) return [];
    
    foreach ($raw_list as $it) {
        $isValid = false;
        
        if ($type === 'medicao') {
            $temData = !empty($it['data_medicao']) || !empty($it['data']);
            $temValor = isset($it['valor_rs']) && trim((string)$it['valor_rs']) !== '';
            if ($temData || $temValor) {
                if (isset($it['valor_rs'])) $it['valor_rs'] = coh_clean_currency($it['valor_rs']);
                if (isset($it['acumulado_rs'])) $it['acumulado_rs'] = coh_clean_currency($it['acumulado_rs']);
                if (isset($it['percentual'])) $it['percentual'] = coh_clean_currency($it['percentual']);
                $isValid = true;
            }
        }
        elseif ($type === 'aditivo') {
            if (!empty($it['numero_aditivo']) || !empty($it['valor_aditivo_total']) || !empty($it['tipo']) || !empty($it['novo_prazo']) || !empty($it['observacao'])) {
                if (isset($it['valor_aditivo_total'])) $it['valor_aditivo_total'] = coh_clean_currency($it['valor_aditivo_total']);
                if (isset($it['valor_total_apos_aditivo'])) $it['valor_total_apos_aditivo'] = coh_clean_currency($it['valor_total_apos_aditivo']);
                $isValid = true;
            }
        }
        elseif ($type === 'reajuste') {
             if (!empty($it['data_base']) || !empty($it['percentual']) || !empty($it['valor_total_apos_reajuste'])) {
                if (isset($it['percentual'])) $it['percentual'] = coh_clean_currency($it['percentual']);
                if (isset($it['valor_total_apos_reajuste'])) $it['valor_total_apos_reajuste'] = coh_clean_currency($it['valor_total_apos_reajuste']);
                $isValid = true;
            }
        }

        if ($isValid) $clean_list[] = $it;
    }
    return $clean_list;
}

// === COMPARAÇÃO INTELIGENTE ===
function coh_values_differ($db_val, $form_val, $col_name) {
    $v_db   = trim((string)$db_val);
    $v_form = trim((string)$form_val);
    if ($v_db === '' && $v_form === '') return false;
    if (preg_match('/Date|Data|Inicio|Fim|Assinatura/i', $col_name)) {
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $v_form, $m)) $v_form = "{$m[3]}-{$m[2]}-{$m[1]}";
        $v_db = substr($v_db, 0, 10);
        $v_form = substr($v_form, 0, 10);
    }
    if (preg_match('/Valor|Saldo|Percentual|Dias/i', $col_name)) {
        $f_db   = (float)coh_clean_currency($v_db);
        $f_form = (float)coh_clean_currency($v_form);
        if (abs($f_db - $f_form) < 0.001) return false;
        return true; 
    }
    return $v_db !== $v_form;
}

// === FUNÇÃO DE PERMISSÃO ===
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

// ===== Auditoria de alterações de contrato =====
function ensure_contratos_log_schema(mysqli $conn): void {
  $conn->query("CREATE TABLE IF NOT EXISTS emop_contratos_log (
    id INT NOT NULL AUTO_INCREMENT,
    contrato_id INT NOT NULL,
    usuario_id INT NULL,
    usuario_nome VARCHAR(150) NULL,
    diretoria VARCHAR(100) NULL,
    acao VARCHAR(50) NULL,
    detalhes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contrato (contrato_id),
    KEY idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function coh_log_contrato_change(mysqli $conn, int $contrato_id, string $acao, string $detalhes = ''): void {
  if ($contrato_id <= 0) return;
  ensure_contratos_log_schema($conn);

  if (session_status() === PHP_SESSION_NONE) @session_start();
  $uid  = (int)($_SESSION['user_id'] ?? 0);
  $dir  = (string)($_SESSION['diretoria'] ?? '');
  if ($dir === 'DIRIM') $dir = 'DIRM';

  $nome = '';
  if (!empty($_SESSION['nome'])) {
      $nome = (string)$_SESSION['nome'];
  } else {
      // fallback para o array global de usuário carregado no topo
      global $_USER;
      if (!empty($_USER['nome'])) {
          $nome = (string)$_USER['nome'];
      }
  }

  if ($st = $conn->prepare("INSERT INTO emop_contratos_log (contrato_id,usuario_id,usuario_nome,diretoria,acao,detalhes) VALUES (?,?,?,?,?,?)")) {
    $st->bind_param('iissss', $contrato_id, $uid, $nome, $dir, $acao, $detalhes);
    $st->execute();
    $st->close();
  }
}

// =====================================================
// POST — Salvar Direto (Nível 5) e Workflow (Nível 1)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // Snapshot do contrato
  $rowNow = [];
  if ($contrato_id > 0 && ($st=$conn->prepare("SELECT * FROM emop_contratos WHERE id=? LIMIT 1"))) {
    $st->bind_param("i",$contrato_id); $st->execute();
    $rs=$st->get_result(); $rowNow = ($rs && $rs->num_rows) ? $rs->fetch_assoc() : []; $st->close();
  }
  $cols=[]; if ($rs=$conn->query("SHOW COLUMNS FROM emop_contratos")){ while($r=$rs->fetch_assoc()) $cols[]=$r['Field']; $rs->free(); }

  // LISTA DE COLUNAS IGNORADAS
  $ignoredCols = [
      'id', 'Percentual_Executado', 'Valor_Liquidado_Na_Medicao_RS', 'Valor_Liquidado_Acumulado',
      'Medicao_Anterior_Acumulada_RS', 'Aditivos_RS', 'Contrato_Apos_Aditivo_Valor_Total_RS',
      'Valor_Dos_Reajustes_RS', 'Valor_Total_Do_Contrato_Novo',
      'Saldo_Contratual_Sem_Reajustes_RS', 'Saldo_Contratual_Com_Reajuste_RS'
  ];

  // ===== Salvar direto (SOMENTE Nível 5) =====
  if ($action === 'salvar' || $action === 'salvar_direto') {
    if (!$can_edit_immediately) {
        flash_set('danger', 'Acesso negado: Seu nível de permissão não permite salvar alterações diretamente.');
        header("Location: form_contratos.php?id=" . $contrato_id); exit;
    }

    $alteracoes_realizadas = [];

    try {
      if ($contrato_id > 0 && !empty($cols)) {
        $sets = []; $params = []; $types = '';
        foreach ($cols as $c) {
          if (in_array($c, $ignoredCols, true)) continue;
          if (!array_key_exists($c, $_POST)) continue;

          $v = $_POST[$c];
          if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          else $v = trim((string)$v);

          $isDateLike = (stripos($c, '_Data') !== false) || (stripos($c, 'Data_') === 0) || preg_match('~_Data$~i', $c) || in_array($c, ['Data','Data_Inicio','Data_Fim','Data_Inicial','Data_Final'], true);
          if ($isDateLike) {
            if ($v === '') $v = null;
            elseif (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $v, $mData)) $v = "{$mData[3]}-{$mData[2]}-{$mData[1]}";
          }
          if (strpos($c, 'Valor_') === 0 || strpos($c, 'Percentual_') === 0) {
              $v = coh_clean_currency($v);
          }

          $oldVal = isset($rowNow[$c]) ? (string)$rowNow[$c] : '';
          if (coh_values_differ($oldVal, $v, $c)) {
              $nomeCampo = str_replace('_', ' ', $c);
              $alteracoes_realizadas[] = "Campo <strong>{$nomeCampo}</strong> atualizado.";
          }
          $sets[] = "`{$c}` = ?"; $params[] = $v; $types .= 's';
        }
        if ($sets) {
          $sqlU = "UPDATE emop_contratos SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
          if ($stU = $conn->prepare($sqlU)) {
            $types .= 'i'; $params[] = $contrato_id;
            $stU->bind_param($types, ...$params); $stU->execute(); $stU->close();
          }
        }
      }

      $m = coh_filter_items($get_array_from_post(['novas_medicoes_json','novas_medicoes']), 'medicao');
      $a = coh_filter_items($get_array_from_post(['novos_aditivos_json','novos_aditivos']), 'aditivo');
      $r = coh_filter_items($get_array_from_post(['novos_reajustes_json','novos_reajustes']), 'reajuste');

      if ($m) { coh_insert_medicoes_from_array($conn,$contrato_id,$m); $alteracoes_realizadas[] = count($m) . " Novas Medições."; }
      if ($a) { coh_insert_aditivos_from_array($conn,$contrato_id,$a); $alteracoes_realizadas[] = count($a) . " Novos Aditivos."; }
      if ($r) { coh_insert_reajustes_from_array($conn,$contrato_id,$r); $alteracoes_realizadas[] = count($r) . " Novos Reajustes."; }

      // Recálculo
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

      // === REGISTRA AUDITORIA DA ALTERAÇÃO (APENAS SE HOUVER ALGUMA) ===
      if (!empty($alteracoes_realizadas)) {
          $detalhes_log = strip_tags(implode("\n", $alteracoes_realizadas));
          // ação armazenada genérica, mas não será exibida (SALVAR_DIRETO some da tela)
          coh_log_contrato_change($conn, $contrato_id, 'SALVAR_DIRETO', $detalhes_log);
      }

      flash_set('success','Alterações salvas com sucesso (Gerenciamento).');
      if (!empty($alteracoes_realizadas)) { $_SESSION['feedback_changes'] = $alteracoes_realizadas; }
      header("Location: form_contratos.php?id=" . $contrato_id); exit;

    } catch (Throwable $e) {
      flash_set('danger','Erro ao salvar: '.$e->getMessage());
    }
  }

  // ===== Workflow Fiscal (Nível 1) =====
  if ($action === 'solicitar_aprovacao') {
    if (!$can_request_approval) {
        flash_set('danger', 'Acesso negado: Apenas Fiscais (Nível 1) podem solicitar aprovação.');
        header("Location: form_contratos.php?id=" . $contrato_id); exit;
    }

    // 1) Campos alterados do próprio contrato
    $campos = [];
    foreach ($cols as $c) {
        if (in_array($c, $ignoredCols, true)) continue;
        if (!array_key_exists($c, $_POST)) continue;

        $new = is_array($_POST[$c])
              ? json_encode($_POST[$c], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
              : (string)$_POST[$c];
        $old = isset($rowNow[$c]) ? (string)$rowNow[$c] : null;

        if (coh_values_differ($old, $new, $c)) {
            $campos[$c] = $new;
        }
    }

    // 2) RASCUNHOS (Medições / Aditivos / Reajustes)
    //    - Inclui resumo textual em $campos
    //    - Inclui arrays completos no payload (para uso na aprovação)
    $novas_medicoes  = $get_array_from_post(['novas_medicoes_json','novas_medicoes']);
    $novos_aditivos  = $get_array_from_post(['novos_aditivos_json','novos_aditivos']);
    $novos_reajustes = $get_array_from_post(['novos_reajustes_json','novos_reajustes']);

    // MEDIÇÕES
    if (!empty($novas_medicoes) && is_array($novas_medicoes)) {
        $linhas = [];
        foreach ($novas_medicoes as $m) {
            if (!is_array($m)) continue;
            $partes = [];

            if (!empty($m['data_medicao'])) {
                $d = $m['data_medicao'];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $d = date('d/m/Y', strtotime($d));
                }
                $partes[] = "Data: {$d}";
            }
            if (!empty($m['valor_rs'])) {
                $partes[] = "Valor: {$m['valor_rs']}";
            }
            if (!empty($m['acumulado_rs'])) {
                $partes[] = "Acumulado: {$m['acumulado_rs']}";
            }
            if (!empty($m['percentual'])) {
                $partes[] = "%: {$m['percentual']}";
            }
            if (!empty($m['observacao'])) {
                $partes[] = "Obs: {$m['observacao']}";
            }

            if ($partes) {
                $linhas[] = implode(' | ', $partes);
            }
        }
        if ($linhas) {
            $campos['NOVAS_MEDICOES'] = implode("\n", $linhas);
        }
    }

    // ADITIVOS
    if (!empty($novos_aditivos) && is_array($novos_aditivos)) {
        $linhas = [];
        foreach ($novos_aditivos as $a) {
            if (!is_array($a)) continue;
            $partes = [];

            if (!empty($a['numero_aditivo'])) {
                $partes[] = "Número: {$a['numero_aditivo']}";
            }
            if (!empty($a['data'])) {
                $d = $a['data'];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $d = date('d/m/Y', strtotime($d));
                }
                $partes[] = "Data: {$d}";
            }
            if (!empty($a['tipo'])) {
                $partes[] = "Tipo: {$a['tipo']}";
            }
            if (!empty($a['valor_aditivo_total'])) {
                $partes[] = "Valor: {$a['valor_aditivo_total']}";
            }
            if (!empty($a['valor_total_apos_aditivo'])) {
                $partes[] = "Total após: {$a['valor_total_apos_aditivo']}";
            }
            if (!empty($a['novo_prazo'])) {
                $partes[] = "Novo prazo: {$a['novo_prazo']}";
            }
            if (!empty($a['observacao'])) {
                $partes[] = "Obs: {$a['observacao']}";
            }

            if ($partes) {
                $linhas[] = implode(' | ', $partes);
            }
        }
        if ($linhas) {
            $campos['NOVOS_ADITIVOS'] = implode("\n", $linhas);
        }
    }

    // REAJUSTES
    if (!empty($novos_reajustes) && is_array($novos_reajustes)) {
        $linhas = [];
        foreach ($novos_reajustes as $rj) {
            if (!is_array($rj)) continue;
            $partes = [];

            if (!empty($rj['data_base'])) {
                $d = $rj['data_base'];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $d = date('d/m/Y', strtotime($d));
                }
                $partes[] = "Data base: {$d}";
            }
            if (!empty($rj['percentual'])) {
                $partes[] = "Percentual: {$rj['percentual']}";
            }
            if (!empty($rj['valor_total_apos_reajuste'])) {
                $partes[] = "Total após: {$rj['valor_total_apos_reajuste']}";
            }
            if (!empty($rj['observacao'])) {
                $partes[] = "Obs: {$rj['observacao']}";
            }

            if ($partes) {
                $linhas[] = implode(' | ', $partes);
            }
        }
        if ($linhas) {
            $campos['NOVOS_REAJUSTES'] = implode("\n", $linhas);
        }
    }

    // 3) Monta payload FINAL (contrato + listas completas)
    $payload = [
        'contrato_id'      => $contrato_id,
        'campos'           => $campos,
        'novas_medicoes'   => $novas_medicoes,
        'novos_aditivos'   => $novos_aditivos,
        'novos_reajustes'  => $novos_reajustes,
    ];

    // 4) Grava na inbox do Coordenador [PASSO 3: LÓGICA DE SALVAMENTO]
    ensure_coordenador_inbox_schema($conn);
    $dir = (string)($rowNow['Diretoria'] ?? $_POST['Diretoria'] ?? $user_dir);
    if ($dir === 'DIRIM') $dir = 'DIRM';
    $fiscal_id = (int)($_SESSION['user_id'] ?? 0);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    // Tenta pegar o ID da revisão vindo do input hidden (que criamos no Passo 2-A)
    $review_id_post = (int)($_POST['review_id'] ?? $_GET['review_id'] ?? 0);

    if ($review_id_post > 0) {
        // --- CENÁRIO: REVISÃO (UPDATE) ---
        // Ao atualizar o status para 'PENDENTE', o item some da lista de "Needs Revision" do fiscal
        // e volta a aparecer na lista do Coordenador.
        $st = $conn->prepare("UPDATE coordenador_inbox SET payload_json=?, status='PENDENTE', created_at=NOW() WHERE id=? AND fiscal_id=?");
        $st->bind_param("sii", $json, $review_id_post, $fiscal_id);
    } else {
        // --- CENÁRIO: NOVO PEDIDO (INSERT) ---
        // Cria um registro novo do zero
        $st = $conn->prepare("INSERT INTO coordenador_inbox(contrato_id,diretoria,fiscal_id,payload_json,status) VALUES (?,?,?,?, 'PENDENTE')");
        $st->bind_param("isis", $contrato_id, $dir, $fiscal_id, $json);
    }

    if ($st->execute()) {
        $_SESSION['APROV_PAYLOAD'] = $payload;
        $HIDE_FORM = true;
        require_once __DIR__ . '/php/solicitacao_aprov_sucesso.php';
        exit;
    }
    $st->close();
  }
}

// ===== SELECT e Layout =====
$row = []; 
$id = $contrato_id = (int)$contrato_id;
$review_id = (int)($_GET['review_id'] ?? 0); // Captura o ID da revisão
$draft_lists = ['medicoes'=>[], 'aditivos'=>[], 'reajustes'=>[]]; // Inicializa listas
$lastLog = null; // última alteração registrada

// 1. Carrega dados originais do contrato
if ($contrato_id > 0) {
  $alias = 'c';
  $whereScope = build_scope_for_select_simple($conn, $alias, $user_level);
  if ($st = $conn->prepare("SELECT {$alias}.* FROM emop_contratos {$alias} WHERE {$alias}.id=? AND ({$whereScope}) LIMIT 1")) {
    $st->bind_param('i', $contrato_id); $st->execute(); 
    $rs = $st->get_result(); 
    $row = ($rs && $rs->num_rows) ? $rs->fetch_assoc() : []; 
    $st->close();
  }
}

// 2. LÓGICA DE REVISÃO: Se houver review_id, busca o rascunho e sobrescreve
$highlight_fields = []; // Inicializa array de destaques

if ($review_id > 0) {
    // Busca a solicitação na inbox do coordenador
    if ($st = $conn->prepare("SELECT payload_json FROM coordenador_inbox WHERE id = ?")) {
        $st->bind_param('i', $review_id);
        $st->execute();
        $resRev = $st->get_result();
        if ($rowRev = $resRev->fetch_assoc()) {
            $payload = json_decode($rowRev['payload_json'], true);
            
            // A) "OPÇÃO NUCLEAR" - Preenche todas as variações de chaves para garantir que o HTML ache
            if (!empty($payload['campos']) && is_array($payload['campos'])) {
                
                foreach ($payload['campos'] as $key => $val) {
                    // 1. Chave Original (como veio do JSON/HTML)
                    $row[$key] = $val;
                    
                    // 2. Chave Totalmente Minúscula (ex: valor_do_contrato)
                    $row[strtolower($key)] = $val;
                    
                    // 3. Chave Totalmente Maiúscula (ex: VALOR_DO_CONTRATO)
                    $row[strtoupper($key)] = $val;
                    
                    // 4. Tenta adivinhar o padrão do banco (ex: Valor_Do_Contrato)
                    // Útil para campos normais, mas pode falhar em siglas como "Processo_SEI"
                    $capitalized = str_replace(' ', '_', ucwords(str_replace('_', ' ', strtolower($key))));
                    $row[$capitalized] = $val;

                    // Adiciona TODAS as versões na lista de destaque (para o JS pintar a borda vermelha)
                    $highlight_fields[] = $key;
                    $highlight_fields[] = strtolower($key);
                    $highlight_fields[] = $capitalized;
                }

                // Aviso visual
                $_SESSION['flash_messages'][] = ['type'=>'warning', 'message'=>'MODO DE REVISÃO: Corrija os campos destacados em vermelho.'];
            }

            // B) Prepara listas complexas para o JS (Medições/Aditivos)
            if (!empty($payload['novas_medicoes']))  $draft_lists['medicoes']  = $payload['novas_medicoes'];
            if (!empty($payload['novos_aditivos']))  $draft_lists['aditivos']  = $payload['novos_aditivos'];
            if (!empty($payload['novos_reajustes'])) $draft_lists['reajustes'] = $payload['novos_reajustes'];
        }
        $st->close();
    }
}

// 3. Carrega última alteração registrada na auditoria (se existir)
if ($contrato_id > 0) {
    ensure_contratos_log_schema($conn);
    if ($st = $conn->prepare("SELECT contrato_id, usuario_nome, diretoria, acao, detalhes, created_at 
                              FROM emop_contratos_log 
                              WHERE contrato_id=? 
                              ORDER BY created_at DESC, id DESC 
                              LIMIT 1")) {
        $st->bind_param('i', $contrato_id);
        $st->execute();
        $rsLog = $st->get_result();
        if ($rsLog && $rsLog->num_rows) {
            $lastLog = $rsLog->fetch_assoc();
        }
        $st->close();
    }
}

require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/topbar.php';
require_once __DIR__ . '/partials/sidebar.php';

echo '<main class="container my-4">';
if ($contrato_id > 0 && empty($row)) echo '<div class="alert alert-warning mb-3">Contrato não encontrado ou sem permissão.</div>';

if (isset($_SESSION['feedback_changes']) && is_array($_SESSION['feedback_changes'])) {
    echo '<div class="alert alert-info alert-dismissible fade show shadow-sm mb-3"><h6 class="alert-heading">Resumo das alterações:</h6><ul class="mb-0 small">';
    foreach ($_SESSION['feedback_changes'] as $msg) echo "<li>{$msg}</li>";
    echo '</ul><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    unset($_SESSION['feedback_changes']);
}
if (isset($_SESSION['flash_messages'])) {
     foreach ($_SESSION['flash_messages'] as $f) {
         $type = ($f['type'] === 'error') ? 'danger' : $f['type'];
         echo '<div class="alert alert-'.$type.' alert-dismissible fade show mb-3">'.$f['message'].'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
     }
     unset($_SESSION['flash_messages']);
}

if (!$HIDE_FORM && ($contrato_id || $is_new)) {
    require_once __DIR__ . '/partials/form_emop_contratos.php';

    // === RODAPÉ: INFORMAÇÕES DA ÚLTIMA ALTERAÇÃO DO CONTRATO ===
    if ($contrato_id > 0 && !empty($lastLog)) {

        // Formata data
        $dtFmt = '';
        if (!empty($lastLog['created_at'])) {
            $ts = strtotime($lastLog['created_at']);
            if ($ts) {
                $dtFmt = date('d/m/Y \à\s H:i', $ts);
            }
        }

        // Calcula o ÚLTIMO CAMPO atualizado a partir de "detalhes"
        $ultimoCampo = '';
        if (!empty($lastLog['detalhes'])) {
            $detRaw   = (string)$lastLog['detalhes'];
            $detLines = preg_split('/\r\n|\r|\n/', $detRaw);
            if (is_array($detLines) && count($detLines) > 0) {
                $ultimoCampo = trim(end($detLines));
            }
        }

        // Renderiza o card escondido (será reposicionado via JS logo após a seção de medições)
        echo '<section id="contrato-last-change" class="mt-3 mb-2 d-none">';
        echo '  <div class="card border-0 shadow-sm">';
        echo '    <div class="card-body py-2 px-3">';
        echo '      <small class="text-muted d-block mb-1">';
        echo '        <i class="bi bi-clock-history me-1"></i>Última alteração registrada deste contrato';
        echo '      </small>';

        // Linha com usuário / diretoria / data
        echo '      <div class="small">';
        $nome = trim((string)($lastLog['usuario_nome'] ?? ''));
        $dir  = trim((string)($lastLog['diretoria'] ?? ''));
        if ($nome === '') $nome = 'Usuário não identificado';
        echo        e($nome);
        if ($dir !== '') {
            echo ' — Diretoria ' . e($dir);
        }
        if ($dtFmt !== '') {
            echo ' — ' . e($dtFmt);
        }
        echo '      </div>';

        // Apenas o último campo atualizado (sem mostrar "SALVAR_DIRETO")
        if ($ultimoCampo !== '') {
            echo '      <div class="small text-muted mt-1">';
            echo        e($ultimoCampo);
            echo '      </div>';
        }

        echo '    </div>';
        echo '  </div>';
        echo '</section>';
}
} else {
    require_once __DIR__ . '/partials/form_contratos_busca.php';
}


// --- [PASSO 2-A] Injeta o ID da revisão no formulário HTML ---
if (isset($review_id) && $review_id > 0) {
    echo "<script>
    document.addEventListener('DOMContentLoaded', function(){
        // Procura o formulário principal
        var f = document.querySelector('form[data-form=\"emop-contrato\"]') || document.getElementById('coh-form');
        if(f) {
            // Cria um input hidden com o ID da revisão para ser enviado no POST
            var i = document.createElement('input'); 
            i.type='hidden'; i.name='review_id'; i.value='{$review_id}';
            f.appendChild(i);
            
            // Feedback visual simples
            var alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-warning fixed-bottom m-3 shadow';
            alertDiv.innerHTML = '<strong>Modo de Revisão:</strong> Você está corrigindo a solicitação #{$review_id}.';
            document.body.appendChild(alertDiv);
        }
    });
    </script>";
}
echo '</main>';

require_once __DIR__ . '/partials/modal_coord_inbox.php';
require_once __DIR__ . '/partials/footer.php';
?>

<script>
// [PASSO 2] Inicializa Objeto Global, CARREGA RASCUNHO e DESTACA ERROS
(function () {
  if (!window.COH) window.COH = {};
  
  // 1. Carrega listas complexas (Medições/Aditivos)
  var draftData = <?php echo json_encode($draft_lists ?? ['medicoes'=>[], 'aditivos'=>[], 'reajustes'=>[]]); ?>;
  window.COH.draft = draftData;

  // 2. Lógica de DESTAQUE (Highlight) dos campos alterados
  var changedFields = <?php echo json_encode($highlight_fields ?? []); ?>;

  document.addEventListener('DOMContentLoaded', function() {
      if(changedFields && changedFields.length > 0) {
          
          changedFields.forEach(function(fieldName) {
              // Tenta encontrar o input pelo nome (tenta seletores diferentes para garantir)
              var el = document.querySelector('[name="'+fieldName+'"]');
              
              if(el) {
                  // Aplica borda vermelha e fundo levemente avermelhado
                  el.style.border = '2px solid #dc3545'; 
                  el.style.backgroundColor = '#fff8f8';
                  el.setAttribute('title', 'DADO ANTERIOR (RECUSADO). Por favor, corrija.');
                  
                  // Adiciona etiqueta de erro no label
                  var label = document.querySelector('label[for="'+el.id+'"]');
                  if(label && !label.innerHTML.includes('(Revisar)')) {
                      label.innerHTML += ' <span class="text-danger small fw-bold">(Revisar)</span>';
                  }
              }
          });
          
          // Rola a tela até o primeiro erro encontrado
          // Usamos um loop para achar o primeiro elemento visível que conseguimos marcar
          for(var i=0; i<changedFields.length; i++){
              var first = document.querySelector('[name="'+changedFields[i]+'"]');
              if(first){
                  first.scrollIntoView({behavior: 'smooth', block: 'center'});
                  break;
              }
          }
      }
  });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var form = document.querySelector('form[data-form="emop-contrato"]') || document.getElementById('coh-form');
  if (!form) return;

  var hidden = form.querySelector('input[name="action"]');
  if (!hidden) { hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='action'; form.appendChild(hidden); }

  // 1. INPUTS HIDDEN AGORA SÃO CRIADOS DIRETO (SE NÃO EXISTIREM)
  var ids = ['novas_medicoes_json', 'novos_aditivos_json', 'novos_reajustes_json'];
  ids.forEach(function(id){
      if(!form.querySelector('input[name="'+id+'"]')){
          var inp = document.createElement('input');
          inp.type = 'hidden'; 
          inp.name = id; 
          inp.id = id;
          form.appendChild(inp);
      }
  });

  // 2. FUNÇÃO QUE FORÇA A ATUALIZAÇÃO DOS CAMPOS OCULTOS
  window.cohForceSync = function() {
      var D = (window.COH && window.COH.draft) ? window.COH.draft : {medicoes:[],aditivos:[],reajustes:[]};
      
      var iA = document.getElementById('novos_aditivos_json');
      var iM = document.getElementById('novas_medicoes_json');
      var iR = document.getElementById('novos_reajustes_json');

      if(iA) iA.value = JSON.stringify(D.aditivos || []);
      if(iM) iM.value = JSON.stringify(D.medicoes || []);
      if(iR) iR.value = JSON.stringify(D.reajustes || []);
  };

  var canEditImmediately = <?php echo json_encode($can_edit_immediately); ?>;
  var canRequestApproval = <?php echo json_encode($can_request_approval); ?>;
  var btnSalvar          = document.getElementById('btnSalvarContrato');

  // CONFIGURAÇÃO DOS BOTÕES
  if (btnSalvar) {
    var handler = function(e, actionType) {
        // Garante que o sync rode ANTES do POST
        if (e) e.preventDefault();

        window.cohForceSync();
        
        var iA = document.getElementById('novos_aditivos_json');
        var D = window.COH.draft || {aditivos:[]};
        if (D.aditivos.length > 0 && iA && (!iA.value || iA.value === '[]')) {
            iA.value = JSON.stringify(D.aditivos);
        }

        hidden.value = actionType;

        // submit explícito para evitar casos em que o botão não é type="submit"
        form.submit();
    };

    if (canEditImmediately) {
        btnSalvar.innerText = 'Salvar Alterações';
        btnSalvar.classList.remove('d-none');
        btnSalvar.addEventListener('click', function(e){ handler(e, 'salvar'); });
    }
    else if (canRequestApproval) {
        btnSalvar.innerText = 'Solicitar Aprovação';
        btnSalvar.classList.replace('btn-success','btn-primary');
        btnSalvar.classList.remove('d-none');
        btnSalvar.addEventListener('click', function(e){ handler(e, 'solicitar_aprovacao'); });
    }
    else { btnSalvar.remove(); }
    
    // Garantia extra: se o usuário submeter de outra forma (Enter, etc.)
    form.addEventListener('submit', function(){
        window.cohForceSync();
    });
  }

  // === Reposiciona o card "Última alteração" logo após a seção de medições ===
  var secMed      = document.getElementById('sec-med');
  var lastChange  = document.getElementById('contrato-last-change');

  if (secMed && lastChange) {
      secMed.insertAdjacentElement('afterend', lastChange);
      lastChange.classList.remove('d-none');
  }
});
</script>


<script>
// FUNÇÕES GLOBAIS DE RENDERIZAÇÃO (corrigidas)
(function () {

  function getDraft() {
    // garante window.COH e window.COH.draft sempre disponíveis
    if (!window.COH) window.COH = {};
    if (!window.COH.draft) {
      window.COH.draft = { medicoes: [], aditivos: [], reajustes: [] };
    }
    return window.COH.draft;
  }

  function renderMed() {
    if (!window.cohRenderDraft) return;
    var d = getDraft();
    window.cohRenderDraft('draft-list-medicoes', d.medicoes);
  }

  function renderAdi() {
    if (!window.cohRenderDraft) return;
    var d = getDraft();
    window.cohRenderDraft('draft-list-aditivos', d.aditivos);
  }

  function renderRea() {
    if (!window.cohRenderDraft) return;
    var d = getDraft();
    window.cohRenderDraft('draft-list-reajustes', d.reajustes);
  }

  // ==== ADD MEDIÇÃO NO RASCUNHO ====
  window.cohAddMedicao = function (p) {
    var d = getDraft();
    var obj = Object.assign(
      {
        _label: 'Medição ' + (p.data_medicao || ''),
        _desc: 'Valor: ' + (p.valor_rs || '')
      },
      p
    );
    d.medicoes.push(obj);
    renderMed();
    if (window.cohForceSync) window.cohForceSync();
  };

  // ==== ADD ADITIVO NO RASCUNHO ====
  window.cohAddAditivo = function (p) {
    var d = getDraft();
    var obj = Object.assign(
      {
        _label: 'Aditivo ' + (p.numero_aditivo || ''),
        _desc: 'Valor: ' + (p.valor_aditivo_total || '')
      },
      p
    );
    d.aditivos.push(obj);
    renderAdi();
    if (window.cohForceSync) window.cohForceSync();
  };

  // ==== ADD REAJUSTE NO RASCUNHO ====
  window.cohAddReajuste = function (p) {
    var d = getDraft();
    var obj = Object.assign(
      {
        _label: 'Reajuste ' + (p.data_base || p.indice || ''),
        _desc: 'Perc: ' + (p.percentual || '')
      },
      p
    );
    d.reajustes.push(obj);
    renderRea();
    if (window.cohForceSync) window.cohForceSync();
  };

})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function startTimers() {
        const timers = document.querySelectorAll('.timer-24h');
        setInterval(() => {
            timers.forEach(span => {
                let seconds = parseInt(span.getAttribute('data-seconds'), 10);
                if (seconds <= 0) {
                    span.innerHTML = "<span class='text-danger fw-bold'>Tempo esgotado</span>";
                    var btnGroup = span.closest('td').querySelector('.btn-group');
                    if(btnGroup) btnGroup.remove();
                    return;
                }
                seconds--; 
                span.setAttribute('data-seconds', seconds);
                const h = Math.floor(seconds / 3600);
                const m = Math.floor((seconds % 3600) / 60);
                const s = seconds % 60;
                span.textContent = `Restam: ${h<10?'0'+h:h}:${m<10?'0'+m:m}:${s<10?'0'+s:s}`;
            });
        }, 1000);
    }
    startTimers();
});

window.cohDeleteDbItem = function(tipo, id) {
    if (!confirm('ATENÇÃO: Você excluirá este registro do banco e os totais serão recalculados.\n\nContinuar?')) return;

    const fd = new FormData();
    fd.append('action', 'delete_item');
    fd.append('type',   tipo);
    fd.append('id',     id);

    fetch('ajax/delete_contract_item.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert('Excluído com sucesso!');
                location.reload();
            } else {
                alert('Erro: ' + (d.message || 'Desconhecido'));
            }
        })
        .catch(e => {
            alert('Erro de conexão.');
        });
};

window.cohEditDbItem = function(tipo, item) {
    let modalId = '';
    const fmt = v =>
        (typeof v === 'number')
            ? v.toLocaleString('pt-BR', { minimumFractionDigits: 2 })
            : (v || '');

    if (tipo === 'medicao') {
        modalId = 'modalMedicao';
        let root = document.getElementById(modalId);
        if (root) {
            let inpData  = root.querySelector('input[name="data_medicao"]');
            let inpValor = root.querySelector('input[name="valor_rs"]');
            let txtObs   = root.querySelector('textarea[name="observacao"]');

            if (inpData)  inpData.value  = item.data_medicao ? item.data_medicao.split(' ')[0] : '';
            if (inpValor) inpValor.value = fmt(item.valor_rs);
            if (txtObs)   txtObs.value   = item.observacao || '';

            if (inpValor) inpValor.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    else if (tipo === 'aditivo') {
        modalId = 'modalAditivo';
        let root = document.getElementById(modalId);
        if (root) {
            let inpNum   = root.querySelector('input[name="numero_aditivo"]');
            let inpData  = root.querySelector('input[name="data"]');
            let selTipo  = root.querySelector('select[name="tipo"]');
            let inpValAd = root.querySelector('input[name="valor_aditivo_total"]');
            let inpValTot= root.querySelector('input[name="valor_total_apos_aditivo"]');
            let inpPrazo = root.querySelector('input[name="novo_prazo"]');
            let txtObs   = root.querySelector('textarea[name="observacao"]');

            if (inpNum)   inpNum.value   = item.numero_aditivo || '';
            if (inpData)  inpData.value  = item.data || (item.created_at ? item.created_at.split(' ')[0] : '');
            if (selTipo) {
                selTipo.value = item.tipo || '';
                selTipo.dispatchEvent(new Event('change'));
            }
            if (inpValAd)  inpValAd.value  = fmt(item.valor_aditivo_total);
            if (inpValTot) inpValTot.value = fmt(item.valor_total_apos_aditivo);
            if (inpPrazo)  inpPrazo.value  = item.novo_prazo || '';
            if (txtObs)    txtObs.value    = item.observacao || '';

            if (inpValAd) inpValAd.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    else if (tipo === 'reajuste') {
        modalId = 'modalReajuste';
        let root = document.getElementById(modalId);
        if (root) {
            let inpData  = root.querySelector('input[name="data_base"]');
            let inpPerc  = root.querySelector('input[name="percentual"]');
            let inpValTot= root.querySelector('input[name="valor_total_apos_reajuste"]');
            let txtObs   = root.querySelector('textarea[name="observacao"]');

            if (inpData)  inpData.value  = item.data_base || '';
            if (inpPerc)  inpPerc.value  = item.percentual ||
                              (item.reajustes_percentual
                                  ? String(item.reajustes_percentual).replace('.', ',')
                                  : '');
            if (inpValTot) inpValTot.value = fmt(item.valor_total_apos_reajuste);
            if (txtObs)    txtObs.value    = item.observacao || '';

            if (inpValTot) inpValTot.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    if (modalId) {
        let m = bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId));
        m.show();
        setTimeout(() => {
            alert('MODO DE EDIÇÃO:\n\n1. Ajuste os dados.\n2. Salve como NOVO.\n3. Exclua o item antigo da lista.');
        }, 300);
    }
};
