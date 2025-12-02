<?php
// php/fiscal_inbox.php — Inbox do Fiscal (com detalhes de alterações)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Sao_Paulo');

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/require_auth.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/conn.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===== Helpers de schema ===== */
function col_exists(mysqli $c, string $t, string $col): bool {
  $t   = $c->real_escape_string($t);
  $col = $c->real_escape_string($col);
  if (!$rs = $c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'")) return false;
  $ok = $rs->num_rows > 0;
  $rs->free();
  return $ok;
}
function table_exists(mysqli $c, string $t): bool {
  $like = $c->real_escape_string($t);
  if (!$rs = $c->query("SHOW TABLES LIKE '{$like}'")) return false;
  $ok = $rs->num_rows > 0;
  $rs->free();
  return $ok;
}

/* ===== Rótulos/formatadores/normalização ===== */
function column_label_map(){ return [
  'Objeto_Da_Obra'                => 'Objeto da Obra',
  'Fonte_De_Recursos'             => 'Fonte de Recursos',
  'Aditivo_N'                     => 'Aditivo Nº',
  'Processo_SEI'                  => 'Processo SEI',
  'Diretoria'                     => 'Diretoria',
  'Secretaria'                    => 'Secretaria',
  'Municipio'                     => 'Município',
  'Empresa'                       => 'Empresa',
  'Valor_Do_Contrato'             => 'Valor do Contrato',
  'Data_Inicio'                   => 'Data de Início',
  'Data_Fim_Prevista'             => 'Data de Fim Prevista',
  'Status'                        => 'Status',
  'Observacoes'                   => 'Observações',
  'Percentual_Executado'          => '% Executado',
  'Valor_Liquidado_Acumulado'     => 'Valor Liquidado (Acum.)',
  'Data_Da_Medicao_Atual'         => 'Data da Medição Atual',
  'Valor_Liquidado_Na_Medicao_RS' => 'Valor da Medição (R$)',
];}
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
function to_num($v){
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '') return null;
  $s = str_replace(['.',' '],'',$s);
  $s = str_replace(',','.',$s);
  return is_numeric($s) ? (float)$s : null;
}
function to_date($v){
  $s = trim((string)$v);
  if ($s==='') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)) return $s;
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$s,$m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  return null;
}
function eq_relaxed($a,$b){
  if ((string)$a === (string)$b) return true;
  $da = to_date($a); $db = to_date($b);
  if ($da && $db) return $da === $db;
  $na = to_num($a);  $nb = to_num($b);
  if ($na !== null && $nb !== null) return abs($na - $nb) < 1e-9;
  $sa = preg_replace('/\s+/',' ', trim((string)$a));
  $sb = preg_replace('/\s+/',' ', trim((string)$b));
  return $sa === $sb;
}

/**
 * Normaliza qualquer formato de "alteração" para:
 *  ['campo' => ..., 'antes' => ..., 'depois' => ..., 'label' => ...]
 */
