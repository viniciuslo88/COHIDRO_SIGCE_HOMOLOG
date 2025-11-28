// /assets/js/fiscal_inbox_global.js
(function(){
  const MODAL_ID  = 'fiscalInboxModal';
  const BODY_SEL  = '#fiscalInboxBody';
  const COUNT_URL = '/php/fiscal_inbox.php?mode=count';
  const EMBED_URL = '/php/fiscal_inbox.php?embed=1';

  function ensureModal(){
    if (document.getElementById(MODAL_ID)) return;
    const tpl = `
    <div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-inbox me-2"></i> Itens para revisão do Fiscal</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
          </div>
          <div class="modal-body" id="fiscalInboxBody">
            <div class="text-center text-muted py-4">
              <div class="spinner-border" role="status" aria-hidden="true"></div>
              <div class="mt-2">Carregando…</div>
            </div>
          </div>
          <div class="modal-footer justify-content-between">
            <div>
              <a class="btn btn-outline-secondary btn-sm" href="/php/historico_fiscal.php">
                Histórico do Fiscal
              </a>
            </div>
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
          </div>
        </div>
      </div>
    </div>
    <style>
      #${MODAL_ID} .modal-dialog.modal-xl { max-width: 1200px; }
      @media (min-width: 1400px){
        #${MODAL_ID} .modal-dialog.modal-xl { max-width: 1320px; }
      }
    </style>`;
    const d = document.createElement('div');
    d.innerHTML = tpl;
    document.body.appendChild(d.firstElementChild);
  }

  async function loadEmbed(){
    const body = document.querySelector(BODY_SEL);
    if (!body) return;
    body.innerHTML = `
      <div class="text-center text-muted py-4">
        <div class="spinner-border" role="status" aria-hidden="true"></div>
        <div class="mt-2">Carregando…</div>
      </div>`;
    try{
      const r = await fetch(EMBED_URL, { cache:'no-store', credentials:'same-origin', headers:{'X-Fragment':'1'} });
      body.innerHTML = await r.text();
    }catch(e){
      body.innerHTML = `<div class="alert alert-danger">Falha ao carregar.</div>`;
    }
  }

  async function refreshBadge(){
    const badge = document.getElementById('fiscalBadge');
    if (!badge) return;
    try{
      const r = await fetch(COUNT_URL, { cache:'no-store', credentials:'same-origin' });
      const j = await r.json().catch(()=>({}));
      const n = parseInt(j && j.count,10) || 0;
      badge.textContent = n > 99 ? '99+' : (n||'');
      badge.style.display = n > 0 ? 'inline-block' : 'none';
    }catch(e){}
  }

  // === Toggle genérico usado por Fiscal e Coordenador ===
  function toggleDetailsGeneric(btn){
    // tenta por data-target
    let sel = btn.getAttribute('data-target');
    if (sel && !sel.startsWith('#')) sel = '#'+sel;
    let row = sel ? document.querySelector(sel) : null;

    // senão, usa a próxima TR com id prefixado cr/fi
    if (!row){
      const tr = btn.closest('tr');
      if (tr && tr.nextElementSibling && tr.nextElementSibling.id &&
          (tr.nextElementSibling.id.startsWith('fi-changes-') || tr.nextElementSibling.id.startsWith('cr-changes-'))) {
        row = tr.nextElementSibling;
      }
    }
    if (!row) return;

    const hidden = row.classList.contains('d-none') || row.style.display === 'none';
    if (hidden){
      row.classList.remove('d-none');
      if (row.tagName === 'TR') row.style.display = 'table-row';
      btn.setAttribute('aria-expanded','true');
      btn.classList.remove('btn-outline-primary');
      btn.classList.add('btn-primary');
    }else{
      if (row.tagName === 'TR') row.style.display = '';
      row.classList.add('d-none');
      btn.setAttribute('aria-expanded','false');
      btn.classList.remove('btn-primary');
      btn.classList.add('btn-outline-primary');
    }
  }

  // expõe global para o topbar usar no modal do Coordenador
  window.__cohToggleAlteracoes = toggleDetailsGeneric;

  async function dismissItem(id){
    const body = document.querySelector(BODY_SEL);
    try{
      const fd = new URLSearchParams();
      fd.append('action','dismiss');
      fd.append('id', String(id));
      const r = await fetch('/php/fiscal_inbox.php', {
        method:'POST',
        body: fd,
        credentials:'same-origin'
      });
      const j = await r.json().catch(()=>({}));
      if (j && j.ok) {
        await loadEmbed();
        await refreshBadge();
        flash(body, 'Item removido da sua inbox. O registro permanece no histórico.', true);
      } else {
        flash(body, 'Não foi possível remover: '+(j.error||'erro'), false);
      }
    }catch(e){
      flash(body, 'Falha de comunicação ao remover.', false);
    }
  }

  function flash(scope, text, ok){
    if (!scope) scope = document.body;
    const div = document.createElement('div');
    div.className = `alert ${ok?'alert-success':'alert-danger'} my-2`;
    div.textContent = text;
    scope.prepend(div);
    setTimeout(()=>div.remove(), 2500);
  }

  function wire(){
    // abrir modal
    document.addEventListener('click', (ev)=>{
      const btn = ev.target.closest('[data-open-fiscal-inbox]');
      if (!btn) return;
      ensureModal();
      const el = document.getElementById(MODAL_ID);
      el.addEventListener('show.bs.modal', ()=>{ loadEmbed(); }, { once:true });
      bootstrap.Modal.getOrCreateInstance(el).show();
    });

    // eventos dentro do modal do Fiscal
    document.addEventListener('click', (ev)=>{
      const modal = document.getElementById(MODAL_ID);
      if (!modal) return;
      if (!modal.contains(ev.target)) return;

      const toggleBtn = ev.target.closest('.js-ver-alteracoes');
      if (toggleBtn){ ev.preventDefault(); toggleDetailsGeneric(toggleBtn); return; }

      const delBtn = ev.target.closest('.js-dismiss');
      if (delBtn){
        ev.preventDefault();
        const id = delBtn.getAttribute('data-id');
        if (id) dismissItem(id);
        return;
      }
    });

    document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) refreshBadge(); });
    setInterval(refreshBadge, 30000);
    refreshBadge();
  }

  document.addEventListener('DOMContentLoaded', function(){
    ensureModal();
    wire();
  });
})();
