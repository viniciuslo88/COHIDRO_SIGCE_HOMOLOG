<?php
// php/solicitacao_aprov_sucesso.php
// Tela de confirmação da solicitação enviada ao coordenador.
// Exibe o resumo do que foi alterado.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conn.php';

// Helper de escape
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Mapa: nome do campo (name="" no form) -> rótulo amigável
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
$changes      = $changes      ?? [];   
$medicoes_fmt = $medicoes_fmt ?? [];   
$aditivos_fmt = $aditivos_fmt ?? [];   
$reajustes_fmt= $reajustes_fmt?? [];   

// Helpers de formatação
$fmt_date = function($iso){
    $iso = (string)$iso;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
        [$y,$m,$d] = explode('-', $iso);
        return sprintf('%02d/%02d/%04d', (int)$d, (int)$m, (int)$y);
    }
    return $iso; // Já está formatado ou vazio
};
$fmt_brl = function($n){
    if ($n === '' || $n === null) return '';
    // Limpa se já vier formatado errado, garante float
    $n = (float)str_replace(['.',','], ['','.' ], (string)$n);
    return 'R$ ' . number_format($n, 2, ',', '.');
};
$fmt_pct = function($p){
    if ($p === '' || $p === null) return '';
    $p = str_replace('.', ',', (string)$p);
    return $p . '%';
};

// 0) Se o chamador (form_contratos.php) passou payload em memória, usa direto
$payload = [];
if (!empty($APROV_PAYLOAD) && is_array($APROV_PAYLOAD)) {
    $payload = $APROV_PAYLOAD;
} elseif (!empty($_SESSION['APROV_PAYLOAD']) && is_array($_SESSION['APROV_PAYLOAD'])) {
    $payload = $_SESSION['APROV_PAYLOAD'];
}

// 1) Contexto do chamador via include (fallback)
if (isset($__success_ctx) && is_array($__success_ctx)) {
    if (!empty($__success_ctx['req_id'])) {
        $req_id = (int)$__success_ctx['req_id'];
    }
}
// 2) Via GET (se o usuário acessou via link direto)
if (!$req_id && isset($_GET['req_id'])) {
    $req_id = (int)$_GET['req_id'];
}

// Se payload foi encontrado, constroi as listas
if ($payload) {
    if (!$contrato_id && isset($payload['contrato_id'])) $contrato_id = (int)$payload['contrato_id'];
    $quando_str = $quando_str ?: date('d/m/Y H:i');

    // Campos principais
    if (!empty($payload['campos']) && is_array($payload['campos'])) {
        foreach ($payload['campos'] as $campo_form => $novo) {
            $changes[$campo_form] = [null, $novo];
        }
    }

    // Medições
    if (!empty($payload['novas_medicoes']) && is_array($payload['novas_medicoes'])) {
        foreach ($payload['novas_medicoes'] as $m) {
            $data = $m['data_medicao'] ?? $m['data'] ?? '';
            $medicoes_fmt[] = [
                'data_br'        => $fmt_date($data),
                'valor_brl'      => $fmt_brl($m['valor_rs']      ?? $m['valor'] ?? ''),
                'acumulado_brl'  => $fmt_brl($m['acumulado_rs']  ?? $m['acumulado'] ?? ''),
                'percentual_txt' => $fmt_pct($m['percentual']    ?? ''),
                'obs'            => (string)($m['observacao'] ?? $m['obs'] ?? '')
            ];
        }
    }

    // Aditivos (Com suporte aos novos campos)
    if (!empty($payload['novos_aditivos']) && is_array($payload['novos_aditivos'])) {
        foreach ($payload['novos_aditivos'] as $a) {
            $aditivos_fmt[] = [
                'numero'         => (string)($a['numero_aditivo'] ?? $a['numero'] ?? '—'),
                'data_br'        => $fmt_date($a['data'] ?? ''),
                'tipo'           => (string)($a['tipo'] ?? ''),
                'valor_brl'      => $fmt_brl($a['valor_aditivo_total'] ?? $a['valor'] ?? ''),
                'valor_total_brl'=> $fmt_brl($a['valor_total_apos_aditivo'] ?? $a['valor_total'] ?? ''),
                'novo_prazo'     => (string)($a['novo_prazo'] ?? $a['prazo'] ?? ''),
                'obs'            => (string)($a['observacao'] ?? '')
            ];
        }
    }

    // Reajustes
    if (!empty($payload['novos_reajustes']) && is_array($payload['novos_reajustes'])) {
        foreach ($payload['novos_reajustes'] as $rj) {
            $reajustes_fmt[] = [
                'indice'         => (string)($rj['indice'] ?? ''),
                'percentual_txt' => $fmt_pct($rj['percentual'] ?? ''),
                'data_base_br'   => $fmt_date($rj['data_base'] ?? ''),
                'valor_total_brl'=> $fmt_brl($rj['valor_total_apos_reajuste'] ?? $rj['valor_total'] ?? ''),
                'obs'            => (string)($rj['observacao'] ?? '')
            ];
        }
    }
}

