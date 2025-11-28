<?php
// partials/form_emop_contratos.php
// -------------------------------
// Wrapper do formulário de contratos + botão "Voltar à busca"

// Helpers
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ==========================================================
   Funções de formatação BR (dinheiro e data)
   ========================================================== */
if (!function_exists('br_money')) {
  function br_money($v){
      if ($v === null || $v === '') return '';
      // normaliza mesmo que venha como string com pontos/virgulas
      if (!is_numeric($v)) {
        $v = str_replace(['.', ','], ['', '.'], (string)$v);
      }
      return 'R$ ' . number_format((float)$v, 2, ',', '.');
  }
}

if (!function_exists('br_date')) {
  function br_date($d){
      if (!$d || $d === '0000-00-00') return '';
      if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)){
          return $m[3] . '/' . $m[2] . '/' . $m[1];
      }
      return $d; // permanece como veio
  }
}

/* ==========================================================
   1) Compat: normaliza ações legadas x atuais ANTES do controller
   ========================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $act = $_POST['action'] ?? '';
  // aliases aceitos historicamente
  $APROV_ALIASES = [
    'solicitar_aprovacao',
    'solicitar_coordenador',
    'request_approval',
    'send_for_approval'
  ];
  if (in_array($act, $APROV_ALIASES, true)) {
    $_POST['action'] = 'solicitar_aprovacao'; // único nome canônico
  }
}

// Defaults/guards vindos do arquivo principal
$HIDE_FORM   = $HIDE_FORM   ?? false;
$id          = isset($id) ? (int)$id : null;
$is_new      = $is_new      ?? false;
$row         = is_array($row ?? null) ? $row : [];
$load_error  = $load_error  ?? '';
$save_msg    = $save_msg    ?? '';
$save_ok     = $save_ok     ?? false;
$csrf        = $csrf        ?? ($_SESSION['csrf'] ?? '');

// ===== Política por nível: 1=fiscal precisa aprovar; 2(coord)/5(dev) salvam direto =====
$level = (int)($_SESSION['role'] ?? $_SESSION['user']['access_level'] ?? $_SESSION['access_level'] ?? 0);
$REQUIRES_COORD_APPROVAL = !in_array($level, [2,5], true);

// Exibir formulário?
$show_form = (!$HIDE_FORM) && (($id && !$load_error) || $is_new);
?>

<?php if (!empty($load_error)): ?>
  <div class="alert alert-danger"><?= e($load_error) ?></div>
<?php endif; ?>

<?php if (!empty($save_msg)): ?>
  <div class="alert <?= $save_ok ? 'alert-success' : 'alert-warning' ?>"><?= e($save_msg) ?></div>
<?php endif; ?>

<?php if ($show_form): ?>

  <?php if (function_exists('flash_out')) { flash_out(); } ?>

    <!-- Cabeçalho do formulário: Voltar à busca + indicador de modo -->
    <div class="coh-legend-wrap mb-3" style="display:flex;justify-content:center;">
      <div class="d-flex justify-content-between align-items-center" 
           style="width:100%;max-width:1100px;">
        <a href="/form_contratos.php" class="btn btn-outline-dark">
          <i class="bi bi-arrow-left-circle"></i> Voltar à busca
        </a>
        <?php if ($is_new): ?>
          <span class="badge text-bg-info">Modo criação</span>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Legenda de campos alterados -->
    <div class="coh-legend-wrap" style="display:flex;justify-content:center;">
      <div class="alert alert-secondary coh-legend text-center m-0" 
           style="width:100%;max-width:1100px;border-radius:10px;">
        <i class="bi bi-magic me-1"></i>
        Campos <strong>alterados</strong> ficam destacados e o rótulo recebe um selo
        <span class="badge text-bg-success">alterado</span>.
      </div>
    </div>
    <br>

  <!-- Formulário principal -->
  <form id="coh-form" method="post" action="" class="mx-auto" style="max-width: 1100px;" data-coh-form="emop-contrato">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <!-- IMPORTANTE: o controller lê 'contrato_id' -->
    <input type="hidden" name="contrato_id" value="<?= e($is_new ? 0 : (int)$id) ?>">
    <!-- compat opcional -->
    <input type="hidden" name="id" value="<?= e($is_new ? 0 : (int)$id) ?>">

    <?php
      // AÇÃO DO FORM — nomes que o controller entende
      $form_action = $REQUIRES_COORD_APPROVAL ? 'solicitar_aprovacao' : 'salvar';
      echo '<input type="hidden" name="action" value="'.e($form_action).'">';
      if ($REQUIRES_COORD_APPROVAL) {
        echo '<input type="hidden" name="approval_intent" value="1">';
      }
    ?>

    <div class="sec-list">
      <?php include __DIR__ . '/form_emop_contratos_sections.php'; ?>
    </div>

    <?php
      // Botões — texto/ícone estáveis
      $btnClass = $REQUIRES_COORD_APPROVAL ? 'warning' : 'success';
      $btnIcon  = $REQUIRES_COORD_APPROVAL ? 'send'    : 'check2-circle';
      $btnText  = $REQUIRES_COORD_APPROVAL
        ? 'Solicitar aprovação'
        : ($is_new ? 'Criar contrato' : 'Salvar alterações');

      // ID previsível p/ JS legado
      $btnId = $REQUIRES_COORD_APPROVAL ? 'btnSolicitarAprovacao' : 'btnSalvarContrato';
    ?>

    <div class="d-flex gap-2 justify-content-center mt-2">
      <button type="submit" id="<?= e($btnId) ?>" class="btn btn-<?= e($btnClass) ?>" data-coh-btn="primary">
        <i class="bi bi-<?= e($btnIcon) ?>"></i> <?= e($btnText) ?>
      </button>

      <a class="btn btn-outline-secondary" href="/form_contratos.php<?= $is_new ? '' : ('?id='.(int)$id) ?>">
        <i class="bi bi-x-circle"></i> Limpar
      </a>
    </div>
  </form>

  <!-- Toast container (Bootstrap) -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
    <div id="cohToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="cohToastBody">Item adicionado ao rascunho.</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <!-- Guardas JS: impedem submit por botões de "Adicionar" e mostram toasts -->
  <script>
    (function(){
      document.addEventListener('DOMContentLoaded', function(){
        var form = document.getElementById('coh-form');
        if(!form) return;

        var needApproval = <?= $REQUIRES_COORD_APPROVAL ? 'true' : 'false' ?>;
        var actionInput  = form.querySelector('input[name="action"]');
        if (actionInput) actionInput.value = needApproval ? 'solicitar_aprovacao' : 'salvar';

        // 1) BLOQUEIA submit disparado por botões de "Adicionar" (medição/aditivo/reajuste)
        var addSelectors = [
          '#btnAddMedicao','[data-add-medicao]','.btn-add-medicao',
          '#btnAddAditivo','[data-add-aditivo]','.btn-add-aditivo',
          '#btnAddReajuste','[data-add-reajuste]','.btn-add-reajuste'
        ].join(',');

        var lastClickWasAdd = false;

        document.addEventListener('click', function(ev){
          var t = ev.target;
          var btn = t.closest('button, a[role="button"]');
          if (!btn) return;

          var isAdd = btn.matches(addSelectors);
          if (!isAdd) {
            // fallback por texto + contexto da seção
            var txt = (btn.textContent || btn.innerText || '').toLowerCase();
            if (/adicionar|incluir/.test(txt)) {
              var inMed  = !!btn.closest('.sec--med, #sec-med');
              var inAdit = !!btn.closest('.sec--adit, #sec-adit');
              var inReaj = !!btn.closest('.sec--reaj, #sec-reaj');
              isAdd = (inMed || inAdit || inReaj);
            }
          }

          if (isAdd) {
            lastClickWasAdd = true;
            // se for type=submit, troca para button
            if (btn.getAttribute('type') === 'submit') btn.setAttribute('type','button');
          } else {
            lastClickWasAdd = false;
          }
        }, true);

        form.addEventListener('submit', function(e){
          if (lastClickWasAdd) {
            // O clique foi em “Adicionar”: NUNCA submete o form
            e.preventDefault(); e.stopPropagation();
            lastClickWasAdd = false;
          } else {
            // Submit normal (Salvar / Solicitar aprovação)
            if (actionInput) actionInput.value = needApproval ? 'solicitar_aprovacao' : 'salvar';
          }
        }, true);

        // 2) TOAST “adicionado com sucesso” quando os partials enviarem o evento de rascunho
        function showToast(msg){
          try {
            var el = document.getElementById('cohToast');
            var body = document.getElementById('cohToastBody');
            if (!el || !body) return;
            body.textContent = msg || 'Item adicionado ao rascunho.';
            if (window.bootstrap && bootstrap.Toast) {
              var t = bootstrap.Toast.getOrCreateInstance(el, { delay: 2000 });
              t.show();
            }
          } catch(_) {}
        }

        document.addEventListener('coh:add-medicao',  function(){ showToast('Medição adicionada ao rascunho.');  });
        document.addEventListener('coh:add-aditivo',  function(){ showToast('Aditivo adicionado ao rascunho.');  });
        document.addEventListener('coh:add-reajuste', function(){ showToast('Reajustamento adicionado ao rascunho.'); });

      });
    })();
  </script>

<?php else: ?>
  <?php if (!$HIDE_FORM): ?>
    <!-- Quando não há formulário para mostrar, quem cuida da busca é o partial de busca -->
  <?php endif; ?>
<?php endif; ?>
