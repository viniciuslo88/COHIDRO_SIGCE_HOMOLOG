<?php
// partials/form_emop_contratos_reajustes.php

if (!function_exists('e')) {
    function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('coh_brl')) {
    function coh_brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); }
}
if (!function_exists('coh_pct')) {
    function coh_pct($n){ return number_format((float)$n, 2, ',', '.').'%'; }
}

$__cid = (int)($id ?? ($row['id'] ?? 0));

// nível de permissão geral (≥ 2 já podia salvar/editar antes)
$tem_permissao_geral = isset($user_level) ? ($user_level >= 2) : true;

// === HIDDENS (rascunho) ===
if (!defined('COH_DRAFT_INPUTS')) {
  define('COH_DRAFT_INPUTS', true);
  echo '<input type="hidden" name="novas_medicoes_json" id="novas_medicoes_json" value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_aditivos_json" id="novos_aditivos_json" value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_reajustes_json" id="novos_reajustes_json" value="">' . PHP_EOL;
}

// === JS RASCUNHO (compartilhado com as 3 seções) ===
if (!defined('COH_DRAFT_JS')) {
  define('COH_DRAFT_JS', true);
  ?>
  <script>
  window.COH = window.COH || {};
  COH.draft = COH.draft || { medicoes: [], aditivos: [], reajustes: [] };

  function cohSetHiddenDraft(){
    var m=document.getElementById('novas_medicoes_json'),
        a=document.getElementById('novos_aditivos_json'),
        r=document.getElementById('novos_reajustes_json');
    if(m) m.value = JSON.stringify(COH.draft.medicoes || []);
    if(a) a.value = JSON.stringify(COH.draft.aditivos || []);
    if(r) r.value = JSON.stringify(COH.draft.reajustes || []);
  }

  function cohRenderDraft(listId, arr){
    var ul=document.getElementById(listId); if(!ul) return;
    ul.innerHTML='';
    (arr || []).forEach(function(item, idx){
      var li=document.createElement('li');
      li.className='d-flex align-items-start justify-content-between border rounded px-2 py-1 mb-1';
      li.innerHTML =
        '<div>' +
          '<strong>'+(item._label||'Item')+'</strong>' +
          '<div class="small text-secondary">'+(item._desc||'')+'</div>' +
        '</div>' +
        '<button type="button" class="btn btn-sm btn-outline-danger ms-2" data-remove="'+idx+'">Excluir</button>';
      li.querySelector('button[data-remove]').addEventListener('click', function(ev){
        ev.preventDefault(); ev.stopPropagation();
        arr.splice(idx,1);
        cohSetHiddenDraft();
        cohRenderDraft(listId, arr);
      });
      ul.appendChild(li);
    });
  }

  window.cohAddMedicao = function(p){
    var l='Medição '+(p.data_medicao||'');
    var d='Valor: '+(p.valor_rs||'');
    COH.draft.medicoes.push(Object.assign({_label:l,_desc:d}, p));
    cohSetHiddenDraft();
    cohRenderDraft('draft-list-medicoes', COH.draft.medicoes);
  };

  window.cohAddAditivo = function(p){
    var l='Aditivo '+(p.numero_aditivo||'');
    var d='Valor: '+(p.valor_aditivo_total||'');
    COH.draft.aditivos.push(Object.assign({_label:l,_desc:d}, p));
    cohSetHiddenDraft();
    cohRenderDraft('draft-list-aditivos', COH.draft.aditivos);
  };

  window.cohAddReajuste = function(p){
    var l='Reajuste '+(p.data_base||'');
    var d='Perc: '+(p.percentual||'');
    COH.draft.reajustes.push(Object.assign({_label:l,_desc:d}, p));
    cohSetHiddenDraft();
    cohRenderDraft('draft-list-reajustes', COH.draft.reajustes);
  };
  </script>
  <?php
}

// === VALOR BASE PARA CÁLCULO ===
if (!function_exists('coh_parse_db_value')) {
    function coh_parse_db_value($v) {
        if (is_null($v) || $v === '') return 0.0;
        if (is_numeric($v)) return (float)$v;
        $v = (string)$v;
        if (strpos($v, ',') !== false) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        return (float)$v;
    }
}

$valor_base_calculo = 0.0;
$origem_base = 'Valor Original';

if (isset($row['Valor_Do_Contrato'])) {
    $valor_base_calculo = coh_parse_db_value($row['Valor_Do_Contrato']);
}
if (isset($row['Contrato_Apos_Aditivo_Valor_Total_RS'])) {
    $v_adit = coh_parse_db_value($row['Contrato_Apos_Aditivo_Valor_Total_RS']);
    if ($v_adit > 0.01) {
        $valor_base_calculo = $v_adit;
        $origem_base = 'Valor com Aditivos';
    }
}

// === BUSCA REAJUSTES (já com created_by na lib nova) ===
require_once __DIR__ . '/../php/reajustes_lib.php';
$__reajustes_salvos = [];
if ($__cid > 0) {
  $__reajustes_salvos = coh_fetch_reajustes_with_prev($conn, $__cid);
}
?>

<ul id="draft-list-reajustes" class="list-unstyled mb-3"></ul>
<button type="button" class="btn btn-outline-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalReajuste">
  + Adicionar Reajuste
</button>