function extract_change($raw){
  if (!is_array($raw)) {
    return ['campo'=>'—','antes'=>'—','depois'=>'—','label'=>'—'];
  }

  $campo  = $raw['campo']  ?? ($raw['field'] ?? ($raw['coluna'] ?? ($raw['nome'] ?? ($raw['chave'] ?? '—'))));
  $antes  = $raw['antes']  ?? ($raw['old']   ?? ($raw['antigo'] ?? ($raw['de'] ?? ($raw['from'] ?? null))));
  $depois = $raw['depois'] ?? ($raw['new']   ?? ($raw['novo']   ?? ($raw['para'] ?? ($raw['to'] ?? ($raw['valor'] ?? null)))));

  if (is_array($antes)  || is_object($antes))  $antes  = json_encode($antes,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if (is_array($depois) || is_object($depois)) $depois = json_encode($depois, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  $label = column_label($campo);
  if ($campo === '' || $campo === null) {
    $campo = '—';
    $label = '—';
  }

  return [
    'campo'  => $campo,
    'antes'  => $antes  ?? '—',
    'depois' => $depois ?? '—',
    'label'  => $label,
  ];
}

function emop_contratos_columns(mysqli $c){
  static $cols = null;
  if ($cols !== null) return $cols;
  $cols = [];
  if ($rs = $c->query("SHOW COLUMNS FROM emop_contratos")) {
    while ($r = $rs->fetch_assoc()) $cols[] = $r['Field'];
    $rs->free();
  }
  return $cols;
}

/**
 * Monta arrays de alterações a partir do payload_json
 * Retorna: [ $changes, $medicoes, $aditivos, $reajustes ]
 */
function parse_payload_changes(array $payload, array $cols = []): array {
  $changes  = [];
  $medicoes = [];
  $aditivos = [];
  $reajustes = [];

  if (!$payload) {
    return [$changes,$medicoes,$aditivos,$reajustes];
  }

  // 1) Arrays explícitos de alterações
  $keysChanges = ['changes','alteracoes','alterações'];
  foreach ($keysChanges as $kc) {
    if (isset($payload[$kc]) && is_array($payload[$kc])) {
      foreach ($payload[$kc] as $ch) {
        $changes[] = extract_change($ch);
      }
    }
  }

  // 2) Campo "campos" (tanto lista quanto array associativo)
  if (isset($payload['campos']) && is_array($payload['campos'])) {
    $campos = $payload['campos'];
    $isList = array_keys($campos) === range(0, count($campos)-1);
    if ($isList) {
      foreach ($campos as $ch) {
        $changes[] = extract_change($ch);
      }
    } else {
      foreach ($campos as $k=>$v) {
        if (is_array($v)) {
          $ch = $v;
          if (!isset($ch['campo'])) $ch['campo'] = $k;
          $changes[] = extract_change($ch);
        } else {
          $changes[] = extract_change(['campo'=>$k,'depois'=>$v]);
        }
      }
    }
  }

  // 3) Diff via before/after
  if (isset($payload['before'], $payload['after']) &&
      is_array($payload['before']) && is_array($payload['after'])) {

    $before = $payload['before'];
    $after  = $payload['after'];
    $keys   = array_unique(array_merge(array_keys($before), array_keys($after)));

    // restringe às colunas do contrato se tiver
    if ($cols) {
      $keys = array_values(array_unique(array_intersect($keys, $cols)));
    }

    foreach ($keys as $k) {
      $va = $before[$k] ?? null;
      $vb = $after[$k]  ?? null;
      if (eq_relaxed($va, $vb)) continue;
      $changes[] = extract_change([
        'campo'  => $k,
        'antes'  => $va,
        'depois' => $vb,
      ]);
    }
  }

  // 4) Alternativa "diff"
  if (!$changes && isset($payload['diff']) && is_array($payload['diff'])) {
    foreach ($payload['diff'] as $k=>$pair) {
      if (is_array($pair) &&
          (isset($pair['before']) || isset($pair['after']))) {
        $changes[] = extract_change([
          'campo'  => $k,
          'antes'  => $pair['before'] ?? null,
          'depois' => $pair['after']  ?? null,
        ]);
      }
    }
  }

  // 5) extras: medições / aditivos / reajustes (apenas listagem simples)
  if (isset($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])) {
    $medicoes = $payload['novas_medicoes'];
  } elseif (isset($payload['medicoes']) && is_array($payload['medicoes'])) {
    $medicoes = $payload['medicoes'];
  }

  if (isset($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])) {
    $aditivos = $payload['novos_aditivos'];
  } elseif (isset($payload['aditivos']) && is_array($payload['aditivos'])) {
    $aditivos = $payload['aditivos'];
  }

  if (isset($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])) {
    $reajustes = $payload['novos_reajustes'];
  } elseif (isset($payload['reajustes']) && is_array($payload['reajustes'])) {
    $reajustes = $payload['reajustes'];
  }

  return [$changes,$medicoes,$aditivos,$reajustes];
}

