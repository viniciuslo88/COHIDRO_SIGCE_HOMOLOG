<?php
// partials/form_emop_contratos_aditivos.php

if (!function_exists('e')) { function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('coh_brl')) { function coh_brl($n){ return 'R$ '.number_format((float)$n, 2, ',', '.'); } }

$__cid = (int)($id ?? ($row['id'] ?? 0));
$pode_ver_botoes = isset($user_level) ? ($user_level >= 1) : false;
$is_read_only     = isset($is_read_only) ? $is_read_only : false;

// === CÁLCULO DA BASE DO CONTRATO (CORRIGIDO PARA FORMATO SQL E BRL) ===

$valor_base_contrato = 0.0;
$usou_coluna_nova = false;

// Função auxiliar para limpar números híbridos (BRL ou SQL)
function coh_limpar_valor_hibrido($v) {
    $v = trim((string)$v);
    // Se tiver vírgula, assume formato BR (ex: 1.000,00)
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v); // Remove ponto de milhar
        $v = str_replace(',', '.', $v); // Troca vírgula por ponto decimal
    } else {
        // Se NÃO tem vírgula, assume formato SQL (ex: 1000.00)
        // Remove tudo que não for dígito ou ponto
        $v = preg_replace('/[^\d.]/', '', $v);
    }
    return (float)$v;
}

// 1. Tenta pegar da coluna nova solicitada (Valor_Total_Do_Contrato_Novo)
if (isset($row) && !empty($row['Valor_Total_Do_Contrato_Novo'])) {
    $valor_base_contrato = coh_limpar_valor_hibrido($row['Valor_Total_Do_Contrato_Novo']);
    
    // Se achou valor válido (> 0), marcamos que usamos a coluna nova
    if ($valor_base_contrato > 0) {
        $usou_coluna_nova = true;
    }
}

// 2. Se não achou na nova, tenta na antiga (Valor_Do_Contrato)
if (!$usou_coluna_nova && isset($row) && !empty($row['Valor_Do_Contrato'])) {
    $valor_base_contrato = coh_limpar_valor_hibrido($row['Valor_Do_Contrato']);
}

// 3. Verifica aditivos no banco APENAS se não usou a coluna nova
if (!$usou_coluna_nova) {
    $soma_aditivos_banco = 0.0;
    $ultimo_acumulado    = 0.0;

    if ($__cid > 0) {
        $qSoma = "SELECT SUM(valor_aditivo_total) as total FROM emop_aditivos WHERE contrato_id = $__cid";
        if($rsS = $conn->query($qSoma)){
            $rS = $rsS->fetch_assoc();
            $soma_aditivos_banco = (float)($rS['total'] ?? 0);
        }

        $qUlt = "SELECT valor_total_apos_aditivo FROM emop_aditivos WHERE contrato_id = $__cid ORDER BY created_at DESC, id DESC LIMIT 1";
        if($rsU = $conn->query($qUlt)){
            $rU = $rsU->fetch_assoc();
            $ultimo_acumulado = (float)($rU['valor_total_apos_aditivo'] ?? 0);
        }
    }

    if ($ultimo_acumulado > 0) {
        $valor_base_contrato = $ultimo_acumulado;
    } elseif ($soma_aditivos_banco > 0) {
        $valor_base_contrato += $soma_aditivos_banco;
    }
}


// INICIALIZAÇÃO JS GARANTIDA
if (!defined('COH_DRAFT_JS')) {
  define('COH_DRAFT_JS', true);
  ?>
  <script>
  window.COH = window.COH || {};
  window.COH.draft = window.COH.draft || { medicoes: [], aditivos: [], reajustes: [] };
  
  function cohRenderDraft(listId, arr){
    var ul=document.getElementById(listId); if(!ul) return;
    ul.innerHTML='';
    arr.forEach(function(item, idx){
      var li=document.createElement('li');
      li.className='d-flex align-items-start justify-content-between border rounded px-2 py-1 mb-1 bg-white';
      li.innerHTML = '<div><span class="badge bg-warning text-dark me-2">Novo</span><strong>'+(item._label||'Item')+'</strong><div class="small text-secondary">'+(item._desc||'')+'</div></div><button type="button" class="btn btn-sm btn-outline-danger ms-2" data-remove="'+idx+'"><i class="bi bi-trash"></i></button>';
      
      // BOTÃO EXCLUIR RASCUNHO
      li.querySelector('button[data-remove]').addEventListener('click', function(ev){ 
          ev.preventDefault(); ev.stopPropagation(); 
          arr.splice(idx,1); 
          cohRenderDraft(listId, arr); 
          // Sincroniza logo após excluir
          if(window.cohForceSync) window.cohForceSync();
          
          // Atualiza hidden também ao excluir
          try {
              var iA = document.getElementById('novos_aditivos_json');
              if (iA) iA.value = JSON.stringify(window.COH.draft.aditivos || []);
          } catch(e){}
      });
      ul.appendChild(li);
    });
  }
  </script>
  <?php
}

