<?php
// partials/form_emop_contratos_medicoes.php

if (!function_exists('e')) {
  function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}
function brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); }
function pct($n){ return number_format((float)$n, 2, ',', '.').'%' ; }

$__cid = (int)($id ?? ($row['id'] ?? 0));

// Valor total do contrato para cálculo de percentual
$__valor_total_contrato = (float)($row['Valor_Total_Do_Contrato_Novo'] ?? 0);
if ($__valor_total_contrato <= 0) {
  $__valor_total_contrato = (float)($row['Valor_Do_Contrato'] ?? 0);
}

// Nível de permissão (nível >=2 pode, mas coh_pode_alterar ainda filtra 24h / created_by)
$tem_permissao_geral = isset($user_level) ? ($user_level >= 2) : true;

require_once __DIR__ . '/../php/medicoes_lib.php';

// Busca medições já salvas + campo liquidado_anterior (da lib)
$__medicoes_salvas = [];
if ($__cid > 0) {
  $__medicoes_salvas = coh_fetch_medicoes_with_prev($conn, $__cid);
}

// Liquidado anterior mostrado no modal (último acumulado salvo)
if (!empty($__medicoes_salvas)) {
    $acumulado = 0.0;
    foreach ($__medicoes_salvas as $m) {
        $acumulado += (float)($m['valor_rs'] ?? 0);
    }
    $__liquidado_anterior = $acumulado;
} else {
    $__liquidado_anterior = (float)($row['Valor_Liquidado_Acumulado'] ?? $row['Medicao_Anterior_Acumulada_RS'] ?? 0);
}

// Campos hidden e Scripts de rascunho (compartilhados com Aditivos/Reajustes)
// Os inputs são criados aqui uma única vez.
if (!defined('COH_DRAFT_INPUTS')) {
  define('COH_DRAFT_INPUTS', true);
  echo '<input type="hidden" name="novas_medicoes_json"   id="novas_medicoes_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_aditivos_json"   id="novos_aditivos_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_reajustes_json"  id="novos_reajustes_json"  value="">' . PHP_EOL;
}

// (O JS base de COH.draft/cohRenderDraft veio do arquivo de Aditivos)
?>

<ul id="draft-list-medicoes" class="list-unstyled mb-3"></ul>
<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalMedicao">
  + Adicionar Medição
</button>

<div class="mt-4">
  <h6>Histórico de Medições Salvas</h6>
  <?php if (!empty($__medicoes_salvas)): ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>Data</th>
            <th>Liquidado anterior</th>
            <th>Valor (R$)</th>
            <th>Acumulado</th>
            <th>%</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php $running=0.0; ?>
          <?php foreach ($__medicoes_salvas as $m): ?>
            <?php
              $valor   = (float)($m['valor_rs'] ?? 0);
              $liq_ant = $running;
              $running += $valor;
              $acum    = $running;
              $pct_line = $__valor_total_contrato > 0 ? (($acum / $__valor_total_contrato) * 100.0) : null;

              // Regra 24h + created_by (helper vindo da lib)
              $pode_mexer = function_exists('coh_pode_alterar')
                  ? coh_pode_alterar($m['created_at'] ?? null, $tem_permissao_geral, $m['created_by'] ?? null)
                  : $tem_permissao_geral;

              // Cálculo do tempo restante (segundos)
              $segundos_restantes = 0;
              if ($pode_mexer && !empty($m['created_at'])) {
                  $criado_em = strtotime($m['created_at']);
                  $segundos_restantes = 86400 - (time() - $criado_em);
                  if ($segundos_restantes < 0) $segundos_restantes = 0;
              }
            ?>
            <tr>
              <td><?= !empty($m['data_medicao']) ? date('d/m/Y', strtotime($m['data_medicao'])) : '—' ?></td>
              <td><?= brl($liq_ant) ?></td>
              <td><?= brl($valor) ?></td>
              <td><?= brl($acum) ?></td>
              <td><?= $pct_line!==null ? pct($pct_line) : '—' ?></td>
              <td class="text-end" style="min-width: 140px;">
                <?php if ($pode_mexer): ?>
                  <div class="btn-group btn-group-sm mb-1">
                    <button type="button" class="btn btn-outline-secondary"
                            onclick='cohEditDbItem("medicao", <?= json_encode($m) ?>)' title="Editar">
                      <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-outline-danger"
                            onclick="cohDeleteDbItem('medicao', <?= (int)$m['id'] ?>)" title="Excluir">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                  <div class="text-danger small fw-bold timer-24h"
                       data-seconds="<?= $segundos_restantes ?>" style="font-size: 0.7rem;">
                    Calculando...
                  </div>
                <?php else: ?>
                  <span class="badge text-bg-success">Salvo</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="text-muted small">Nenhuma medição salva.</div>
  <?php endif; ?>
</div>

