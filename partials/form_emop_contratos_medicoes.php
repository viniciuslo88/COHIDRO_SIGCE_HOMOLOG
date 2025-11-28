<?php
// partials/form_emop_contratos_medicoes.php

if (!function_exists('e')) { function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
function brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); }
function pct($n){ return number_format((float)$n, 2, ',', '.').'%' ; }

// Variáveis do escopo pai
$__cid = (int)($id ?? ($row['id'] ?? 0));
$__valor_total_contrato = (float)($row['Valor_Total_Do_Contrato_Novo'] ?? 0);
if ($__valor_total_contrato <= 0) {
  $__valor_total_contrato = (float)($row['Valor_Do_Contrato'] ?? 0);
}

// ===== BUSCA: Medições salvas =====
require_once __DIR__ . '/../php/medicoes_lib.php';
$__medicoes_salvas = [];
if ($__cid > 0) {
  $__medicoes_salvas = coh_fetch_medicoes_with_prev($conn, $__cid);
}

// ===== Cálculo do liquidado anterior baseado na ÚLTIMA medição =====
if (!empty($__medicoes_salvas)) {
    $acumulado = 0.0;
    foreach ($__medicoes_salvas as $m) $acumulado += (float)($m['valor_rs'] ?? 0);
    $__liquidado_anterior = $acumulado;
} else {
    $__liquidado_anterior = (float)($row['Valor_Liquidado_Acumulado'] ?? $row['Medicao_Anterior_Acumulada_RS'] ?? 0);
}

// Permissão (vem do $user_level do arquivo pai)
$tem_permissao_geral = isset($user_level) ? ($user_level >= 2) : true; 

// ===== Campos hidden globais de rascunho =====
if (!defined('COH_DRAFT_INPUTS')) {
  define('COH_DRAFT_INPUTS', true);
  echo '<input type="hidden" name="novas_medicoes_json"   id="novas_medicoes_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_aditivos_json"   id="novos_aditivos_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_reajustes_json"  id="novos_reajustes_json"  value="">' . PHP_EOL;
}

// ===== Script global de rascunho =====
if (!defined('COH_DRAFT_JS')) { define('COH_DRAFT_JS', true); ?>
<script>
window.COH = window.COH || {};
COH.draft = COH.draft || { medicoes: [], aditivos: [], reajustes: [] };

function cohSetHiddenDraft(){
  let m=document.getElementById('novas_medicoes_json'), a=document.getElementById('novos_aditivos_json'), r=document.getElementById('novos_reajustes_json');
  if(m)m.value=JSON.stringify(COH.draft.medicoes);
  if(a)a.value=JSON.stringify(COH.draft.aditivos);
  if(r)r.value=JSON.stringify(COH.draft.reajustes);
}

function cohRenderDraft(listId, arr){
  let ul=document.getElementById(listId); if(!ul) return;
  ul.innerHTML='';
  arr.forEach((item, idx)=>{
    let li=document.createElement('li');
    li.className='d-flex align-items-start justify-content-between border rounded px-2 py-1 mb-1';
    li.innerHTML = '<div><strong>'+(item._label||'Item')+'</strong><div class="small text-secondary">'+(item._desc||'')+'</div></div><button type="button" class="btn btn-sm btn-outline-danger ms-2" data-remove="'+idx+'">Excluir</button>';
    li.querySelector('button[data-remove]').addEventListener('click', ev=>{ ev.preventDefault(); ev.stopPropagation(); arr.splice(idx,1); cohSetHiddenDraft(); cohRenderDraft(listId, arr); });
    ul.appendChild(li);
  });
}
window.cohAddMedicao=function(p){ let l='Medição '+(p.data_medicao||''); let d='Valor: '+(p.valor_rs||''); COH.draft.medicoes.push(Object.assign({_label:l,_desc:d}, p)); cohSetHiddenDraft(); cohRenderDraft('draft-list-medicoes', COH.draft.medicoes); };
window.cohAddAditivo=function(p){ let l='Aditivo '+(p.numero_aditivo||''); let d='Valor: '+(p.valor_aditivo_total||''); COH.draft.aditivos.push(Object.assign({_label:l,_desc:d}, p)); cohSetHiddenDraft(); cohRenderDraft('draft-list-aditivos', COH.draft.aditivos); };
window.cohAddReajuste=function(p){ let l='Reajuste '+(p.indice||''); let d='Perc: '+(p.percentual||''); COH.draft.reajustes.push(Object.assign({_label:l,_desc:d}, p)); cohSetHiddenDraft(); cohRenderDraft('draft-list-reajustes', COH.draft.reajustes); };
</script>
<?php } ?>

