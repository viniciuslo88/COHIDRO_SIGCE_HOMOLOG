<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$cpf    = $_SESSION['cpf']    ?? null;
$nome   = $_SESSION['nome']   ?? null;
$role   = (int)($_SESSION['role'] ?? 0);
$dir    = $_SESSION['diretoria'] ?? '-';

// auto-open apenas no primeiro carregamento após login
$just_logged_in = (int)($_SESSION['just_logged_in'] ?? 0);
unset($_SESSION['just_logged_in']);
?>
<nav class="navbar navbar-expand-lg bg-body border-bottom sticky-top coh-topbar">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="/">
      <img src="/Logo_Cohidro_Alta.png" alt="Cohidro" style="height:40px;width:auto"/>
    </a>

    <div class="ms-auto d-flex align-items-center">

      <style>.notif-badge{position:absolute; top:-6px; right:-6px;}</style>

      <script>
        // contador unificado (usa o endpoint do coordenador)
        async function loadCoordinatorCount(){
          try{
            const r = await fetch('/php/coordenador_inbox.php?mode=count', {cache:'no-store'});
            const j = await r.json();
            const n = (j && typeof j.count==='number') ? j.count : 0;
            const el = document.querySelector('#notifBadge'); // se existir
            if (el){
              el.textContent = n;
              el.classList.toggle('d-none', n<=0);
            }
          }catch(e){}
        }
        document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) loadCoordinatorCount(); });
        loadCoordinatorCount();
        setInterval(loadCoordinatorCount, 30000);

        // Fallback de toggle (caso o JS externo ainda não tenha sido carregado)
        if (!window.__cohToggleAlteracoes) {
          window.__cohToggleAlteracoes = function(btn){
            let sel = btn.getAttribute('data-target');
            if (sel && !sel.startsWith('#')) sel = '#'+sel;
            let row = sel ? document.querySelector(sel) : null;
            if (!row){
              const tr = btn.closest('tr');
              if (tr && tr.nextElementSibling && tr.nextElementSibling.id &&
                  (tr.nextElementSibling.id.startsWith('cr-changes-') || tr.nextElementSibling.id.startsWith('fi-changes-'))) {
                row = tr.nextElementSibling;
              }
            }
            if (!row) return;
            const hidden = row.classList.contains('d-none') || row.style.display==='none';
            if (hidden){
              row.classList.remove('d-none');
              if (row.tagName === 'TR') row.style.display = 'table-row';
              btn.setAttribute('aria-expanded','true');
              btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary');
            }else{
              if (row.tagName === 'TR') row.style.display = '';
              row.classList.add('d-none');
              btn.setAttribute('aria-expanded','false');
              btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary');
            }
          }
        }
      </script>

      <?php if ($role === 1): /* ===== Botão + JS do FISCAL (apenas nível 1) ===== */ ?>
        <script>
          async function updateFiscalBadge(){
            try{
              const r = await fetch('/php/fiscal_inbox.php?mode=count', { cache:'no-store', credentials:'same-origin' });
              const j = await r.json();
              const n = (j && typeof j.count === 'number') ? j.count : 0;
              const badge = document.getElementById('fiscalBadge');
              if (badge){
                badge.textContent = n > 99 ? '99+' : (n || '');
                badge.style.display = n > 0 ? 'inline-block' : 'none';
              }
              return n;
            }catch(e){ return 0; }
          }

          document.addEventListener('DOMContentLoaded', ()=>{
            updateFiscalBadge();
            setInterval(updateFiscalBadge, 30000);

            // Carrega conteúdo quando o modal abrir
            const modalEl = document.getElementById('fiscalInboxModal');
            if (modalEl && window.bootstrap){
              modalEl.addEventListener('show.bs.modal', ()=>{
                const body = document.getElementById('fiscalInboxBody');
                if (!body) return;
                body.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border" role="status"></div><div class="mt-2">Carregando…</div></div>';
                fetch('/php/fiscal_inbox.php?embed=1', { cache:'no-store', credentials:'same-origin', headers:{'X-Fragment':'1'} })
                  .then(r => r.text())
                  .then(html => { body.innerHTML = html; })
                  .catch(() => { body.innerHTML = '<div class="alert alert-danger">Falha ao carregar.</div>'; });
              });

              // Delegação de 'Ver alterações' também aqui (fallback)
              modalEl.addEventListener('click', function(ev){
                const btn = ev.target.closest('.js-ver-alteracoes');
                if (!btn) return;
                ev.preventDefault();
                if (window.__cohToggleAlteracoes) window.__cohToggleAlteracoes(btn);
              });
            }
          });
        </script>

        <!-- Botão do Fiscal -->
        <button class="btn btn-outline-warning position-relative ms-2"
                title="Pendências do Fiscal"
                data-bs-toggle="modal"
                data-bs-target="#fiscalInboxModal">
          <i class="bi bi-inbox"></i>
          <span id="fiscalBadge" class="badge rounded-pill bg-danger notif-badge" style="display:none;"></span>
        </button>
      <?php endif; ?>
      <!-- ===== /Botão Fiscal ===== -->

      <?php if ($role >= 2): /* ===== Coordenador / Nível 2+ ===== */ ?>
      <script>
        const JUST_LOGGED_IN = <?= $just_logged_in ? 'true' : 'false' ?>;

        async function updateCoordinatorBadge(){
          try{
            const r = await fetch('/php/coordenador_inbox.php?mode=count', {
              cache:'no-store', credentials:'same-origin'
            });
            if(!r.ok){ throw new Error('HTTP '+r.status); }
            const j = await r.json();
            const n = (j && typeof j.count === 'number') ? j.count : 0;

            const badge = document.getElementById('coordBadge');
            if (badge){
              badge.textContent = n > 99 ? '99+' : (n||'');
              badge.style.display = n > 0 ? 'inline-block' : 'none';
            }
            return n;
          }catch(e){
            return 0;
          }
        }

        // Carrega a inbox do coordenador no modal (fragmento)
        async function loadCoordinatorModal(){
          const body = document.getElementById('coordenadorInboxBody');
          body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border" role="status"></div><br>Carregando...</div>';
          try{
            const r = await fetch('/php/coordenador_inbox.php?embed=1', {
              cache:'no-store', credentials:'same-origin', headers:{'X-Fragment':'1'}
            });
            const html = await r.text();
            body.innerHTML = html;

            // Delegação: Ver alterações (usa classe .js-ver-alteracoes)
            body.addEventListener('click', (ev) => {
              const btn = ev.target.closest('.js-ver-alteracoes');
              if(!btn) return;
              ev.preventDefault();
              if (window.__cohToggleAlteracoes) window.__cohToggleAlteracoes(btn);
            });

            // (restante — aprovar/rejeitar — mantido)
            body.addEventListener('click', async (ev) => {
              const approveBtn = ev.target.closest('.js-approve');
              if (approveBtn) {
                try {
                  const id = approveBtn.getAttribute('data-id');
                  const fd = new URLSearchParams(); fd.append('id', id);
                  const rr = await fetch('/php/coordenador_aprovar.php', { method:'POST', body: fd, credentials:'same-origin' });
                  let ok=false,msg='OK'; try{ const j=await rr.json(); ok=!!j.ok; msg=j.error||'OK'; }catch(_){}
                  await loadCoordinatorModal(); await updateCoordinatorBadge();
                  alerta(body, ok ? 'Operação concluída.' : ('Falha: '+msg), ok);
                } catch(e) { alerta(body, 'Erro ao aprovar.', false); }
                return;
              }
              const rejectBtn = ev.target.closest('.js-reject');
              if (rejectBtn) {
                try {
                  const id = rejectBtn.getAttribute('data-id');
                  const fd = new URLSearchParams(); fd.append('id', id);
                  const rr = await fetch('/php/coordenador_rejeitar.php', { method:'POST', body: fd, credentials:'same-origin' });
                  let ok=false,msg='OK'; try{ const j=await rr.json(); ok=!!j.ok; msg=j.error||'OK'; }catch(_){}
                  await loadCoordinatorModal(); await updateCoordinatorBadge();
                  alerta(body, ok ? 'Operação concluída.' : ('Falha: '+msg), ok);
                } catch(e) { alerta(body, 'Erro ao rejeitar.', false); }
              }
            });

          }catch(e){
            body.innerHTML = '<div class="alert alert-danger m-3">Falha ao carregar a inbox do Coordenador.</div>';
          }
        }

        function alerta(scope, text, ok){
          const div = document.createElement('div');
          div.className = `alert ${ok?'alert-success':'alert-danger'} my-2`;
          div.textContent = text;
          scope.prepend(div);
          setTimeout(()=>div.remove(), 2500);
        }

        document.addEventListener('DOMContentLoaded', async ()=>{
          updateCoordinatorBadge();
          setInterval(updateCoordinatorBadge, 60000);

          const btn = document.getElementById('btnCoordInbox');
          if (btn){ btn.addEventListener('click', loadCoordinatorModal); }

          const modalEl = document.getElementById('coordenadorInboxModal');
          if (modalEl && typeof bootstrap !== 'undefined') {
            modalEl.addEventListener('show.bs.modal', () => {
              try { loadCoordinatorModal(); } catch (e) {}
            });
          }

          if (JUST_LOGGED_IN) {
            const pend = await updateCoordinatorBadge();
            if (pend > 0) {
              await loadCoordinatorModal();
              const modalEl = document.getElementById('coordenadorInboxModal');
              if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
              }
            }
          }
        });
      </script>

      <button class="btn btn-outline-primary position-relative ms-2"
              id="btnCoordInbox"
              title="Solicitações de alteração de contratos"
              data-bs-toggle="modal"
              data-bs-target="#coordenadorInboxModal">
        <i class="bi bi-clipboard-check"></i>
        <span id="coordBadge" class="badge rounded-pill bg-danger notif-badge" style="display:none;">0</span>
      </button>
      <?php endif; ?>

      <?php if ($role >= 5): ?>
      <script>
        // ===== BADGES (Reset + Fale Conosco) =====
        async function getResetCount(){
          try{
            const r = await fetch('/php/notificacoes_reset_count.php', {cache:'no-store', credentials:'same-origin'});
            const j = await r.json();
            return (j && typeof j.pending === 'number') ? j.pending : 0;
          }catch(e){ return 0; }
        }
        async function getFaleCount(){
          try{
            const r = await fetch('/php/gerenciamento_inbox.php?mode=count', {cache:'no-store', credentials:'same-origin'});
            const j = await r.json();
            return (j && typeof j.count === 'number') ? j.count : 0;
          }catch(e){ return 0; }
        }

        async function updateGerenciamentoBadges(){
          const [nReset, nFale] = await Promise.all([getResetCount(), getFaleCount()]);
          const total = (nReset||0) + (nFale||0);

          const elTotal = document.getElementById('badge-ger-total');
          if (elTotal){
            elTotal.textContent = total > 99 ? '99+' : total;
            elTotal.style.display = total > 0 ? 'inline-block' : 'none';
          }

          const elReset = document.getElementById('badge-ger-reset');
          if (elReset){
            elReset.textContent = nReset > 99 ? '99+' : nReset;
            elReset.style.display = nReset > 0 ? 'inline-block' : 'none';
          }

          const elFale = document.getElementById('badge-ger-fale');
          if (elFale){
            elFale.textContent = nFale > 99 ? '99+' : nFale;
            elFale.style.display = nFale > 0 ? 'inline-block' : 'none';
          }

          return {nReset, nFale, total};
        }

        // ===== LOAD TAB: RESET =====
        async function loadResetTab(){
          const cont = document.getElementById('tabResetContent');
          if (!cont) return;

          cont.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-warning" role="status"></div><br>Carregando resets...</div>';
          try{
            const r = await fetch('/php/reset_admin_inbox.php', {
              cache:'no-store',
              headers:{'X-Requested-With':'fetch'},
              credentials:'same-origin'
            });
            cont.innerHTML = await r.text();
          }catch(e){
            cont.innerHTML = '<div class="alert alert-danger m-3">Falha ao carregar as solicitações de reset.</div>';
          }
        }

        // ===== LOAD TAB: FALE CONOSCO =====
        async function loadFaleTab(){
          const cont = document.getElementById('tabFaleContent');
          if (!cont) return;

          cont.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-warning" role="status"></div><br>Carregando mensagens...</div>';
          try{
            const r = await fetch('/php/gerenciamento_inbox.php?embed=1', {
              cache:'no-store',
              credentials:'same-origin',
              headers:{'X-Fragment':'1'}
            });
            cont.innerHTML = await r.text();
          }catch(e){
            cont.innerHTML = '<div class="alert alert-danger m-3">Falha ao carregar mensagens do Fale Conosco.</div>';
          }
        }

        // ===== WIRE AJAX SUBMITS dentro do modal (Reset + Fale) =====
        function wireGerModalAjax(){
          const modalBody = document.getElementById('modalGerBody');
          if (!modalBody || modalBody.__wired) return;
          modalBody.__wired = true;

          modalBody.addEventListener('submit', async (ev)=>{
            const form = ev.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.getAttribute('data-ajax') !== '1') return;

            ev.preventDefault();
            ev.stopPropagation();

            const fd = new FormData(form);

            const btns = form.querySelectorAll('button, input[type="submit"]');
            btns.forEach(b=> b.disabled = true);

            try{
              await fetch(form.action, { method:'POST', body: fd, credentials:'same-origin' });

              // Recarrega a aba ativa
              const activeTab = document.querySelector('#gerTabs .nav-link.active')?.getAttribute('data-bs-target') || '#tabReset';
              if (activeTab === '#tabFale') await loadFaleTab();
              else await loadResetTab();

              await updateGerenciamentoBadges();
            }catch(e){
              alert('Não foi possível processar a ação. Tente novamente.');
            }finally{
              btns.forEach(b=> b.disabled = false);
            }
          }, true);
        }

        document.addEventListener('DOMContentLoaded', ()=>{
          updateGerenciamentoBadges();
          setInterval(updateGerenciamentoBadges, 60000);

          const modalEl = document.getElementById('modalGerInbox');
          if (modalEl && window.bootstrap){
            modalEl.addEventListener('show.bs.modal', async ()=>{
              wireGerModalAjax();
              await loadResetTab();
              await loadFaleTab();
              await updateGerenciamentoBadges();
            });

            // quando troca aba, se quiser recarregar “on-demand”:
            modalEl.addEventListener('shown.bs.tab', async (ev)=>{
              // opcional: manter como está (já carregamos ao abrir)
              await updateGerenciamentoBadges();
            });
          }
        });
      </script>

      <!-- Botão ÚNICO do Gerenciamento (Reset + Fale Conosco) -->
      <button class="btn btn-outline-warning position-relative ms-2"
              title="Inbox do Gerenciamento"
              data-bs-toggle="modal"
              data-bs-target="#modalGerInbox">
        <i class="bi bi-inbox-fill"></i>
        <span id="badge-ger-total" class="badge rounded-pill bg-danger notif-badge" style="display:none;">0</span>
      </button>
      <?php endif; ?>

      <!-- ===== Botão para instalar PWA ===== -->
      <button id="btnInstallApp"
              class="btn btn-outline-secondary ms-2"
              style="display:none;">
        <i class="bi bi-download me-1"></i> Instalar app
      </button>
      <!-- ===== /Botão PWA ===== -->

      <div class="dropdown ms-3">
        <button class="btn btn-outline-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-person-circle me-1"></i>
          <?php echo $nome ? htmlspecialchars($nome) : 'Usuário'; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li class="px-3 py-2 small text-secondary">
            <?php if ($nome): ?><div><strong><?= htmlspecialchars($nome) ?></strong></div><?php endif; ?>
            <?php if ($cpf): ?><div>CPF: <?= htmlspecialchars($cpf) ?></div><?php endif; ?>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="/php/alterar_senha.php"><i class="bi bi-key me-2"></i>Alterar senha</a></li>
          <li><a class="dropdown-item" href="/php/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
        </ul>
      </div>

    </div>
  </div>