<div class="modal fade" id="modalMedicao" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Nova Medição</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Data</label>
            <input type="date" name="data_medicao" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Liquidado anterior (R$)</label>
            <input type="text" class="form-control" id="med_liq_ant_view"
                   value="<?= brl($__liquidado_anterior) ?>" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Valor da medição (R$)</label>
            <input type="text" name="valor_rs" class="form-control" id="med_valor_rs" placeholder="0,00">
          </div>
          <div class="col-md-12">
            <label class="form-label">Liquidado acumulado (R$)</label>
            <input type="text" name="acumulado_rs" class="form-control" id="med_acumulado_rs" readonly>
          </div>
          <div class="col-md-12">
            <label class="form-label">% Liquidado</label>
            <input type="text" name="percentual" class="form-control" id="med_percentual" readonly>
          </div>
          <div class="col-12">
            <label class="form-label">Observação</label>
            <textarea name="observacao" class="form-control" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="salvarMedicaoNoDraft()">Salvar no Rascunho</button>
      </div>
    </div>
  </div>
</div>

<script>
function salvarMedicaoNoDraft() {
    var root = document.getElementById('modalMedicao');
    if (!root) return;

    var p = {
        data_medicao: root.querySelector('input[name="data_medicao"]')?.value || '',
        valor_rs:      root.querySelector('input[name="valor_rs"]')?.value || '',
        acumulado_rs:  root.querySelector('input[name="acumulado_rs"]')?.value || '',
        percentual:    root.querySelector('input[name="percentual"]')?.value || '',
        observacao:    root.querySelector('textarea[name="observacao"]')?.value || ''
    };

    // Validação simples
    if (!p.data_medicao && !p.valor_rs && !p.observacao) {
        alert('Preencha ao menos data, valor ou observação.');
        return;
    }

    // 1) Adiciona ao draft visual
    if (window.cohAddMedicao) {
        window.cohAddMedicao(p);
    }

    // 2) Atualiza hidden "novas_medicoes_json" imediatamente
    try {
        var form = document.querySelector('form[data-form="emop-contrato"]') || document.getElementById('coh-form');
        if (form) {
            var inp = form.querySelector('input[name="novas_medicoes_json"]');
            if (!inp) {
                inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'novas_medicoes_json';
                inp.id   = 'novas_medicoes_json';
                form.appendChild(inp);
            }
            var arr = [];
            if (inp.value && inp.value.trim() !== '' && inp.value.trim() !== '[]') {
                try { arr = JSON.parse(inp.value); } catch(e){ arr = []; }
            }
            arr.push(p);
            inp.value = JSON.stringify(arr);
        }
    } catch(e){
        console.error('Erro ao atualizar novas_medicoes_json:', e);
    }

    // 3) Sincroniza global (extra)
    if (window.cohForceSync) window.cohForceSync();

    // 4) Limpa campos (mantém o "liquidado anterior" exibido)
    root.querySelectorAll('input[name="data_medicao"], input[name="valor_rs"], input[name="acumulado_rs"], input[name="percentual"], textarea[name="observacao"]').forEach(function(el){
        el.value = '';
    });

    // 5) Fecha modal
    var m = bootstrap.Modal.getInstance(root);
    if (m) m.hide();
}

(function(){
  const LIQ_ANT = <?= json_encode($__liquidado_anterior) ?>;
  const VLR_TOT = <?= json_encode($__valor_total_contrato) ?>;

  function brlToFloat(str){
    if(!str) return 0;
    str = String(str).trim();
    str = str.replace(/\./g,'').replace(',', '.');
    var n = parseFloat(str);
    return isNaN(n) ? 0 : n;
  }
  function fmtBRL(n){
    try {
      return n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
    } catch(e){
      var s = (Math.round(n*100)/100).toFixed(2);
      return s.replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
    }
  }
  function fmtPCT(n){
    var s = (Math.round(n*100)/100).toFixed(2);
    return s.replace('.',',');
  }

  function recalc(){
    var inpVal = document.getElementById('med_valor_rs');
    var inpAc  = document.getElementById('med_acumulado_rs');
    var inpPc  = document.getElementById('med_percentual');
    if(!inpVal || !inpAc || !inpPc) return;

    var v = brlToFloat(inpVal.value);
    var ac = (LIQ_ANT || 0) + v;
    var pc = VLR_TOT > 0 ? (ac / VLR_TOT) * 100 : 0;

    inpAc.value = fmtBRL(ac);
    inpPc.value = fmtPCT(pc);
  }

  document.addEventListener('input', function(ev){
    if (ev.target && ev.target.id === 'med_valor_rs') recalc();
  });

  var modal = document.getElementById('modalMedicao');
  if (modal) {
    modal.addEventListener('shown.bs.modal', function(){
      setTimeout(recalc, 130);
    });
  }
})();
</script>
