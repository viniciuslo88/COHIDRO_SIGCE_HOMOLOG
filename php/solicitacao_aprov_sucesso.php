<?php
// php/solicitacao_aprov_sucesso.php
// Tela de confirmação da solicitação enviada ao coordenador.
// Pode ser chamada embutida (via require) ou diretamente (?req_id=).

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';

// Helper (evita redeclarar se vier do chamador)
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Mapa: nome do campo (name="" no form_emop_update) -> rótulo exibido no formulário
 * Ajuste/complete se adicionar novos campos no formulário.
 */
$FIELD_LABELS = [
    'Diretoria' => 'Diretoria',
    'Secretaria' => 'Órgão Demandante',
    'Estado' => 'Estado',
    'Municipio' => 'Município',
    'Bairro' => 'Bairro',
    'Regiao' => 'Mesorregião',
    'Responsavel_Fiscal' => 'Fiscal Responsável',
    'Fiscal_2' => 'Fiscal 2',
    'Setor_EMOP' => 'Setor EMOP',
    'Tipo' => 'Tipo de Contrato',
    'Objeto_Da_Obra' => 'Objeto da Obra',
    'Gestor_Obra' => 'Gestor da Obra',
    'Processo_SEI' => 'Processo (SEI)',
    'Status' => 'Status',
    'No_do_Contrato' => 'Nº do Contrato',
    'Assinatura_Do_Contrato_Data' => 'Assinatura do Contrato',
    'Ultima_Alteracao' => 'Última Alteração',
    'Empresa' => 'Empresa',
    'Data_Inicio' => 'Data de Início',
    'Prazo_Obra_Ou_Projeto' => 'Prazo (dias)',
    'Dias_Trabalhados' => 'Dias Trabalhados',
    'Data_Fim_Prevista' => 'Fim Previsto',
    'Valor_Do_Contrato' => 'R$ do Contrato',

    'Medicao_Anterior_Acumulada_RS' => 'Medição Anterior (R$)',
    'Data_Da_Medicao_Atual' => 'Data da Medição Atual',
    'Valor_Liquidado_Na_Medicao_RS' => 'Valor Liquidado na Medição (R$)',
    'Valor_Liquidado_Acumulado' => 'Liquidado Acumulado (R$)',
    'Percentual_Executado' => 'Percentual Executado (%)',

    'Saldo_Contratual_Sem_Reajustes_RS' => 'Saldo s/ Reajustes (R$)',
    'Saldo_Contratual_Com_Reajuste_RS' => 'Saldo c/ Reajustes (R$)',
    'Saldo_De_Empenho_RS' => 'Saldo de Empenho (R$)',

    'Numero_Do_Oficio_De_Recursos' => 'Ofício de Solicitação de Recursos (Nº)',
    'Fonte_De_Recursos' => 'Fonte de Recursos',

    'Inicio_Da_Suspensao' => 'Início da Suspensão',
    'Termino_Da_Suspensao' => 'Término da Suspensão',

    'Aditivo_N' => 'Aditivo Nº',
    'Novo_Prazo_Apos_Aditivo_Dias' => 'Novo Prazo (dias)',
    'Aditivos_RS' => 'Aditivos (R$)',
    'Contrato_Apos_Aditivo_Prazo_Do_Contrato_Aditivos_Dias' => 'Prazo Após Aditivo (dias)',
    'Contrato_Apos_Aditivo_Valor_Total_RS' => 'Valor Total Após Aditivo (R$)',

    'Reajuste_N' => 'Reajuste Nº',
    'Valor_Dos_Reajustes_RS' => 'Valor dos Reajustes (R$)',
    'Reajustes_Percentual' => 'Reajustes (%)',
    'Valor_Total_Do_Contrato_Novo' => 'Valor Total do Contrato (novo)',

    'Procedimento_Licitatorio' => 'Procedimento Licitatório',
];

// ---------- Entrada / Contexto ----------
$req_id       = null;
$contrato_id  = $contrato_id  ?? null;
$quando_str   = $quando_str   ?? null;
$changes      = $changes      ?? [];   // ['Campo_do_Form' => [before, after]]
$medicoes_fmt = $medicoes_fmt ?? [];   // [{data_br, valor_brl, acumulado_brl, percentual_txt, obs}]
$aditivos_fmt = $aditivos_fmt ?? [];   // [{numero, data_br, tipo, valor_brl, valor_total_brl, obs}]
$reajustes_fmt= $reajustes_fmt?? [];   // [{indice, percentual_txt, data_base_br, valor_total_brl, obs}]