</nav>

<?php
// Inclui modais compartilhados fora do index, como você já faz
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  if (!isset($GLOBALS['COH_MODAL_INBOX'])) { require __DIR__ . '/modal_coord_inbox.php'; $GLOBALS['COH_MODAL_INBOX']=1; }
  if ($role === 1 && !isset($GLOBALS['COH_MODAL_FISCAL'])) { require __DIR__ . '/modal_fiscal_inbox.php'; $GLOBALS['COH_MODAL_FISCAL']=1; }
}
?>

<?php if ($role >= 2): ?>
<div class="modal fade" id="coordenadorInboxModal" tabindex="-1" aria-labelledby="coordenadorInboxLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="coordenadorInboxLabel"><i class="bi bi-clipboard-check me-2"></i>Solicitações para Aprovação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="coordenadorInboxBody" style="min-height:300px;">
        <div class="text-center py-5 text-muted"><div class="spinner-border" role="status"></div><br>Carregando...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($role >= 5): ?>
<div class="modal fade" id="modalGerInbox" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-25">
        <h5 class="modal-title"><i class="bi bi-inbox me-2"></i>Inbox do Gerenciamento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>

      <div class="modal-body p-0" id="modalGerBody" style="min-height:300px;">
        <!-- Tabs -->
        <ul class="nav nav-tabs px-3 pt-3" id="gerTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-reset-btn" data-bs-toggle="tab" data-bs-target="#tabReset" type="button" role="tab">
              <i class="bi bi-shield-lock me-1"></i> Reset de Senha
              <span id="badge-ger-reset" class="badge bg-danger ms-2" style="display:none;">0</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-fale-btn" data-bs-toggle="tab" data-bs-target="#tabFale" type="button" role="tab">
              <i class="bi bi-chat-dots me-1"></i> Fale Conosco
              <span id="badge-ger-fale" class="badge bg-danger ms-2" style="display:none;">0</span>
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <div class="tab-pane fade show active" id="tabReset" role="tabpanel">
            <div id="tabResetContent">
              <div class="text-center py-5 text-muted">
                <div class="spinner-border text-warning" role="status"></div><br>Carregando...
              </div>
            </div>
          </div>

          <div class="tab-pane fade" id="tabFale" role="tabpanel">
            <div id="tabFaleContent">
              <div class="text-center py-5 text-muted">
                <div class="spinner-border text-warning" role="status"></div><br>Carregando...
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Safe init for dropdowns (keeps layout intact) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  try {
    document.querySelectorAll('.coh-topbar .dropdown-toggle').forEach(function(el){
      if (!el.getAttribute('data-bs-toggle')) el.setAttribute('data-bs-toggle','dropdown');
    });
    if (window.bootstrap && window.bootstrap.Dropdown) {
      document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        window.bootstrap.Dropdown.getOrCreateInstance(el);
      });
    }
  } catch (e) {}
});
</script>
<style>
/* keep menus on top of content */
.coh-topbar { z-index: 1030; }
.coh-topbar .dropdown-menu { z-index: 1050; }
</style>
