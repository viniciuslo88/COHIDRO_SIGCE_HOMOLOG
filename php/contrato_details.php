<?php
// php/contrato_details.php — conteúdo do modal de detalhes
// - Lê medições da tabela emop_medicoes (via medicoes_lib.php)
// - Lê aditivos (emop_aditivos) e reajustes (emop_reajustamento)
// - Recalcula liquidado_anterior, acumulado e % liquidado
// - Mantém estilos/cores idênticos ao form

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Fragment: 1');

set_error_handler(function($severity, $message, $file, $line){
  throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
  require_once __DIR__ . '/require_auth.php';
  require_once __DIR__ . '/session_guard.php';
  if (session_status() === PHP_SESSION_NONE) { session_start(); }
  date_default_timezone_set('America/Sao_Paulo');
  require_once __DIR__ . '/conn.php';
  require_once __DIR__ . '/diretoria_guard.php';
  require_once __DIR__ . '/medicoes_lib.php';
  require_once __DIR__ . '/aditivos_lib.php';
  require_once __DIR__ . '/reajustes_lib.php';

  if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  }

  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
  function v($arr, $key, $default='—'){ return (isset($arr[$key]) && $arr[$key] !== '') ? e($arr[$key]) : $default; }
  function dt($s){ if (!$s) return '—'; $t=strtotime($s); return $t?date('d/m/Y', $t):e($s); }
  function brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); }
  function pct($n){ return number_format((float)$n, 2, ',', '.').'%' ; }

  $role     = (int)($_SESSION['role'] ?? 0);
  $user_dir = $_SESSION['diretoria'] ?? null;
  $id       = isset($_GET['id']) ? (int)$_GET['id'] : 0;

  if ($id <= 0){
    echo "<div class='alert alert-danger m-2'>Parâmetro <code>id</code> inválido.</div>";
    exit;
  }

  // Checagem simples de escopo por diretoria (igual ao index)
  $can = true;
  if ($role === 1 && $user_dir){
    $sqlChk = "SELECT 1 FROM emop_contratos WHERE id=? AND UPPER(TRIM(`Diretoria`)) = UPPER(TRIM(?)) LIMIT 1";
    $stmt = $conn->prepare($sqlChk);
    $stmt->bind_param('is', $id, $user_dir);
    $stmt->execute(); $stmt->store_result();
    $can = $stmt->num_rows > 0;
    $stmt->close();
  }
  if (!$can){
    echo "<div class='alert alert-danger m-2'>Acesso negado para este contrato.</div>";
    exit;
  }

  // Carrega o contrato
  if ($role === 1 && $user_dir){
    $sql = "SELECT * FROM emop_contratos WHERE id = ? AND UPPER(TRIM(`Diretoria`)) = UPPER(TRIM(?))";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $id, $user_dir);
  } else {
    $sql = "SELECT * FROM emop_contratos WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $ctr = $res->fetch_assoc();
  $stmt->close();

  if (!$ctr){
    echo "<div class='alert alert-warning m-2'>Contrato não encontrado.</div>";
    exit;
  }

  // ==========================
  // MEDIÇÕES — leitura bruta
  // ==========================
  $medicoes_raw = coh_fetch_medicoes_with_prev($conn, $id); // já vem ordenadas por data/id

  // ==========================
  // ADITIVOS (emop_aditivos)
  // ==========================
  $aditivos = coh_fetch_aditivos_with_prev($conn, $id);
  $qtde_aditivos       = count($aditivos);
  $total_aditivos_acum = 0.0;
  if ($qtde_aditivos > 0) {
    $lastA               = end($aditivos);
    $total_aditivos_acum = (float)$lastA['valor_total_apos_aditivo'];
  }

  // ==========================
  // REAJUSTES (emop_reajustamento)
  // ==========================
  $reajustes = coh_fetch_reajustes_with_prev($conn, $id);
  $qtde_reajustes       = count($reajustes);
  $total_reajustes_acum = 0.0;
  if ($qtde_reajustes > 0) {
    $lastR                 = end($reajustes);
    $total_reajustes_acum  = (float)$lastR['valor_total_apos_reajuste'];
  }

  // ==========================
  // Valores de contrato
  // ==========================
  $vtc_novo  = (float)($ctr['Valor_Total_Do_Contrato_Novo'] ?? 0);
  $vtc_base  = (float)($ctr['Valor_Do_Contrato'] ?? 0);
  $valor_total_contrato = $vtc_novo > 0 ? $vtc_novo : $vtc_base;

  // Valor do contrato após aditivos (sem reajuste): base + total_aditivos_acum
  $valor_contrato_pos_aditivo   = $vtc_base + $total_aditivos_acum;
  // Valor do contrato após reajustes (teoricamente = Valor_Total_Do_Contrato_Novo)
  $valor_contrato_pos_reajuste  = $vtc_base + $total_aditivos_acum + $total_reajustes_acum;

  // ==========================
  // Recalcula medição: liq anterior, acumulado, %
  // ==========================
  $medicoes_calc = [];
  $prev_acum = 0.0; // começando do zero (igual à tela principal)

  foreach ($medicoes_raw as $m) {
    $valor = (float)($m['valor_rs'] ?? 0);
    $liq_ant = $prev_acum;
    $acum    = $prev_acum + $valor;
    $perc    = ($valor_total_contrato > 0)
                 ? ($acum / $valor_total_contrato) * 100.0
                 : null;

    $medicoes_calc[] = [
      'data_medicao'       => $m['data_medicao'] ?? null,
      'valor_rs'           => $valor,
      'liquidado_anterior' => $liq_ant,
      'acumulado'          => $acum,
      'percentual'         => $perc,
      'obs'                => $m['obs'] ?? '',
    ];

    $prev_acum = $acum;
  }

  // ==========================
  // Resumo (usa última medição calculada, se houver)
  // ==========================
  $resumo_valor_medicao   = (float)($ctr['Valor_Liquidado_Na_Medicao_RS'] ?? 0);
  $resumo_acumulado       = (float)($ctr['Valor_Liquidado_Acumulado'] ?? 0);
  $resumo_percentual_exec = (float)($ctr['Percentual_Executado'] ?? 0);

  if (!empty($medicoes_calc)) {
    $last = end($medicoes_calc);
    $resumo_valor_medicao = $last['valor_rs'];
    $resumo_acumulado     = $last['acumulado'];
    if ($last['percentual'] !== null) {
      $resumo_percentual_exec = $last['percentual'];
    } elseif ($valor_total_contrato > 0) {
      $resumo_percentual_exec = ($resumo_acumulado / $valor_total_contrato) * 100.0;
    }
  } elseif ($valor_total_contrato > 0 && $resumo_acumulado > 0 && $resumo_percentual_exec == 0) {
    // recalcula % caso só tenhamos acumulado no contrato
    $resumo_percentual_exec = ($resumo_acumulado / $valor_total_contrato) * 100.0;
  }

  // ==========================
  // Valor Total Atualizado do Contrato + Saldo Atualizado
  // (mesma lógica da form_emop_contratos_sections)
  // ==========================
  $valor_total_atualizado = 0.0;
  $saldo_atualizado       = 0.0;

  $v_inicial       = $vtc_base;
  $v_total_novo    = $vtc_novo;
  $v_apos_aditivo  = (float)($ctr['Contrato_Apos_Aditivo_Valor_Total_RS'] ?? 0);
  $v_aditivos      = (float)($ctr['Aditivos_RS'] ?? 0);
  $v_reajustes     = (float)($ctr['Valor_Dos_Reajustes_RS'] ?? 0);

  // 1) Se Valor_Total_Do_Contrato_Novo estiver preenchido, usar ele
  // 2) Senão, se existir valor após aditivo + reajustes, somar
  // 3) Senão, valor após aditivo sozinho
  // 4) Senão, valor inicial + aditivos + reajustes
  if ($v_total_novo > 0) {
    $valor_total_atualizado = $v_total_novo;
  } elseif ($v_apos_aditivo > 0 && $v_reajustes > 0) {
    $valor_total_atualizado = $v_apos_aditivo + $v_reajustes;
  } elseif ($v_apos_aditivo > 0) {
    $valor_total_atualizado = $v_apos_aditivo;
  } else {
    $valor_total_atualizado = $v_inicial + $v_aditivos + $v_reajustes;
  }

  // fallback: se por algum motivo ficou zerado, usa o valor_total_contrato já calculado
  if ($valor_total_atualizado <= 0) {
    $valor_total_atualizado = $valor_total_contrato;
  }

  // Saldo atualizado = valor total atualizado - liquidado acumulado
  $saldo_atualizado = $valor_total_atualizado - $resumo_acumulado;


  // ===== Estilos =====
  ?>
  <style>
    .coh-sec .card-header{
      color:#fff; font-weight:700;
      display:flex; align-items:center; justify-content:space-between;
      padding:.6rem .9rem;
      border-radius:12px 12px 0 0;
    }
    .coh-sec .card{ border:none; box-shadow:0 6px 20px rgba(2,6,23,.06); border-radius:12px; }
    .coh-sec .card-body{ border-top:none; border-radius:0 0 12px 12px; }

    .coh-sec--ficha .card-header{ background: linear-gradient(135deg, #010b25, #0a235a); }
    .coh-sec--licit .card-header{ background: linear-gradient(135deg, #102a6b, #1e3a8a); }
    .coh-sec--dados .card-header{ background: linear-gradient(135deg, #2563eb, #60a5fa); }
    .coh-sec--prazo .card-header{ background: linear-gradient(135deg, #93c5fd, #bfdbfe); }
    .coh-sec--carac .card-header{ background: linear-gradient(135deg, #064e3b, #0f766e); }
    .coh-sec--adit  .card-header{ background: linear-gradient(135deg, #15803d, #34d399); }
    .coh-sec--reaj  .card-header{ background: linear-gradient(135deg, #16a34a, #86efac); }
    .coh-sec--saldo .card-header{ background: linear-gradient(135deg, #4ade80, #bbf7d0); }
    .coh-sec--susp  .card-header{ background: linear-gradient(135deg, #374151, #6b7280); }
    .coh-sec--med   .card-header{ background: linear-gradient(135deg, #4b5563, #9ca3af); }

    .coh-sec--ficha .card-body{ background: linear-gradient(180deg, #e5ecff, #ffffff); border:1px solid #c7d2fe; }
    .coh-sec--licit .card-body{ background: linear-gradient(180deg, #e6ecff, #ffffff); border:1px solid #c7d2fe; }
    .coh-sec--dados .card-body{ background: linear-gradient(180deg, #eff6ff, #ffffff); border:1px solid #dbeafe; }
    .coh-sec--prazo .card-body{ background: linear-gradient(180deg, #f3f7ff, #ffffff); border:1px solid #dbeafe; }
    .coh-sec--carac .card-body{ background: linear-gradient(180deg, #e9fbf6, #ffffff); border:1px solid #c7f1e8; }
    .coh-sec--adit  .card-body{ background: linear-gradient(180deg, #ecfdf5, #ffffff); border:1px solid #bbf7d0; }
    .coh-sec--reaj  .card-body{ background: linear-gradient(180deg, #f0fff6, #ffffff); border:1px solid #c7f9df; }
    .coh-sec--saldo .card-body{ background: linear-gradient(180deg, #f6fff9, #ffffff); border:1px solid #dcfce7; }
    .coh-sec--susp  .card-body{ background: linear-gradient(180deg, #f3f4f6, #ffffff); border:1px solid #d1d5db; }
    .coh-sec--med   .card-body{ background: linear-gradient(180deg, #f5f6f7, #ffffff); border:1px solid #d6d9de; }

    .coh-sec { margin-bottom: 18px; }
    @media (min-width: 992px){ .coh-sec { margin-bottom: 22px; } }

    .coh-sec--saldo .card-header{
      background: linear-gradient(135deg, #4ade80, #bbf7d0);
      color: #fff;
    }
    
      .coh-saldo-pill{
    border-radius:999px;
    padding:.55rem .95rem;
    background:#ecfdf5;
    border:1px solid #22c55e;
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-size:.95rem;
    box-shadow:0 3px 8px rgba(16,185,129,.18);
  }
  .coh-saldo-pill strong{
    color:#065f46;
    font-weight:600;
  }
  .coh-saldo-pill span.amount{
    font-size:1.15rem;
    font-weight:700;
  }

  </style>
  <?php

  $badge = function($txt){ return "<span class='badge text-bg-secondary ms-1'>".e($txt)."</span>"; };
  ?>
  <div class="container-fluid px-2 py-2" style="max-width: 1080px;">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
      <div>
        <h5 class="mb-1 fw-bold" style="line-height:1.2">
          <?= v($ctr,'Objeto_Da_Obra','Contrato #'.$id) ?>
        </h5>
        <div class="small text-muted">
          <?= v($ctr,'Diretoria') ?> • <?= v($ctr,'Secretaria') ?> <?= $badge('ID '.$id) ?>
        </div>
      </div>
    </div>

    <div class="row g-2">

        <!-- 1) DADOS GERAIS -->
        <div class="col-12 coh-sec coh-sec--ficha">
          <div class="card">
            <div class="card-header">Dados Gerais</div>
            <div class="card-body py-2">
              <!-- Linha 1: ID / Diretoria / Status / Tipo -->
              <div class="row small mb-1">
                <div class="col-md-2">
                  <strong>ID:</strong> <?= (int)($ctr['id'] ?? $id) ?>
                </div>
                <div class="col-md-4">
                  <strong>Diretoria:</strong> <?= v($ctr,'Diretoria') ?>
                </div>
                <div class="col-md-3">
                  <strong>Status:</strong> <?= v($ctr,'Status') ?>
                </div>
                <div class="col-md-3">
                  <strong>Tipo:</strong> <?= v($ctr,'Tipo') ?>
                </div>
              </div>
        
              <!-- Linha 2: Nº Contrato / Processo SEI / Órgão Demandante -->
              <div class="row small mb-1">
                <div class="col-md-3">
                  <strong>Nº do Contrato:</strong>
                  <?= v($ctr, 'Numero_Contrato', v($ctr, 'No_do_Contrato')) ?>
                </div>
                <div class="col-md-4">
                  <strong>Processo (SEI):</strong> <?= v($ctr,'Processo_SEI') ?>
                </div>
                <div class="col-md-5">
                  <strong>Órgão Demandante:</strong> <?= v($ctr,'Orgao_Demandante') ?>
                </div>
              </div>
        
              <!-- Linha 3: Valor / Assinatura -->
              <div class="row small mb-1">
                <div class="col-md-4">
                  <strong>Valor do Contrato (R$):</strong> <?= brl($valor_total_contrato) ?>
                </div>
                <div class="col-md-4">
                  <strong>Assinatura do Contrato:</strong>
                  <?= dt($ctr['Assinatura_Do_Contrato_Data'] ?? $ctr['Data_Assinatura'] ?? '') ?>
                </div>
              </div>
        
              <!-- Linha 4: Fonte de Recursos (largura cheia) -->
              <div class="row small">
                <div class="col-12">
                  <strong>Fonte de Recursos:</strong> <?= v($ctr,'Fonte_De_Recursos') ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 2) PROC. LICITATÓRIO -->
        <div class="col-12 coh-sec coh-sec--licit">
          <div class="card">
            <div class="card-header">Procedimento Licitatório</div>
            <div class="card-body py-2 small">
              <div class="row">
                <div class="col-12">
                  <strong>Procedimento Licitatório:</strong>
                  <?= v($ctr,'Procedimento_Licitatorio', v($ctr,'Modalidade')) ?>
                </div>
              </div>
            </div>
          </div>
        </div>

      <!-- 3) DADOS DA OBRA -->
      <div class="col-12 coh-sec coh-sec--dados">
        <div class="card">
          <div class="card-header">Dados da Obra</div>
          <div class="card-body py-2 small">
            <div class="row">
              <div class="col-md-3"><strong>Valor do Contrato:</strong> <?= brl($valor_total_contrato) ?></div>
              <div class="col-md-3"><strong>Valor Liquidado na Medição:</strong> <?= brl($resumo_valor_medicao) ?></div>
              <div class="col-md-3"><strong>Liquidado Acumulado:</strong> <?= brl($resumo_acumulado) ?></div>
              <div class="col-md-3"><strong>Percentual Executado:</strong> <?= pct($resumo_percentual_exec) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 4) PRAZO DA OBRA -->
      <div class="col-12 coh-sec coh-sec--prazo">
        <div class="card">
          <div class="card-header">Prazo da Obra</div>
          <div class="card-body py-2 small">
            <div class="row">
              <div class="col-md-3"><strong>Ordem de Início:</strong> <?= dt($ctr['Data_Inicio'] ?? $ctr['Data_Ordem_De_Inicio'] ?? '') ?></div>
              <div class="col-md-3"><strong>Prazo (dias):</strong> <?= v($ctr,'Prazo_Obra_Ou_Projeto') ?></div>
              <div class="col-md-3"><strong>Término Previsto:</strong> <?= dt($ctr['Data_Fim_Prevista'] ?? $ctr['Data_Termino_Previsto'] ?? '') ?></div>
              <div class="col-md-3"><strong>Status:</strong> <?= v($ctr,'Status') ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 5) CARACTERÍSTICAS -->
      <div class="col-12 coh-sec coh-sec--carac">
        <div class="card">
          <div class="card-header">Características do Contrato</div>
          <div class="card-body py-2 small">
            <div class="row">
              <div class="col-md-3"><strong>Fonte:</strong> <?= v($ctr,'Fonte_De_Recursos') ?></div>
              <div class="col-md-3"><strong>Programa:</strong> <?= v($ctr,'Programa') ?></div>
              <div class="col-md-6"><strong>Observações:</strong> <?= v($ctr,'Observacoes') ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 6) ADITIVOS -->
      <div class="col-12 coh-sec coh-sec--adit">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span>Aditivos</span>
            <small class="text-white-50"><?= $qtde_aditivos ?> registro(s)</small>
          </div>
          <div class="card-body py-2 small">
            <div class="row mb-2">
              <div class="col-md-4"><strong>Qtde Aditivos:</strong> <?= $qtde_aditivos ?></div>
              <div class="col-md-4"><strong>Valor Aditivo Total (acumulado):</strong> <?= brl($total_aditivos_acum) ?></div>
              <div class="col-md-4"><strong>Valor Contrato após Aditivos:</strong> <?= brl($valor_contrato_pos_aditivo) ?></div>
            </div>

            <?php if (!empty($aditivos)): ?>
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Data</th>
                      <th>Aditivo anterior</th>
                      <th>Valor do aditivo</th>
                      <th>Aditivo acumulado</th>
                      <th>Novo prazo (dias)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($aditivos as $a): ?>
                      <tr>
                        <td><?= $a['created_at'] ? e(date('d/m/Y', strtotime($a['created_at']))) : '—' ?></td>
                        <td><?= brl($a['aditivo_anterior'] ?? 0) ?></td>
                        <td><?= brl($a['valor_aditivo_total'] ?? 0) ?></td>
                        <td><?= brl($a['valor_total_apos_aditivo'] ?? 0) ?></td>
                        <td><?= $a['novo_prazo'] !== null ? e($a['novo_prazo']) : '—' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-muted small">Nenhum aditivo cadastrado.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- 7) REAJUSTES -->
      <div class="col-12 coh-sec coh-sec--reaj">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span>Reajustes</span>
            <small class="text-white-50"><?= $qtde_reajustes ?> registro(s)</small>
          </div>
          <div class="card-body py-2 small">
            <div class="row mb-2">
              <div class="col-md-4"><strong>Qtde Reajustes:</strong> <?= $qtde_reajustes ?></div>
              <div class="col-md-4"><strong>Reajustes acumulados (R$):</strong> <?= brl($total_reajustes_acum) ?></div>
              <div class="col-md-4"><strong>Valor Contrato após Reajustes:</strong> <?= brl($valor_contrato_pos_reajuste) ?></div>
            </div>

            <?php if (!empty($reajustes)): ?>
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Data</th>
                      <th>Reajuste anterior</th>
                      <th>Valor do reajuste</th>
                      <th>Reajuste acumulado</th>
                      <th>% deste reajuste</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($reajustes as $r): ?>
                      <tr>
                        <td><?= $r['created_at'] ? e(date('d/m/Y', strtotime($r['created_at']))) : '—' ?></td>
                        <td><?= brl($r['reajuste_anterior'] ?? 0) ?></td>
                        <td><?= brl($r['valor_reajuste'] ?? 0) ?></td>
                        <td><?= brl($r['valor_total_apos_reajuste'] ?? 0) ?></td>
                        <td><?= $r['reajustes_percentual'] !== null ? pct($r['reajustes_percentual']) : '—' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-muted small">Nenhum reajuste cadastrado.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- 8) SALDO CONTRATUAL -->
      <div class="col-12 coh-sec coh-sec--saldo">
        <div class="card">
          <div class="card-header">Saldo Contratual</div>
          <div class="card-body py-2 small">
            <!-- Linha 1: mesmos campos já existentes -->
            <div class="row mb-2">
              <div class="col-md-3">
                <strong>Liquidado Acumulado Anterior:</strong>
                <?php
                  if (!empty($medicoes_calc)) {
                    $last = end($medicoes_calc);
                    echo brl($last['liquidado_anterior'] ?? 0);
                  } else {
                    echo brl($ctr['Medicao_Anterior_Acumulada_RS'] ?? 0);
                  }
                ?>
              </div>
              <div class="col-md-3">
                <strong>Valor Liquidado na Medição:</strong>
                <?= brl($resumo_valor_medicao) ?>
              </div>
              <div class="col-md-3">
                <strong>Liquidado Acumulado:</strong>
                <?= brl($resumo_acumulado) ?>
              </div>
              <div class="col-md-3">
                <strong>Percentual Executado:</strong>
                <?= pct($resumo_percentual_exec) ?>
              </div>
            </div>

            <!-- Linha 2: novos campos espelhando o formulário, em destaque -->
            <div class="row mt-3">
              <div class="col-md-6 mb-2">
                <div class="coh-saldo-pill">
                  <strong>Valor Total Atualizado do Contrato:</strong>
                  <span class="amount"><?= brl($valor_total_atualizado) ?></span>
                </div>
              </div>
              <div class="col-md-6 mb-2">
                <div class="coh-saldo-pill">
                  <strong>Saldo Atualizado do Contrato:</strong>
                  <span class="amount"><?= brl($saldo_atualizado) ?></span>
                </div>
              </div>
            </div>
        </div>
      </div>

      <!-- 9) PERÍODO DE SUSPENSÃO -->
      <div class="col-12 coh-sec coh-sec--susp">
        <div class="card">
          <div class="card-header">Período de Suspensão</div>
          <div class="card-body py-2 small">
            <div class="row">
              <div class="col-md-4"><strong>Início:</strong> <?= dt($ctr['Inicio_Da_Suspensao'] ?? $ctr['Suspensao_Inicio'] ?? '') ?></div>
              <div class="col-md-4"><strong>Fim:</strong> <?= dt($ctr['Termino_Da_Suspensao'] ?? $ctr['Suspensao_Fim'] ?? '') ?></div>
              <div class="col-md-4"><strong>Motivo:</strong> <?= v($ctr,'Suspensao_Motivo') ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 10) MEDIÇÕES -->
      <div class="col-12 coh-sec coh-sec--med">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span>Medições</span>
            <small class="text-white-50"><?= count($medicoes_calc) ?> registro(s)</small>
          </div>
          <div class="card-body p-0">
            <?php if (empty($medicoes_calc)): ?>
              <div class="p-2 text-muted small">Nenhuma medição registrada neste contrato.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                  <thead class="table-light">
                    <tr>
                      <th style="white-space:nowrap;">Data</th>
                      <th style="white-space:nowrap;">Liquidado anterior</th>
                      <th style="white-space:nowrap;">Valor da medição (R$)</th>
                      <th style="white-space:nowrap;">Liquidado acumulado</th>
                      <th style="white-space:nowrap;">% Liquidado</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($medicoes_calc as $m): ?>
                      <tr title="<?= e(trim(($m['obs'] ?? '') !== '' ? 'Obs: '.$m['obs'] : '')) ?>">
                        <td><?= dt($m['data_medicao'] ?? '') ?></td>
                        <td><?= brl($m['liquidado_anterior'] ?? 0) ?></td>
                        <td><?= brl($m['valor_rs'] ?? 0) ?></td>
                        <td><?= brl($m['acumulado'] ?? 0) ?></td>
                        <td><?= $m['percentual'] !== null ? pct($m['percentual']) : '—' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- BLOCO FINAL: ÚLTIMA ALTERAÇÃO -->
      <div class="text-start mt-3 mb-2" style="font-size:0.9rem; color:var(--bs-secondary-color); border-top:1px solid #ddd; padding-top:0.75rem;">
        <strong>Última alteração:</strong>
        <?= e($ctr['Ultima_Alteracao'] ?? 'Sem alterações registradas') ?>
      </div>

<?php

} catch (Throwable $e) {
  http_response_code(200);
  $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  $file = htmlspecialchars($e->getFile(),    ENT_QUOTES, 'UTF-8');
  $line = (int)$e->getLine();
  echo "<div class='alert alert-danger m-3'>
          Não foi possível gerar o conteúdo.<br>
          <small><code>$msg</code><br><code>$file:$line</code></small>
        </div>";
}