<?php if (!empty($__reajustes_salvos)): ?>
  <div class="table-responsive mb-2">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Data Base</th>
          <th>Percentual</th>
          <th>Total Após (R$)</th>
          <th class="text-end">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($__reajustes_salvos as $r): ?>
          <?php
             // created_by vindo da lib (pode ser null em registros antigos)
             $created_by_row = isset($r['created_by']) ? (int)$r['created_by'] : null;

             // regra 24h / dono do registro
             $pode_mexer = function_exists('coh_pode_alterar')
                           ? coh_pode_alterar($r['created_at'] ?? null, $tem_permissao_geral, $created_by_row)
                           : false;

             $segundos_restantes = 0;
             if ($pode_mexer && !empty($r['created_at'])) {
                 $criado_em = strtotime($r['created_at']);
                 $segundos_restantes = 86400 - (time() - $criado_em);
                 if ($segundos_restantes < 0) $segundos_restantes = 0;
             }

             // Formatação da data para exibição (d/m/Y)
             $data_exibicao = '—';
             if (!empty($r['data_base']) && $r['data_base'] !== '0000-00-00') {
                 $data_exibicao = date('d/m/Y', strtotime($r['data_base']));
             }
          ?>
          <tr>
            <td><?= e($data_exibicao) ?></td>
            <td><?= coh_pct($r['percentual'] ?? $r['reajustes_percentual'] ?? 0) ?></td>
            <td><?= coh_brl($r['valor_total_apos_reajuste'] ?? 0) ?></td>
            <td class="text-end" style="min-width: 160px;">
                <?php if ($pode_mexer): ?>
                    <div class="btn-group btn-group-sm mb-1">
                        <button type="button"
                                class="btn btn-outline-secondary"
                                onclick='cohEditDbItem("reajuste", <?= json_encode($r) ?>)'
                                title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button"
                                class="btn btn-outline-danger"
                                onclick="cohDeleteDbItem('reajuste', <?= (int)$r['id'] ?>)"
                                title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="text-danger small fw-bold timer-24h"
                         data-seconds="<?= (int)$segundos_restantes ?>"
                         style="font-size: 0.7rem;">
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
  <div class="text-muted small mb-2">Nenhum reajuste salvo no banco ainda.</div>
<?php endif; ?>

<input type="hidden" id="valor_base_calculo_reajuste" value="<?= number_format($valor_base_calculo, 2, '.', '') ?>">

<div class="modal fade" id="modalReajuste" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Novo Reajustamento</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-light border py-1 px-2 mb-3 small text-muted">
        <i class="bi bi-info-circle"></i>
        Base de cálculo:
        <strong><?= e(coh_brl($valor_base_calculo)) ?></strong>
        (<?= e($origem_base) ?>)
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Data Base</label>
          <input type="date" name="data_base" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Percentual (%)</label>
          <input type="text" name="percentual" id="reaj_percentual" class="form-control" placeholder="0,00">
        </div>
        <div class="col-md-12">
          <label class="form-label">Valor Total Após Reajuste (R$)</label>
          <input type="text" name="valor_total_apos_reajuste" id="reaj_total" class="form-control" placeholder="0,00">
        </div>
        <div class="col-12">
          <label class="form-label">Observação</label>
          <textarea name="observacao" class="form-control" rows="2"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="(function(){
        var root=document.getElementById('modalReajuste');
        var p={
           data_base: root.querySelector('input[name=data_base]')?.value||'',
           percentual: root.querySelector('input[name=percentual]')?.value||'',
           valor_total_apos_reajuste: root.querySelector('input[name=valor_total_apos_reajuste]')?.value||'',
           observacao: root.querySelector('textarea[name=observacao]')?.value||''
        };
        if(window.cohAddReajuste) window.cohAddReajuste(p);
        root.querySelectorAll('input,textarea').forEach(function(el){ el.value=''; });
        var m=bootstrap.Modal.getInstance(root); if(m)m.hide();
      })()">Salvar no Rascunho</button>
    </div>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const inpPercentual = document.getElementById('reaj_percentual');
    const inpTotal      = document.getElementById('reaj_total');
    const baseHidden    = document.getElementById('valor_base_calculo_reajuste');
    const modal         = document.getElementById('modalReajuste');

    if (!inpPercentual || !inpTotal || !baseHidden || !modal) return;

    const parseBR = v => parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || 0;
    const fmtBR   = v => v.toLocaleString('pt-BR',
                        {minimumFractionDigits: 2, maximumFractionDigits: 2});

    modal.addEventListener('shown.bs.modal', function() {
        if (inpTotal.value === '' || inpTotal.value === '0,00') {
             const baseVal = parseFloat(baseHidden.value) || 0;
             if (baseVal > 0) inpTotal.value = fmtBR(baseVal);
        }
    });

    inpPercentual.addEventListener('input', function() {
        const baseVal = parseFloat(baseHidden.value) || 0;
        const perc    = parseBR(this.value);
        const novoTot = baseVal * (1 + (perc / 100));
        inpTotal.value = fmtBR(novoTot);
    });

    inpTotal.addEventListener('change', function() {
        const baseVal       = parseFloat(baseHidden.value) || 0;
        const totalDigitado = parseBR(this.value);

        if (baseVal > 0 && totalDigitado > 0) {
            const diferenca = totalDigitado - baseVal;
            const percCalc  = (diferenca / baseVal) * 100;
            if (document.activeElement === this) {
                 inpPercentual.value = fmtBR(percCalc);
            }
        }
    });
});
</script>