// Helpers de formatação
$fmt_date = function($iso){
    $iso = (string)$iso;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
        [$y,$m,$d] = explode('-', $iso);
        return sprintf('%02d/%02d/%04d', (int)$d, (int)$m, (int)$y);
    }
    return $iso;
};
$fmt_brl = function($n){
    if ($n === '' || $n === null) return '';
    $n = (float)str_replace(['.',','], ['','.' ], (string)$n);
    return 'R$ ' . number_format($n, 2, ',', '.');
};
$fmt_pct = function($p){
    if ($p === '' || $p === null) return '';
    $p = str_replace('.', ',', (string)$p);
    return $p . '%';
};

// 0) Se o chamador passou payload em memória, usa direto
$payload = [];
if (!empty($APROV_PAYLOAD) && is_array($APROV_PAYLOAD)) {
    $payload = $APROV_PAYLOAD;
} elseif (!empty($_SESSION['APROV_PAYLOAD']) && is_array($_SESSION['APROV_PAYLOAD'])) {
    $payload = $_SESSION['APROV_PAYLOAD'];
}

// 1) Contexto do chamador via $__success_ctx
if (isset($__success_ctx) && is_array($__success_ctx)) {
    if (!empty($__success_ctx['req_id'])) {
        $req_id = (int)$__success_ctx['req_id'];
    }
}
// 2) GET
if (!$req_id && isset($_GET['req_id'])) {
    $req_id = (int)$_GET['req_id'];
}

// Se payload veio do chamador/SESSION, constroi as listas a partir dele
if ($payload) {
    if (!$contrato_id && isset($payload['contrato_id'])) $contrato_id = (int)$payload['contrato_id'];
    $quando_str = $quando_str ?: date('d/m/Y H:i');

    if (!empty($payload['campos']) && is_array($payload['campos'])) {
        foreach ($payload['campos'] as $campo_form => $novo) {
            $changes[$campo_form] = [null, $novo];
        }
    }
    if (!empty($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])) {
        foreach ($payload['novas_medicoes'] as $m) {
            $data = $m['data_medicao'] ?? $m['data'] ?? '';
            $medicoes_fmt[] = [
                'data_br'        => $fmt_date($data),
                'valor_brl'      => $fmt_brl($m['valor_rs']      ?? ''),
                'acumulado_brl'  => $fmt_brl($m['acumulado_rs']  ?? ''),
                'percentual_txt' => $fmt_pct($m['percentual']    ?? ''),
                'obs'            => (string)($m['observacao'] ?? $m['obs'] ?? '')
            ];
        }
    }
    if (!empty($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])) {
        foreach ($payload['novos_aditivos'] as $a) {
            $aditivos_fmt[] = [
                'numero'         => (string)($a['numero_aditivo'] ?? ''),
                'data_br'        => $fmt_date($a['data'] ?? ''),
                'tipo'           => (string)($a['tipo'] ?? ''),
                'valor_brl'      => $fmt_brl($a['valor_aditivo_total'] ?? ''),
                'valor_total_brl'=> $fmt_brl($a['valor_total_apos_aditivo'] ?? ''),
                'obs'            => (string)($a['observacao'] ?? '')
            ];
        }
    }
    if (!empty($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])) {
        foreach ($payload['novos_reajustes'] as $rj) {
            $reajustes_fmt[] = [
                'indice'         => (string)($rj['indice'] ?? ''),
                'percentual_txt' => $fmt_pct($rj['percentual'] ?? ''),
                'data_base_br'   => $fmt_date($rj['data_base'] ?? ''),
                'valor_total_brl'=> $fmt_brl($rj['valor_total_apos_reajuste'] ?? ''),
                'obs'            => (string)($rj['observacao'] ?? '')
            ];
        }
    }
}