// Se não tem payload na sessão/memória, tenta buscar do banco pelo ID da solicitação
if ((!$changes && !$medicoes_fmt && !$aditivos_fmt && !$reajustes_fmt) && $req_id > 0) {
    if ($st = $conn->prepare("SELECT payload_json, created_at FROM coordenador_inbox WHERE id=? LIMIT 1")) {
        $st->bind_param('i', $req_id);
        if ($st->execute()) {
            $rs = $st->get_result();
            if ($rs && ($r = $rs->fetch_assoc())) {
                $payload = json_decode((string)$r['payload_json'], true) ?: [];
                if (!$contrato_id && isset($payload['contrato_id'])) $contrato_id = (int)$payload['contrato_id'];
                $quando_str = $quando_str ?: ( !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : date('d/m/Y H:i') );

                // Repete lógica de extração (pode ser refatorado para função, mas aqui mantemos inline para simplicidade do arquivo único)
                if (!empty($payload['campos'])) {
                    foreach ($payload['campos'] as $k => $v) $changes[$k] = [null, $v];
                }
                if (!empty($payload['novas_medicoes'])) {
                    foreach ($payload['novas_medicoes'] as $m) {
                        $medicoes_fmt[] = [
                            'data_br' => $fmt_date($m['data_medicao']??$m['data']??''),
                            'valor_brl' => $fmt_brl($m['valor_rs']??''),
                            'acumulado_brl' => $fmt_brl($m['acumulado_rs']??''),
                            'percentual_txt' => $fmt_pct($m['percentual']??''),
                            'obs' => $m['observacao']??''
                        ];
                    }
                }
                if (!empty($payload['novos_aditivos'])) {
                    foreach ($payload['novos_aditivos'] as $a) {
                        $aditivos_fmt[] = [
                            'numero' => $a['numero_aditivo']??'—',
                            'data_br' => $fmt_date($a['data']??''),
                            'tipo' => $a['tipo']??'',
                            'valor_brl' => $fmt_brl($a['valor_aditivo_total']??''),
                            'valor_total_brl' => $fmt_brl($a['valor_total_apos_aditivo']??''),
                            'novo_prazo' => $a['novo_prazo']??'',
                            'obs' => $a['observacao']??''
                        ];
                    }
                }
                if (!empty($payload['novos_reajustes'])) {
                    foreach ($payload['novos_reajustes'] as $rj) {
                        $reajustes_fmt[] = [
                            'indice' => $rj['indice']??'',
                            'percentual_txt' => $fmt_pct($rj['percentual']??''),
                            'data_base_br' => $fmt_date($rj['data_base']??''),
                            'valor_total_brl' => $fmt_brl($rj['valor_total_apos_reajuste']??''),
                            'obs' => $rj['observacao']??''
                        ];
                    }
                }
            }
        }
        $st->close();
    }
}

// Valores padrão para exibição
$contrato_id = $contrato_id ?: ($_GET['contrato_id'] ?? '');
$quando_str  = $quando_str  ?: date('d/m/Y H:i');