/* ===== Controle de acesso ===== */
$role      = (int)($_SESSION['role'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_cpf  = trim((string)($_SESSION['cpf'] ?? ''));
$user_name = trim((string)($_SESSION['nome'] ?? ($_SESSION['name'] ?? '')));

// Fiscal (1) e Dev (6)
if (!in_array($role, [1,6], true)) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Acesso negado']);
  exit;
}

/* ===== POST: remover da inbox do fiscal ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=UTF-8');
  $action = $_POST['action'] ?? '';

  if ($action === 'dismiss') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

    $res = $conn->query("SELECT * FROM coordenador_inbox WHERE id={$id} LIMIT 1");
    if (!$res || !$res->num_rows) { echo json_encode(['ok'=>false,'error'=>'Registro não encontrado']); exit; }
    $row = $res->fetch_assoc();
    $res->free();

    if (strtoupper((string)$row['status']) !== 'REJEITADO') {
      echo json_encode(['ok'=>false,'error'=>'Apenas itens REJEITADOS podem ser removidos']);
      exit;
    }

    if (table_exists($conn,'historico_alteracoes_contratos')) {
      $contrato_id = (int)($row['contrato_id'] ?? 0);
      $usuario_id  = (int)($row['usuario_id'] ?? $user_id);
      $dados_json  = json_encode($row, JSON_UNESCAPED_UNICODE);
      $stmt = $conn->prepare("
        INSERT INTO historico_alteracoes_contratos
          (contrato_id, usuario_id, acao, dados_json, criado_em)
        VALUES
          (?, ?, 'REMOVIDO_DA_INBOX', ?, NOW())
      ");
      if ($stmt) {
        $stmt->bind_param('iis', $contrato_id, $usuario_id, $dados_json);
        $stmt->execute();
        $stmt->close();
      }
    }

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
$table        = 'coordenador_inbox';
$status_where = "UPPER(a.status) IN ('REJEITADO','REVISAO_SOLICITADA')";

/* Filtro por fiscal */
$fiscal_parts = [];
if (col_exists($conn,$table,'fiscal_id')        && $user_id>0)
  $fiscal_parts[] = "a.fiscal_id={$user_id}";
if (col_exists($conn,$table,'requested_by_id')  && $user_id>0)
  $fiscal_parts[] = "a.requested_by_id={$user_id}";
if (col_exists($conn,$table,'requested_by_cpf') && $user_cpf!=='')
  $fiscal_parts[] = "a.requested_by_cpf='".$conn->real_escape_string($user_cpf)."'";
if (col_exists($conn,$table,'requested_by') && $user_name!=='') {
  $nm = $conn->real_escape_string($user_name);
  $fiscal_parts[] = "a.requested_by='{$nm}'";
  $fiscal_parts[] = "a.requested_by LIKE '%{$nm}%'";
}
$fiscal_where = $fiscal_parts ? '('.implode(' OR ', $fiscal_parts).')' : '';

/* Excluir itens já “removidos” */
$dismiss_ex = [];
if (col_exists($conn,$table,'dismissed_by_cpf') && $user_cpf!=='')
  $dismiss_ex[] = "COALESCE(a.dismissed_by_cpf,'') <> '".$conn->real_escape_string($user_cpf)."'";
if (col_exists($conn,$table,'dismissed_by_id') && $user_id>0)
  $dismiss_ex[] = "(a.dismissed_by_id IS NULL OR a.dismissed_by_id <> ".(int)$user_id.")";
if (col_exists($conn,$table,'dismissed_by'))
  $dismiss_ex[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.dismissed_by, '$.cpf')),'') <> '".$conn->real_escape_string($user_cpf)."'";