require_once __DIR__ . '/../php/aditivos_lib.php';
$__aditivos_salvos = [];
if ($__cid > 0) {
  if (function_exists('coh_ensure_aditivos_schema')) coh_ensure_aditivos_schema($conn);
  $sqlAd = "SELECT id, contrato_id, valor_aditivo_total, novo_prazo, valor_total_apos_aditivo, numero_aditivo, tipo, created_at, observacao FROM emop_aditivos WHERE contrato_id = ? ORDER BY created_at ASC, id ASC";
  if ($st = $conn->prepare($sqlAd)) {
    $st->bind_param('i', $__cid);
    $st->execute();
    $rs = $st->get_result();
    $prev_acum = 0.0; 
    while ($r = $rs->fetch_assoc()) {
      $valor      = ($r['valor_aditivo_total']      !== null ? (float)$r['valor_aditivo_total']      : 0.0);
      $acum_total = ($r['valor_total_apos_aditivo'] !== null ? (float)$r['valor_total_apos_aditivo'] : $prev_acum + $valor);
      $__aditivos_salvos[] = [
        'id'                        => $r['id'],
        'created_at'                => $r['created_at'],
        'novo_prazo'                => $r['novo_prazo'],
        'numero_aditivo'            => $r['numero_aditivo'],
        'tipo'                      => $r['tipo'],
        'valor_aditivo_total'       => $valor,
        'valor_total_apos_aditivo' => $acum_total,
        'aditivo_anterior'          => $prev_acum,
        'observacao'                => $r['observacao']
      ];
      $prev_acum = $acum_total;
    }
    $st->close();
  }
}
?>

