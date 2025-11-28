<?php
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

// Campos hidden compartilhados
if (!defined('COH_DRAFT_INPUTS')) {
  define('COH_DRAFT_INPUTS', true);
  echo '<input type="hidden" name="novas_medicoes_json"   id="novas_medicoes_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_aditivos_json"   id="novos_aditivos_json"   value="">' . PHP_EOL;
  echo '<input type="hidden" name="novos_reajustes_json"  id="novos_reajustes_json"  value="">' . PHP_EOL;
}

// Script de rascunho global (compartilhado com medições e aditivos)
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
    if(m)m.value=JSON.stringify(COH.draft.medicoes);
    if(a)a.value=JSON.stringify(COH.draft.aditivos);
    if(r)r.value=JSON.stringify(COH.draft.reajustes);
  }

  function cohRenderDraft(listId, arr){
    var ul=document.getElementById(listId); if(!ul) return;
    ul.innerHTML='';
    arr.forEach(function(item, idx){
      var li=document.createElement('li');
      li.className='d-flex align-items-start justify-content-between border rounded px-2 py-1 mb-1';
      li.innerHTML =
        '<div><strong>'+(item._label||'Item')+'</strong>'+
        '<div class="small text-secondary">'+(item._desc||'')+'</div></div>'+
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

  // Medições
  window.cohAddMedicao = function(p){
    var l='Medição de '+(p.data_medicao||'');
    var d='Valor: '+(p.valor_rs||'')+' · Percentual: '+(p.percentual||'');
    COH.draft.medicoes.push(Object.assign({_label:l,_desc:d}, p));
    cohSetHiddenDraft();
    cohRenderDraft('draft-list-medicoes', COH.draft.medicoes);
  };

  // Aditivos
  window.cohAddAditivo = function(p){
    var l='Aditivo nº '+(p.numero_aditivo||'')+(p.data ? (' em '+p.data) : '');
    var d='Tipo: '+(p.tipo||'')+
          ' · Valor: '+(p.valor_aditivo_total||'')+
          ' · Total após: '+(p.valor_total_apos_aditivo||'');
    COH.draft.aditivos.push(Object.assign({_label:l,_desc:d}, p));
    cohSetHiddenDraft();
    cohRenderDraft('draft-list-aditivos', COH.draft.aditivos);
  };

  // Reajustes
  window.cohAddReajuste = function(p){
    var l='Reajuste '+(p.indice||'')+(p.data_base ? (' ('+p.data_base+')') : '');
    var d='Percentual: '+(p.percentual||'')+
          ' · Total após: '+(p.valor_total_apos_reajuste||'');
    COH.draft.reajustes.push(Object.assign({_label:l,_desc:d}, p));
    cohSetHiddenDraft();
    cohRenderDraft('draft-list-reajustes', COH.draft.reajustes);
  };
  </script>
  <?php
}

// BUSCA REAJUSTES SALVOS
require_once __DIR__ . '/../php/reajustes_lib.php';
$__cid = (int)($id ?? ($row['id'] ?? 0));
$__reajustes_salvos = [];

if ($__cid > 0) {
  coh_ensure_reajustamento_schema($conn);
  $sqlRj = "SELECT id, contrato_id, reajustes_percentual, valor_total_apos_reajuste, created_at
            FROM emop_reajustamento
            WHERE contrato_id = ?
            ORDER BY created_at ASC, id ASC";
  if ($st = $conn->prepare($sqlRj)) {
    $st->bind_param('i', $__cid);
    $st->execute();
    $rs = $st->get_result();
    $prev_acum = 0.0;
    while ($r = $rs->fetch_assoc()) {
      $acum_total  = ($r['valor_total_apos_reajuste'] !== null ? (float)$r['valor_total_apos_reajuste'] : $prev_acum);
      $valor_linha = $acum_total - $prev_acum;
      $__reajustes_salvos[] = [
        'created_at'                => $r['created_at'],
        'reajustes_percentual'      => ($r['reajustes_percentual'] !== null ? (float)$r['reajustes_percentual'] : null),
        'valor_total_apos_reajuste' => $acum_total,
        'reajuste_anterior'         => $prev_acum,
        'valor_reajuste'            => $valor_linha,
      ];
      $prev_acum = $acum_total;
    }
    $st->close();
  }
}

if (!function_exists('coh_brl')) { function coh_brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); } }
if (!function_exists('coh_pct')) { function coh_pct($n){ return number_format((float)$n, 2, ',', '.').'%' ; } }
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
          <th>Data</th>
          <th>Reajuste anterior (R$)</th>
          <th>Valor do reajuste (R$)</th>
          <th>Reajuste acumulado (R$)</th>
          <th>% deste reajuste</th>
          <th class="text-end">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($__reajustes_salvos as $r): ?>
          <?php $pode_mexer = coh_pode_alterar($r['created_at'] ?? null, $usuario_tem_permissao); ?>
          <tr>
            <td><?= $r['created_at'] ? e(date('d/m/Y', strtotime($r['created_at']))) : '—' ?></td>
            <td><?= coh_brl($r['reajuste_anterior'] ?? 0) ?></td>
            <td><?= coh_brl($r['valor_reajuste'] ?? 0) ?></td>
            <td><?= coh_brl($r['valor_total_apos_reajuste'] ?? 0) ?></td>
            <td><?= $r['reajustes_percentual'] !== null ? coh_pct($r['reajustes_percentual']) : '—' ?></td>
            <td class="text-end">
                <?php if ($pode_mexer): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" 
                            onclick="cohDeleteDbItem('reajuste', <?= $r['id'] ?>)">Excluir</button>
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

<div class="modal fade" id="modalReajuste" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Novo Reajuste (Rascunho)</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Índice</label>
          <input type="text" name="indice" class="form-control" placeholder="IGP-M, IPCA, ...">
        </div>
        <div class="col-md-6">
          <label class="form-label">Percentual (%)</label>
          <input type="text" name="percentual" class="form-control" placeholder="0,00">
        </div>
        <div class="col-md-6">
          <label class="form-label">Data-base</label>
          <input type="date" name="data_base" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Valor Total após Reajuste (R$)</label>
          <input type="text" name="valor_total_apos_reajuste" class="form-control" placeholder="0,00">
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
        var indice=root.querySelector('input[name=indice]')?.value||'';
        var percentual=root.querySelector('input[name=percentual]')?.value||'';
        var data_base=root.querySelector('input[name=data_base]')?.value||'';
        var valor_total_apos_reajuste=root.querySelector('input[name=valor_total_apos_reajuste]')?.value||'';
        var observacao=root.querySelector('textarea[name=observacao]')?.value||'';
        
        if(window.cohAddReajuste) window.cohAddReajuste({
          indice, percentual, data_base,
          valor_total_apos_reajuste, observacao
        });
        
        // Limpar inputs
        root.querySelectorAll('input, select, textarea').forEach(el => el.value = '');
        
        var m=bootstrap.Modal.getInstance(root); if(m)m.hide();
      })();">Salvar no Rascunho</button>
    </div>
  </div></div>
</div>