$dismiss_filter = $dismiss_ex ? (' AND '.implode(' AND ', $dismiss_ex)) : '';

/* ===== COUNT ===== */
if (($_GET['mode'] ?? null) === 'count') {
  header('Content-Type: application/json; charset=UTF-8');

  $sql = "SELECT COUNT(*) AS n
          FROM coordenador_inbox a
          WHERE {$status_where}" . ($fiscal_where ? " AND {$fiscal_where}" : "") . $dismiss_filter;

  $n = 0;
  if ($rs = $conn->query($sql)) {
    $row = $rs->fetch_assoc();
    $n   = (int)($row['n'] ?? 0);
    $rs->free();
  }

  // Fallback se o filtro por fiscal zerar a contagem
  if ($n === 0 && $fiscal_where !== '') {
    $sql2 = "SELECT COUNT(*) AS n
             FROM coordenador_inbox a
             WHERE {$status_where}{$dismiss_filter}";
    if ($rs2 = $conn->query($sql2)) {
      $row2 = $rs2->fetch_assoc();
      $n    = (int)($row2['n'] ?? 0);
      $rs2->free();
    }
  }

  echo json_encode(['count'=>$n], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== LISTAGEM (embed) ===== */
if ((int)($_GET['embed'] ?? 0) === 1) {
  header('Content-Type: text/html; charset=UTF-8');

  $sel  = "a.*, c.Objeto_Da_Obra AS objeto, c.Empresa AS empresa";
  if (col_exists($conn,$table,'requested_by')) $sel .= ", a.requested_by";
  if (col_exists($conn,$table,'processed_by')) $sel .= ", a.processed_by";

  $join = " LEFT JOIN emop_contratos c ON c.id = a.contrato_id ";

  if (table_exists($conn,'usuarios_cohidro_sigce') &&
      col_exists($conn,'usuarios_cohidro_sigce','id') &&
      col_exists($conn,'usuarios_cohidro_sigce','nome')) {
    $sel  .= ", uc.nome AS processed_by_name";
    if (col_exists($conn,'usuarios_cohidro_sigce','diretoria'))
      $sel .= ", uc.diretoria AS processed_by_dir";
    $join .= " LEFT JOIN usuarios_cohidro_sigce uc ON uc.id = a.processed_by ";
  }

  $sql = "SELECT {$sel}
          FROM coordenador_inbox a
          {$join}
          WHERE {$status_where}" . ($fiscal_where ? " AND {$fiscal_where}" : "") . $dismiss_filter . "
          ORDER BY a.processed_at DESC, a.id DESC
          LIMIT 300";

  $rows = [];
  if ($rs = $conn->query($sql)) {
    while ($r = $rs->fetch_assoc()) $rows[] = $r;
    $rs->free();
  }

  // Fallback sem filtro de fiscal
  if (!$rows && $fiscal_where !== '') {
    $sql = "SELECT {$sel}
            FROM coordenador_inbox a
            {$join}
            WHERE {$status_where}{$dismiss_filter}
            ORDER BY a.processed_at DESC, a.id DESC
            LIMIT 300";
    if ($rs = $conn->query($sql)) {
      while ($r = $rs->fetch_assoc()) $rows[] = $r;
      $rs->free();
    }
  }

  $colsContrato = emop_contratos_columns($conn);
  ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Contrato</th>
          <th>Status</th>
          <th>Coordenador</th>
          <th>Diretoria</th>
          <th>Motivo / Observação</th>
          <th>Solicitado em</th>
          <th>Processado em</th>
          <th>Alterações</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="9" class="text-muted text-center py-4">
            Nenhuma rejeição ou revisão pendente.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $r):
          $id          = (int)$r['id'];
          $contrato_id = (int)($r['contrato_id'] ?? 0);

          $status_raw   = strtoupper(trim((string)($r['status'] ?? '')));
          $status_label = ($status_raw === 'REVISAO_SOLICITADA')
                        ? 'REVISÃO SOLICITADA'
                        : $status_raw;

          // motivo / observação
          $motivo = (string)(
              $r['reason']
              ?? $r['review_notes']
              ?? $r['motivo_rejeicao']
              ?? $r['motivo']
              ?? ''
          );

          $dtc = $r['created_at']   ?? '';
          $dtp = $r['processed_at'] ?? '';

          $coord = !empty($r['processed_by_name'])
                  ? $r['processed_by_name']
                  : (!empty($r['requested_by']) ? $r['requested_by'] : '—');

          $coord_dir = isset($r['processed_by_dir']) ? (string)$r['processed_by_dir'] : '—';

          // payload_json
          $payload = [];
          $pl = '';
          if (isset($r['payload_json']))      $pl = trim((string)$r['payload_json']);
          elseif (isset($r['payload']))       $pl = trim((string)$r['payload']);

          if ($pl !== '') {
            $payload = json_decode($pl, true);
            if (!is_array($payload)) $payload = [];
          }

          [$changes, $medicoes, $aditivos, $reajustes] = parse_payload_changes($payload, $colsContrato);
          $changes_count = is_array($changes) ? count($changes) : 0;
          $badge = ($status_raw === 'REVISAO_SOLICITADA') ? 'warning' : 'danger';
        ?>
        <tr data-id="<?= $id ?>">
          <td>
            <?= $contrato_id
                  ? "<a href='/form_contratos.php?id={$contrato_id}'>Contrato {$contrato_id}</a>"
                  : '—' ?>
          </td>
          <td>
            <span class="badge text-bg-<?= $badge ?>"><?= h($status_label) ?></span>
          </td>
          <td><?= h($coord) ?></td>
          <td><?= h($coord_dir) ?></td>
          <td style="max-width:420px">
            <div class="small text-muted"><?= nl2br(h($motivo ?: '—')) ?></div>
          </td>
          <td class="text-nowrap"><?= h($dtc) ?></td>
          <td class="text-nowrap"><?= h($dtp) ?></td>
          <td>
            <button
              class="btn btn-outline-primary btn-sm js-ver-alteracoes"
              data-target="#fi-changes-<?= $id ?>">
              Detalhes<?= $changes_count ? " ({$changes_count})" : "" ?>
            </button>
          </td>
          <td class="text-end">
            <?php if ($status_raw === 'REJEITADO'): ?>
              <button
                class="btn btn-sm btn-outline-danger js-dismiss"
                data-id="<?= $id ?>">
                Remover
              </button>
            <?php elseif ($status_raw === 'REVISAO_SOLICITADA' && $contrato_id): ?>
              <a class="btn btn-sm btn-outline-success"
                 href="/form_contratos.php?id=<?= (int)$contrato_id ?>">
                Revisar
              </a>
            <?php endif; ?>
          </td>
        </tr>
        <tr id="fi-changes-<?= $id ?>" class="bg-light d-none">
          <td colspan="9">
            <?php if ($changes): ?>
              <div class="small mb-2">
                <strong>Campos alterados:</strong>
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-bordered mb-2">
                  <thead class="table-secondary">
                    <tr>
                      <th style="width:25%">Campo</th>
                      <th style="width:37%">Antes</th>
                      <th style="width:38%">Depois</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($changes as $ch): ?>
                    <tr>
                      <td><?= h($ch['label'] ?: $ch['campo']) ?></td>
                      <td><?= nl2br(h((string)$ch['antes'])) ?></td>
                      <td><?= nl2br(h((string)$ch['depois'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="small text-muted">
                Nenhuma alteração de campo registrada neste item.
              </div>
            <?php endif; ?>

            <?php if ($medicoes): ?>
              <hr class="my-2">
              <div class="small mb-1"><strong>Novas medições:</strong></div>
              <ul class="small mb-1">
                <?php foreach ($medicoes as $m):
                  $txt = [];
                  if (isset($m['data_medicao']) || isset($m['data']))
                    $txt[] = 'Data: '.h($m['data_medicao'] ?? $m['data']);
                  if (isset($m['valor_rs']) || isset($m['valor']))
                    $txt[] = 'Valor: '.h($m['valor_rs'] ?? $m['valor']);
                  if (isset($m['obs']))
                    $txt[] = 'Obs: '.h($m['obs']);
                ?>
                  <li><?= implode(' · ', $txt) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if ($aditivos): ?>
              <hr class="my-2">
              <div class="small mb-1"><strong>Novos aditivos:</strong></div>
              <ul class="small mb-1">
                <?php foreach ($aditivos as $a):
                  $txt = [];
                  if (isset($a['numero_aditivo']) || isset($a['numero']))
                    $txt[] = 'Nº '.h($a['numero_aditivo'] ?? $a['numero']);
                  if (isset($a['valor_aditivo_total']) || isset($a['valor']))
                    $txt[] = 'Valor: '.h($a['valor_aditivo_total'] ?? $a['valor']);
                  if (isset($a['data']))
                    $txt[] = 'Data: '.h($a['data']);
                ?>
                  <li><?= implode(' · ', $txt) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if ($reajustes): ?>
              <hr class="my-2">
              <div class="small mb-1"><strong>Novos reajustes:</strong></div>
              <ul class="small mb-1">
                <?php foreach ($reajustes as $rj):
                  $txt = [];
                  if (isset($rj['indice']))
                    $txt[] = 'Índice: '.h($rj['indice']);
                  if (isset($rj['reajustes_percentual']) || isset($rj['percentual']))
                    $txt[] = 'Percentual: '.h($rj['reajustes_percentual'] ?? $rj['percentual']);
                  if (isset($rj['data_base']) || isset($rj['data']))
                    $txt[] = 'Data-base: '.h($rj['data_base'] ?? $rj['data']);
                ?>
                  <li><?= implode(' · ', $txt) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <script>
  (function(){
    // Toggle detalhes
    document.addEventListener('click', function(ev){
      var btn = ev.target.closest('.js-ver-alteracoes');
      if (btn) {
        var target = btn.getAttribute('data-target');
        if (!target) return;
        var row = document.querySelector(target);
        if (!row) return;
        var isHidden = row.classList.contains('d-none');
        row.classList.toggle('d-none');
        btn.textContent = isHidden ? 'Ocultar' : 'Detalhes';
        return;
      }

      // Remover item (apenas REJEITADO)
      var btnDel = ev.target.closest('.js-dismiss');
      if (btnDel) {
        var id = btnDel.getAttribute('data-id');
        if (!id) return;
        if (!confirm('Remover este item da sua caixa?')) return;
        btnDel.disabled = true;
        fetch('/php/fiscal_inbox.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: 'action=dismiss&id=' + encodeURIComponent(id)
        }).then(function(r){ return r.json(); })
        .then(function(res){
          if (!res || !res.ok) {
            alert(res && res.error ? res.error : 'Erro ao remover.');
            btnDel.disabled = false;
            return;
          }
          var tr = btnDel.closest('tr');
          if (tr) {
            var det = document.querySelector('#fi-changes-' + id);
            if (det) det.remove();
            tr.remove();
          }
        }).catch(function(){
          alert('Falha na requisição.');
          btnDel.disabled = false;
        });
      }
    });
  })();
  </script>
  <?php
  exit;
}

/* ===== Fallback página completa ===== */
?>
<div class="container py-4">
  <h5>Inbox do Fiscal</h5>
  <div id="fiscalInboxBody"></div>
</div>
<script>
fetch('/php/fiscal_inbox.php?embed=1', {headers:{'X-Fragment':'1'}})
  .then(r => r.text())
  .then(html => { document.getElementById('fiscalInboxBody').innerHTML = html; });
</script>