// Se ainda não recebemos dados prontos do chamador, reconstruímos via req_id (compat)
if ((!$changes && !$medicoes_fmt && !$aditivos_fmt && !$reajustes_fmt) && $req_id > 0) {
    if ($st = $conn->prepare("SELECT payload_json, created_at FROM coordenador_inbox WHERE id=? LIMIT 1")) {
        $st->bind_param('i', $req_id);
        if ($st->execute()) {
            $rs = $st->get_result();
            if ($rs && ($r = $rs->fetch_assoc())) {
                $payload = json_decode((string)$r['payload_json'], true) ?: [];
                if (!$contrato_id && isset($payload['contrato_id'])) $contrato_id = (int)$payload['contrato_id'];
                $quando_str = $quando_str ?: ( !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : date('d/m/Y H:i') );

                if (!empty($payload['campos']) && is_array($payload['campos'])) {
                    foreach ($payload['campos'] as $campo_form => $novo) {
                        $changes[$campo_form] = [null, $novo];
                    }
                }
                if (!empty($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])) {
                    foreach ($payload['novas_medicoes'] as $m) {
                        $data = $m['data_medicao'] ?? $m['data'] ?? '';
                        $medicoes_fmt[] = [
                            'data_br'        => $fmt_date($data),
                            'valor_brl'      => $fmt_brl($m['valor_rs']      ?? ''),
                            'acumulado_brl'  => $fmt_brl($m['acumulado_rs']  ?? ''),
                            'percentual_txt' => $fmt_pct($m['percentual']    ?? ''),
                            'obs'            => (string)($m['observacao'] ?? $m['obs'] ?? '')
                        ];
                    }
                }
                if (!empty($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])) {
                    foreach ($payload['novos_aditivos'] as $a) {
                        $aditivos_fmt[] = [
                            'numero'         => (string)($a['numero_aditivo'] ?? ''),
                            'data_br'        => $fmt_date($a['data'] ?? ''),
                            'tipo'           => (string)($a['tipo'] ?? ''),
                            'valor_brl'      => $fmt_brl($a['valor_aditivo_total'] ?? ''),
                            'valor_total_brl'=> $fmt_brl($a['valor_total_apos_aditivo'] ?? ''),
                            'obs'            => (string)($a['observacao'] ?? '')
                        ];
                    }
                }
                if (!empty($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])) {
                    foreach ($payload['novos_reajustes'] as $rj) {
                        $reajustes_fmt[] = [
                            'indice'         => (string)($rj['indice'] ?? ''),
                            'percentual_txt' => $fmt_pct($rj['percentual'] ?? ''),
                            'data_base_br'   => $fmt_date($rj['data_base'] ?? ''),
                            'valor_total_brl'=> $fmt_brl($rj['valor_total_apos_reajuste'] ?? ''),
                            'obs'            => (string)($rj['observacao'] ?? '')
                        ];
                    }
                }
            }
        }
        $st->close();
    }
}

// Valores padrão
$contrato_id = $contrato_id ?: ($_GET['contrato_id'] ?? '');
$quando_str  = $quando_str  ?: date('d/m/Y H:i');

