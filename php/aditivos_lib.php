<?php
// partials/form_emop_contratos_aditivos.php

if (!function_exists('e')) { function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('coh_brl')) { function coh_brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); } }

$__cid = (int)($id ?? ($row['id'] ?? 0));
$tem_permissao_geral = isset($user_level) ? ($user_level >= 2) : true;

$valor_base_contrato = 0.0;
if (isset($row) && is_array($row) && isset($row['Valor_Do_Contrato']) && $row['Valor_Do_Contrato'] !== '') {
  $raw = trim((string)$row['Valor_Do_Contrato']);
  if ($raw !== '') {
    $raw = str_replace(['.', ','], ['', '.'], $raw);
    $valor_base_contrato = (float)$raw;
  }
}

if (!defined('COH_DRAFT_INPUTS')) {
  define('COH_DRAFT_INPUTS', true);
  echo '<input type="hidden" name="novas_medicoes_json"   id="novas_medicoes_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_aditivos_json"   id="novos_aditivos_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_reajustes_json"  id="novos_reajustes_json"  value="">' . PHP_EOL;
}

if (!defined('COH_DRAFT_JS')) {
  define('COH_DRAFT_JS', true);
  ?>
  <script>
  window.COH = window.COH || {};
  COH.draft = COH.draft || { medicoes: [], aditivos: [], reajustes: [] };
  function cohSetHiddenDraft(){
    var m=document.getElementById('novas_medicoes_json'), a=document.getElementById('novos_aditivos_json'), r=document.getElementById('novos_reajustes_json');
    if(m)m.value=JSON.stringify(COH.draft.medicoes); if(a)a.value=JSON.stringify(COH.draft.aditivos); if(r)r.value=JSON.stringify(COH.draft.reajustes);
  }
  function cohRenderDraft(listId, arr){
    var ul=document.getElementById(listId); if(!ul) return;
    ul.innerHTML='';
    arr.forEach(function(item, idx){
      var li=document.createElement('li');
      li.className='d-flex align-items-start justify-content-between border rounded px-2 py-1 mb-1';
      li.innerHTML = '<div><strong>'+(item._label||'Item')+'</strong><div class="small text-secondary">'+(item._desc||'')+'</div></div><button type="button" class="btn btn-sm btn-outline-danger ms-2" data-remove="'+idx+'">Excluir</button>';
      li.querySelector('button[data-remove]').addEventListener('click', function(ev){ ev.preventDefault(); ev.stopPropagation(); arr.splice(idx,1); cohSetHiddenDraft(); cohRenderDraft(listId, arr); });
      ul.appendChild(li);
    });
  }
  window.cohAddMedicao = function(p){ var l='Medição '+(p.data_medicao||''); var d='Valor: '+(p.valor_rs||''); COH.draft.medicoes.push(Object.assign({_label:l,_desc:d}, p)); cohSetHiddenDraft(); cohRenderDraft('draft-list-medicoes', COH.draft.medicoes); };
  window.cohAddAditivo = function(p){ var l='Aditivo '+(p.numero_aditivo||''); var d='Valor: '+(p.valor_aditivo_total||''); COH.draft.aditivos.push(Object.assign({_label:l,_desc:d}, p)); cohSetHiddenDraft(); cohRenderDraft('draft-list-aditivos', COH.draft.aditivos); };
  window.cohAddReajuste = function(p){ var l='Reajuste '+(p.indice||''); var d='Perc: '+(p.percentual||''); COH.draft.reajustes.push(Object.assign({_label:l,_desc:d}, p)); cohSetHiddenDraft(); cohRenderDraft('draft-list-reajustes', COH.draft.reajustes); };
  </script>
  <?php
}

require_once __DIR__ . '/../php/aditivos_lib.php';
$__aditivos_salvos = [];
if ($__cid > 0) {
  if (function_exists('coh_ensure_aditivos_schema')) coh_ensure_aditivos_schema($conn);
  // inclui created_by para regra 24h
  $sqlAd = "SELECT id, contrato_id, valor_aditivo_total, novo_prazo,
                   valor_total_apos_aditivo, numero_aditivo, tipo,
                   created_at, created_by, observacao
            FROM emop_aditivos
            WHERE contrato_id = ?
            ORDER BY created_at ASC, id ASC";
  if ($st = $conn->prepare($sqlAd)) {
    $st->bind_param('i', $__cid);
    $st->execute();
    $rs = $st->get_result();
    $prev_acum = 0.0; 
    while ($r = $rs->fetch_assoc()) {
      $valor      = ($r['valor_aditivo_total']      !== null ? (float)$r['valor_aditivo_total']      : 0.0);
      $acum_total = ($r['valor_total_apos_aditivo'] !== null ? (float)$r['valor_total_apos_aditivo'] : $prev_acum + $valor);
      $__aditivos_salvos[] = [
        'id'                       => $r['id'],
        'created_at'               => $r['created_at'],
        'created_by'               => $r['created_by'] ?? null,
        'novo_prazo'               => $r['novo_prazo'],
        'numero_aditivo'           => $r['numero_aditivo'],
        'tipo'                     => $r['tipo'],
        'valor_aditivo_total'      => $valor,
        'valor_total_apos_aditivo' => $acum_total,
        'aditivo_anterior'         => $prev_acum,
        'observacao'               => $r['observacao']
      ];
      $prev_acum = $acum_total;
    }
    $st->close();
  }
}
?>