<div id="wrapper-aditivos" class="w-100 mb-3" style="display: flow-root; position: relative;">
    <ul id="draft-list-aditivos" class="list-unstyled mb-2"></ul>

    <?php if(!$is_read_only): ?>
    <div class="mb-3" style="position: static;">
        <a href="javascript:void(0)" 
           id="btn-add-aditivo-unique"
           class="btn btn-outline-primary" 
           role="button"
           data-bs-toggle="modal" 
           data-bs-target="#modalAditivo">
           + Inserir Aditivo
        </a>
    </div>
    <?php endif; ?>

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
                 // segundos restantes desde a criação (para o contador visual)
                 $segundos_restantes = 0;
                 if (!empty($a['created_at'])) {
                      $criado_em = strtotime($a['created_at']);
                      $segundos_restantes = 86400 - (time() - $criado_em);
                      if ($segundos_restantes < 0) $segundos_restantes = 0;
                 }

                 // regra: nível 5 (admin/dev) pode sempre mexer;
                 // demais níveis só dentro das 24h
                 $pode_mexer = false;
                 if ($pode_ver_botoes && !empty($a['created_at'])) {
                      if ($user_level >= 5) {
                          $pode_mexer = true;
                      } else {
                          $pode_mexer = ($segundos_restantes > 0);
                      }
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
                            <button type="button"
                                    class="btn btn-outline-secondary"
                                    onclick='cohEditDbItem("aditivo", <?= json_encode($a) ?>)'
                                    title="Editar">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button"
                                    class="btn btn-outline-danger"
                                    onclick="cohDeleteDbItem('aditivo', <?= (int)$a['id'] ?>)"
                                    title="Excluir">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>

                        <?php if ($segundos_restantes > 0 && $user_level < 5): ?>
                            <div class="text-danger small fw-bold timer-24h"
                                 data-seconds="<?= (int)$segundos_restantes ?>"
                                 style="font-size: 0.7rem;">
                                Calculando...
                            </div>
                        <?php else: ?>
                            <span class="badge text-bg-success">Salvo</span>
                        <?php endif; ?>

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
</div>

<input type="hidden" id="valor_base_contrato" value="<?= number_format($valor_base_contrato, 2, '.', '') ?>">

<div class="modal fade" id="modalAditivo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Novo Aditivo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Data</label><input type="date" name="data" class="form-control"></div>
        <div class="col-md-5">
            <label class="form-label">Tipo</label>
            <select name="tipo" id="selTipoAditivo" class="form-select">
                <option value="">Selecione...</option>
                <option value="PRAZO">Prazo</option>
                <option value="VALOR">Valor</option>
                <option value="AMBOS">Ambos (Prazo e Valor)</option>
                <option value="RE-RATIFICACAO">Re-ratificação</option>
                <option value="OBJETO">Objeto</option>
                <option value="OUTROS">Outros</option>
            </select>
        </div>
        <div class="col-md-6"><label class="form-label">Valor (R$)</label><input type="text" name="valor_aditivo_total" class="form-control" id="adt_valor" placeholder="0,00"></div>
        <div class="col-md-6"><label class="form-label">Total Após (R$)</label><input type="text" name="valor_total_apos_aditivo" class="form-control" id="adt_total" placeholder="0,00" readonly></div>
        <div class="col-md-6" id="divNovoPrazo" style="display:none;"><label class="form-label">Novo Prazo</label><input type="text" name="novo_prazo" class="form-control" placeholder="Ex: 12 meses"></div>
        <div class="col-12"><label class="form-label">Observação</label><textarea name="observacao" class="form-control" rows="2"></textarea></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="salvarAditivoNoDraft()">Inserir à Lista</button>
    </div>
  </div></div>
</div>

<script>
function salvarAditivoNoDraft() {
    var root = document.getElementById('modalAditivo');
    var p = {
        numero_aditivo:       root.querySelector('input[name=numero_aditivo]')?.value||'',
        data:                 root.querySelector('input[name=data]')?.value||'',
        tipo:                 root.querySelector('select[name=tipo]')?.value||'',
        valor_aditivo_total:  root.querySelector('input[name=valor_aditivo_total]')?.value||'',
        valor_total_apos_aditivo: root.querySelector('input[name=valor_total_apos_aditivo]')?.value||'',
        novo_prazo:           root.querySelector('input[name=novo_prazo]')?.value||'',
        observacao:           root.querySelector('textarea[name=observacao]')?.value||''
    };
    
    // Validação simples: pelo menos um campo relevante
    if (!p.numero_aditivo && !p.valor_aditivo_total && !p.novo_prazo && !p.observacao) {
         alert('Preencha ao menos um campo principal.'); 
         return;
    }

    // 1) Adiciona no objeto de rascunho para a UI
    if (window.cohAddAditivo) {
        window.cohAddAditivo(p);
    }

    // 2) Grava DIRETO no hidden novos_aditivos_json (sem depender do cohForceSync)
    try {
        var form = document.querySelector('form[data-form="emop-contrato"]') || document.getElementById('coh-form');
        if (form) {
            var inp = form.querySelector('input[name="novos_aditivos_json"]');
            if (!inp) {
                inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'novos_aditivos_json';
                inp.id   = 'novos_aditivos_json';
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
        console.error('Erro ao atualizar novos_aditivos_json:', e);
    }

    // 3) Atualiza input hidden global (se existir) via cohForceSync também (seguro extra)
    if (window.cohForceSync) window.cohForceSync();
    
    // 4) Limpa campos do modal
    root.querySelectorAll('input, select, textarea').forEach(el => el.value = '');
    var divPrazo = document.getElementById('divNovoPrazo');
        if(divPrazo) divPrazo.style.display = 'none';

    // 5) Fecha modal
    var m = bootstrap.Modal.getInstance(root);
    if (m) m.hide();
}

document.addEventListener('DOMContentLoaded', function(){

  // Toggle do campo "Novo Prazo" conforme o tipo de aditivo
  var selTipo = document.getElementById('selTipoAditivo');
  var divPrazo = document.getElementById('divNovoPrazo');
  if (selTipo && divPrazo) {
      selTipo.addEventListener('change', function () {
          var val = this.value;
          if (val === 'PRAZO' || val === 'AMBOS') {
              divPrazo.style.display = 'block';
          } else {
              divPrazo.style.display = 'none';
              var inpPrazo = divPrazo.querySelector('input[name="novo_prazo"]');
              if (inpPrazo) inpPrazo.value = '';
          }
      });
  }

  // ======== CÁLCULO "Total Após (R$)" NO MODAL ========

  var baseInput = document.getElementById('valor_base_contrato');

  // Função para limpar e converter BRL -> float
  function parseBRL(str) {
      if (!str) return 0;
      str = String(str).trim();
      // remove R$, espaços etc
      str = str.replace(/[^\d.,\-]/g, '');
      // troca separadores
      str = str.replace(/\./g, '').replace(',', '.');
      var n = parseFloat(str);
      return isNaN(n) ? 0 : n;
  }

  // Formata float -> BRL (string)
  function formatBRL(n) {
      try {
          return n.toLocaleString('pt-BR', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2
          });
      } catch (e) {
          var s = (Math.round(n * 100) / 100).toFixed(2);
          return s.replace('.', ',');
      }
  }

  // Use parseFloat aqui, pois o input já está em formato float limpo
  var valorBaseContrato = baseInput ? parseFloat(baseInput.value) : 0;

  var inpValor = document.getElementById('adt_valor');
  var inpTotal = document.getElementById('adt_total');

  if (inpValor && inpTotal) {

      function atualizarTotal() {
          var vAd = parseBRL(inpValor.value);
          var total = valorBaseContrato + vAd;
          inpTotal.value = formatBRL(total);
      }

      // Quando o modal abrir, já coloca o total com a base atual
      var modal = document.getElementById('modalAditivo');
      if (modal) {
          modal.addEventListener('shown.bs.modal', function () {
              // CORREÇÃO: Usar parseFloat, pois o hidden já vem como 1234.56
              valorBaseContrato = baseInput ? parseFloat(baseInput.value) : 0;
              
              inpValor.value = '';
              inpTotal.value = formatBRL(valorBaseContrato);
          });
      }

      ['input', 'blur', 'change', 'keyup'].forEach(function (evt) {
          inpValor.addEventListener(evt, atualizarTotal);
      });
  }

});
</script>