<ul id="draft-list-medicoes" class="list-unstyled mb-3"></ul>
<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalMedicao">+ Adicionar Medição</button>

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
              $valor = (float)($m['valor_rs'] ?? 0);
              $liq_ant = $running;
              $running += $valor;
              $acum = $running;
              $pct_line = $__valor_total_contrato > 0 ? (($acum / $__valor_total_contrato) * 100.0) : null;
              
              // Verifica regra 24h usando a função DO ARQUIVO PAI
              $pode_mexer = function_exists('coh_pode_alterar') 
                            ? coh_pode_alterar($m['created_at'] ?? null, $tem_permissao_geral) 
                            : false;
            ?>
            <tr>
              <td><?= $m['data_medicao'] ? date('d/m/Y', strtotime($m['data_medicao'])) : '—' ?></td>
              <td><?= brl($liq_ant) ?></td>
              <td><?= brl($valor) ?></td>
              <td><?= brl($acum) ?></td>
              <td><?= $pct_line!==null ? pct($pct_line) : '—' ?></td>
              <td class="text-end">
                <?php if ($pode_mexer): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                            onclick='cohEditDbItem("medicao", <?= json_encode($m) ?>)' title="Editar">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" 
                            onclick="cohDeleteDbItem('medicao', <?= $m['id'] ?>)" title="Excluir">
                        <i class="bi bi-trash"></i>
                    </button>
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

<div class="modal fade" id="modalMedicao" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Nova Medição</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-4">
            <label>Data</label>
            <input type="date" name="data_medicao" class="form-control">
        </div>
        <div class="col-md-4">
            <label>Liquidado anterior (R$)</label>
            <input type="text" class="form-control" id="med_liq_ant_view" value="<?= brl($__liquidado_anterior) ?>" readonly>
        </div>
        <div class="col-md-4">
            <label>Valor da medição (R$)</label>
            <input type="text" name="valor_rs" class="form-control" id="med_valor_rs" placeholder="0,00">
        </div>
        <div class="col-md-12">
            <label>Liquidado acumulado (R$)</label>
            <input type="text" name="acumulado_rs" class="form-control" id="med_acumulado_rs" readonly>
        </div>
        <div class="col-md-12">
            <label>% Liquidado</label>
            <input type="text" name="percentual" class="form-control" id="med_percentual" readonly>
        </div>
        <div class="col-12">
            <label>Observação</label>
            <textarea name="observacao" class="form-control" rows="2"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="(function(){
        var root=document.getElementById('modalMedicao');
        var p={
          data_medicao: root.querySelector('input[name=data_medicao]').value,
          valor_rs: root.querySelector('input[name=valor_rs]').value,
          acumulado_rs: root.querySelector('input[name=acumulado_rs]').value,
          percentual: root.querySelector('input[name=percentual]').value,
          observacao: root.querySelector('textarea[name=observacao]').value
        };
        if(window.cohAddMedicao) window.cohAddMedicao(p);
        root.querySelectorAll('input,textarea').forEach(el=>el.value='');
        bootstrap.Modal.getInstance(root).hide();
      })()">Salvar no Rascunho</button>
    </div>
  </div></div>
</div>

<script>
(function(){
  const LIQ_ANT = <?= json_encode($__liquidado_anterior) ?>;
  const VLR_TOT = <?= json_encode($__valor_total_contrato) ?>;

  function brlToFloat(str){
    if(!str) return 0;
    return parseFloat(str.replace(/\./g,'').replace(',', '.')) || 0;
  }
  function fmtBRL(n){
    return (n||0).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
  }
  function fmtPCT(n){
    return (n||0).toFixed(2).replace('.',',');
  }

  function recalc(){
    let v = brlToFloat(document.getElementById('med_valor_rs').value);
    let ac = (LIQ_ANT || 0) + v;
    let pc = VLR_TOT > 0 ? (ac / VLR_TOT) * 100 : 0;
    document.getElementById('med_acumulado_rs').value = fmtBRL(ac);
    document.getElementById('med_percentual').value  = fmtPCT(pc);
  }

  document.addEventListener('input', ev=>{ if(ev.target.id === 'med_valor_rs') recalc(); });
  document.getElementById('modalMedicao')?.addEventListener('shown.bs.modal', ()=>setTimeout(recalc,130));
})();
</script>