// Carrega Partials de Layout se ainda não carregados
if (!defined('COH_PARTIALS_LOADED')) {
    define('COH_PARTIALS_LOADED', true);
    require_once __DIR__ . '/../partials/header.php';
    require_once __DIR__ . '/../partials/topbar.php';
    include __DIR__ . '/lgpd_guard.php'; // Se existir
}
?>
<style>
/* CSS Específico da Página de Sucesso */
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
          <span class="badge text-bg-success badge-pill"><i class="bi bi-check-lg"></i> Sucesso</span>
          <div>
            <div class="fs-5 fw-bold">Solicitação enviada ao Coordenador</div>
            <div class="small text-secondary">
              Contrato: <strong>#<?= e($contrato_id) ?></strong> • Enviado em: <?= e($quando_str) ?>
            </div>
          </div>
        </div>
        <p class="mb-0 text-muted small mt-2">
            Suas alterações foram registradas e aguardam aprovação. Você será notificado assim que o processo for concluído.
        </p>
      </div>
    </div>

    <?php if (!empty($changes)): ?>
    <div class="sux-card mb-4">
      <div class="sux-head">
        <span class="sux-dot"></span>
        <div class="sux-title">Dados Cadastrais Alterados</div>
      </div>
      <div class="sux-body">
          <div class="table-responsive">
            <table class="table table-sm table-clean align-middle mb-0">
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
                  <td class="fw-semibold text-primary"><?= e($rotulo) ?></td>
                  <td class="text-muted small"><?= ($antes === null || $antes === '') ? '—' : e($antes) ?></td>
                  <td class="fw-bold"><?= ($depois === null || $depois === '') ? '—' : e($depois) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($medicoes_fmt)): ?>
    <div class="sux-card mb-4">
      <div class="sux-head">
        <span class="sux-dot" style="background:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.15);"></span>
        <div class="sux-title">Novas Medições</div>
      </div>
      <div class="sux-body">
        <div class="table-responsive">
          <table class="table table-sm table-clean align-middle mb-0">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Data</th>
                <th>Valor</th>
                <th>Acumulado</th>
                <th>%</th>
                <th>Obs</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach ($medicoes_fmt as $m): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= e($m['data_br']) ?></td>
                  <td><?= e($m['valor_brl']) ?></td>
                  <td><?= e($m['acumulado_brl']) ?></td>
                  <td><?= e($m['percentual_txt']) ?></td>
                  <td class="small text-muted"><?= e($m['obs']) ?></td>
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
        <span class="sux-dot" style="background:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.15);"></span>
        <div class="sux-title">Novos Aditivos</div>
      </div>
      <div class="sux-body">
        <div class="table-responsive">
          <table class="table table-sm table-clean align-middle mb-0">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Nº / Tipo</th>
                <th>Data</th>
                <th>Valor Aditivo</th>
                <th>Valor Total</th>
                <th>Novo Prazo</th>
                <th>Obs</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach ($aditivos_fmt as $a): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td>
                      <strong><?= e($a['numero']) ?></strong><br>
                      <small class="text-secondary"><?= e($a['tipo']) ?></small>
                  </td>
                  <td><?= e($a['data_br']) ?></td>
                  <td class="text-primary fw-bold"><?= e($a['valor_brl']) ?></td>
                  <td><?= e($a['valor_total_brl']) ?></td>
                  <td><?= e($a['novo_prazo']) ?></td>
                  <td class="small text-muted" style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($a['obs']) ?>">
                      <?= e($a['obs']) ?>
                  </td>
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
        <span class="sux-dot" style="background:#8b5cf6; box-shadow:0 0 0 3px rgba(139,92,246,.15);"></span>
        <div class="sux-title">Novos Reajustes</div>
      </div>
      <div class="sux-body">
        <div class="table-responsive">
          <table class="table table-sm table-clean align-middle mb-0">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Índice</th>
                <th>Percentual</th>
                <th>Data Base</th>
                <th>Valor Total</th>
                <th>Obs</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; foreach ($reajustes_fmt as $rj): $i++; ?>
                <tr>
                  <td><?= $i ?></td>
                  <td><?= e($rj['indice']) ?></td>
                  <td><?= e($rj['percentual_txt']) ?></td>
                  <td><?= e($rj['data_base_br']) ?></td>
                  <td><?= e($rj['valor_total_brl']) ?></td>
                  <td class="small text-muted"><?= e($rj['obs']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if(empty($changes) && empty($medicoes_fmt) && empty($aditivos_fmt) && empty($reajustes_fmt)): ?>
        <div class="alert alert-warning text-center">
            Nenhuma alteração detalhada foi encontrada neste protocolo.
        </div>
    <?php endif; ?>

    <?php
      // Link de retorno ao contrato específico
      $back_contrato = ($contrato_id > 0) ? "/form_contratos.php?id={$contrato_id}" : '/form_contratos.php';
    ?>
    <div class="text-center mb-4 d-print-none">
      <a href="<?= e($back_contrato) ?>" class="btn btn-primary btn-soft px-4">
        <i class="bi bi-arrow-left"></i> Voltar para o Contrato
      </a>
      <a href="/form_contratos.php" class="btn btn-outline-secondary btn-soft ms-2">
        <i class="bi bi-search"></i> Voltar à Busca
      </a>
    </div>

  </div>

  <div class="mt-auto">
    <?php require_once __DIR__ . '/../partials/footer.php'; ?>
  </div>
</div>

<?php
// Limpa a sessão para que, ao atualizar a página, não reapareça a mensagem de sucesso repetida
if (isset($_SESSION['APROV_PAYLOAD'])) {
    unset($_SESSION['APROV_PAYLOAD']);
}
?>