<ul id="draft-list-aditivos" class="list-unstyled mb-3"></ul>
<button type="button" class="btn btn-outline-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalAditivo">+ Adicionar Aditivo</button>

<?php if (!empty($__aditivos_salvos)): ?>
  <div class="table-responsive mb-2">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Data</th>
          <th>Número/Tipo</th>
          <th>Valor (R$)</th>
          <th>Acumulado (R$)</th>
          <th>Novo prazo</th>
          <th class="text-end">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($__aditivos_salvos as $a): ?>
          <?php 
             $pode_mexer = function_exists('coh_pode_alterar') 
                           ? coh_pode_alterar($a['created_at'] ?? null, $tem_permissao_geral, $a['created_by'] ?? null) 
                           : false;
             
             // CÁLCULO DO TEMPO RESTANTE
             $segundos_restantes = 0;
             if ($pode_mexer && !empty($a['created_at'])) {
                  $criado_em = strtotime($a['created_at']);
                  $segundos_restantes = 86400 - (time() - $criado_em);
                  if ($segundos_restantes < 0) $segundos_restantes = 0;
             }
          ?>
          <tr>
            <td><?= $a['created_at'] ? e(date('d/m/Y', strtotime($a['created_at']))) : '—' ?></td>
            <td>
              <strong><?= e($a['numero_aditivo'] ?? '') ?></strong><br>
              <small class="text-muted"><?= e($a['tipo'] ?? '') ?></small>
            </td>
            <td><?= coh_brl($a['valor_aditivo_total'] ?? 0) ?></td>
            <td><?= coh_brl($a['valor_total_apos_aditivo'] ?? 0) ?></td>
            <td><?= $a['novo_prazo'] !== null ? e($a['novo_prazo']) : '—' ?></td>
            <td class="text-end" style="min-width: 140px;">
                <?php if ($pode_mexer): ?>
                    <div class="btn-group btn-group-sm mb-1">
                        <button type="button" class="btn btn-outline-secondary" onclick='cohEditDbItem("aditivo", <?= json_encode($a) ?>)' title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger" onclick="cohDeleteDbItem('aditivo', <?= $a['id'] ?>)" title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="text-danger small fw-bold timer-24h" data-seconds="<?= $segundos_restantes ?>" style="font-size: 0.7rem;">
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
  <div class="text-muted small mb-2">Nenhum aditivo salvo no banco ainda.</div>
<?php endif; ?>

<input type="hidden" id="valor_base_contrato" value="<?= e($valor_base_contrato) ?>">

<div class="modal fade" id="modalAditivo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Novo Aditivo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Número</label><input type="text" name="numero_aditivo" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Data</label><input type="date" name="data" class="form-control"></div>
        <div class="col-md-5">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-select">
            <option value="">Selecione...</option>
            <option value="PRAZO">Prazo</option>
            <option value="VALOR">Valor</option>
            <option value="AMBOS">Ambos</option>
            <option value="RE-RATIFICACAO">Re-ratificação</option>
            <option value="OBJETO">Objeto</option>
            <option value="OUTROS">Outros</option>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Valor (R$)</label><input type="text" name="valor_aditivo_total" class="form-control" id="adt_valor" placeholder="0,00"></div>
        <div class="col-md-6"><label class="form-label">Total Após (R$)</label><input type="text" name="valor_total_apos_aditivo" class="form-control" id="adt_total" placeholder="0,00"></div>
        <div class="col-12"><label class="form-label">Observação</label><textarea name="observacao" class="form-control" rows="2"></textarea></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="(function(){
        var root=document.getElementById('modalAditivo');
        var p={
          numero_aditivo:root.querySelector('input[name=numero_aditivo]')?.value||'',
          data:root.querySelector('input[name=data]')?.value||'',
          tipo:root.querySelector('select[name=tipo]')?.value||'',
          valor_aditivo_total:root.querySelector('input[name=valor_aditivo_total]')?.value||'',
          valor_total_apos_aditivo:root.querySelector('input[name=valor_total_apos_aditivo]')?.value||'',
          observacao:root.querySelector('textarea[name=observacao]')?.value||''
        };
        if(window.cohAddAditivo) window.cohAddAditivo(p);
        root.querySelectorAll('input, select, textarea').forEach(el => el.value = '');
        var m=bootstrap.Modal.getInstance(root); if(m)m.hide();
      })()">Salvar no Rascunho</button>
    </div>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var modal = document.getElementById('modalAditivo'); if (!modal) return;
  var baseInput = document.getElementById('valor_base_contrato');
  var valorBaseContrato = baseInput ? (parseFloat(String(baseInput.value)) || 0) : 0;
  function parseBRL(str){ if (!str) return 0; str = String(str).trim().replace(/\./g, '').replace(',', '.'); var n = parseFloat(str); return isNaN(n) ? 0 : n; }
  function formatBRL(n){ try { return n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); } catch(e){ return (Math.round(n*100)/100).toFixed(2).replace('.', ','); } }
  var inpValor = document.getElementById('adt_valor');
  var inpTotal = document.getElementById('adt_total');
  if (inpValor && inpTotal) {
    function atualizarTotal(){ var vAd = parseBRL(inpValor.value); var total = valorBaseContrato + vAd; inpTotal.value = formatBRL(total); }
    modal.addEventListener('shown.bs.modal', function(){ if(!inpTotal.value && inpTotal.value !== '0,00') { inpTotal.value = formatBRL(valorBaseContrato); } });
    ['input','blur'].forEach(function(evt){ inpValor.addEventListener(evt, atualizarTotal); });
  }
});
</script>