// ---------- Partials ----------
if (!defined('COH_PARTIALS_LOADED')) {
    define('COH_PARTIALS_LOADED', true);
    require_once __DIR__ . '/../partials/header.php';
    require_once __DIR__ . '/../partials/topbar.php';
    include __DIR__ . '/lgpd_guard.php';
}
?>
<style>
/* Visual clean, fundo branco */
.sux-wrap{ max-width: 1100px; margin: 12px auto 32px; }
.sux-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 6px 22px rgba(2,6,23,.06); }
.sux-head{ display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:1px dashed #e5e7eb; }
.sux-dot{ width:9px; height:9px; border-radius:999px; background:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
.sux-title{ font-weight:800; color:#0f172a; letter-spacing:.2px; }
.sux-body{ padding:16px; }
.badge-pill{ border-radius:999px; font-weight:700; padding:.45rem .85rem; }
.table-clean thead th{ background:#f8fafc; color:#0f172a; font-weight:700; }
.table-clean td, .table-clean th{ vertical-align:middle; }
.btn-soft{ border-radius:999px; padding:.6rem 1rem; font-weight:600; box-shadow:0 2px 10px rgba(2,6,23,.06); }
</style>

<div class="coh-page d-flex flex-column min-vh-100">
  <div class="container sux-wrap">

    <div class="sux-card mb-4">
      <div class="sux-body">
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="badge text-bg-success badge-pill">Sucesso</span>
          <div>
            <div class="fs-5 fw-bold">Solicitação enviada ao Coordenador.</div>
            <div class="small text-secondary">
              Protocolo: <?= $contrato_id ? 'contrato #'.e($contrato_id) : '—' ?> • <?= e($quando_str) ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Alterações de campos -->
    <div class="sux-card mb-4">
      <div class="sux-head">
        <span class="sux-dot"></span>
        <div class="sux-title">Alterações solicitadas</div>
      </div>
      <div class="sux-body">
        <?php if (!empty($changes)): ?>
          <div class="table-responsive">
            <table class="table table-sm table-clean align-middle">
              <thead>
                <tr>
                  <th>Campo</th>
                  <th style="width:30%">Antes</th>
                  <th style="width:30%">Depois</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($changes as $campo_form => $val):
                    $rotulo = isset($FIELD_LABELS[$campo_form]) ? $FIELD_LABELS[$campo_form] : $campo_form;
                    $antes  = $val[0];
                    $depois = $val[1];
              ?>
                <tr>
                  <td class="fw-semibold"><?= e($rotulo) ?></td>
                  <td><?= ($antes === null || $antes === '') ? '—' : e($antes) ?></td>
                  <td><?= ($depois === null || $depois === '') ? '—' : e($depois) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Nenhuma alteração de campo foi identificada.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($medicoes_fmt)): ?>
    <div class="sux-card mb-4">
      <div class="sux-head">
        <span class="sux-dot"></span>
        <div class="sux-title">Medições incluídas</div>
      </div>
      <div class="sux-body">
        <div class="table-responsive">
          <table class="table table-sm table-clean align-middle">
            <thead>
              <tr>
                <th style="width:60px">#</th>
                <th>Data</th>
                <th>Valor (R$)</th>
                <th>Acumulado (R$)</th>
                <th>%</th>
                <th>Obs</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach ($medicoes_fmt as $m): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= e($m['data_br'] ?? '') ?></td>
                  <td><?= e($m['valor_brl'] ?? '') ?></td>
                  <td><?= e($m['acumulado_brl'] ?? '') ?></td>
                  <td><?= e($m['percentual_txt'] ?? '') ?></td>
                  <td><?= e($m['obs'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($aditivos_fmt)): ?>
    <div class="sux-card mb-4">
      <div class="sux-head">
        <span class="sux-dot"></span>
        <div class="sux-title">Aditivos incluídos</div>
      </div>
      <div class="sux-body">
        <div class="table-responsive">
          <table class="table table-sm table-clean align-middle">
            <thead>
              <tr>
                <th style="width:60px">#</th>
                <th>Nº</th>
                <th>Data</th>
                <th>Tipo</th>
                <th>Valor do Aditivo</th>
                <th>Valor Total Após Aditivo</th>
                <th>Obs</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach ($aditivos_fmt as $a): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= e($a['numero'] ?? '') ?></td>
                  <td><?= e($a['data_br'] ?? '') ?></td>
                  <td><?= e($a['tipo'] ?? '') ?></td>
                  <td><?= e($a['valor_brl'] ?? '') ?></td>
                  <td><?= e($a['valor_total_brl'] ?? '') ?></td>
                  <td><?= e($a['obs'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($reajustes_fmt)): ?>
    <div class="sux-card mb-4">
      <div class="sux-head">
        <span class="sux-dot"></span>
        <div class="sux-title">Reajustes incluídos</div>
      </div>
      <div class="sux-body">
        <div class="table-responsive">
          <table class="table table-sm table-clean align-middle">
            <thead>
              <tr>
                <th style="width:60px">#</th>
                <th>Índice</th>
                <th>%</th>
                <th>Data-base</th>
                <th>Valor Total Após Reajuste</th>
                <th>Obs</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach ($reajustes_fmt as $rj): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= e($rj['indice'] ?? '') ?></td>
                  <td><?= e($rj['percentual_txt'] ?? '') ?></td>
                  <td><?= e($rj['data_base_br'] ?? '') ?></td>
                  <td><?= e($rj['valor_total_brl'] ?? '') ?></td>
                  <td><?= e($rj['obs'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php
      // Link de retorno: sempre para a página de BUSCA
      $back = '/form_contratos.php';
    ?>
    <div class="text-center mb-4">
      <a href="<?= e($back) ?>" class="btn btn-outline-primary btn-soft">
        <i class="bi bi-house-door"></i> Voltar à busca
      </a>
    </div>

  </div>

  <div class="mt-auto">
    <?php require_once __DIR__ . '/../partials/footer.php'; ?>
  </div>
</div>
