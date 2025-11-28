// /assets/js/coordenador_inbox_global.js
(function(){
  const MODAL_ID = 'coordenadorInboxModal';
  const MODAL_BODY_SEL = '#coordenadorInboxBody';
  const COUNT_URL = '/php/coordenador_inbox.php?mode=count';
  const EMBED_URL = '/php/coordenador_inbox.php?embed=1';
  const APPROVE_URL = '/php/coordenador_aprovar.php';
  const REJECT_URL  = '/php/coordenador_rejeitar.php';
  const REASON_MODAL_ID = 'cohRejectReasonModal';

  // --- util ---
  function qs(s, r){ return (r||document).querySelector(s); }
  function qsa(s, r){ return Array.from((r||document).querySelectorAll(s)); }
  function toast(msg){ try { console.debug('[coh-inbox]', msg); } catch(_){} }

  async function fetchJSON(url, opts){
    const r = await fetch(url, opts);
    const j = await r.json().catch(()=>({}));
    if (!r.ok || !j.ok) throw new Error(j.error || ('HTTP '+r.status));
    return j;
  }

  async function refreshBadge(){
    const badge = document.getElementById('coordBadge');
    if (!badge) return;
    try{
      const r = await fetch(COUNT_URL, { cache:'no-store', credentials:'same-origin' });
      const j = await r.json().catch(()=>({}));
      const n = parseInt(j && j.count,10) || 0;
      badge.textContent = n > 99 ? '99+' : (n||'');
      badge.style.display = n > 0 ? 'inline-block' : 'none';
    }catch(e){}
  }

  async function loadEmbed(){
    const body = qs(MODAL_BODY_SEL);
    if (!body) return;
    body.innerHTML = `
      <div class="text-center text-muted py-5">
        <div class="spinner-border" role="status" aria-hidden="true"></div>
        <div class="mt-2">Carregando…</div>
      </div>`;
    try{
      const r = await fetch(EMBED_URL, { cache:'no-store', credentials:'same-origin', headers:{'X-Fragment':'1'} });
      body.innerHTML = await r.text();
    }catch(e){
      body.innerHTML = `<div class="alert alert-danger">Falha ao carregar a Inbox do Coordenador.</div>`;
    }
  }

  // ---------- Mini-modal de motivo ----------
  function ensureReasonModal(){
    if (document.getElementById(REASON_MODAL_ID)) return;
    const tpl = `
    <div class="modal fade" id="${REASON_MODAL_ID}" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-x-octagon me-2"></i> Rejeitar solicitação</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="cohRejectReason" class="form-label">Motivo da rejeição <span class="text-danger">*</span></label>
              <textarea id="cohRejectReason" class="form-control" rows="4" placeholder="Descreva o motivo..."></textarea>
              <div class="invalid-feedback">Motivo é obrigatório.</div>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="cohRequestRevision">
              <label class="form-check-label" for="cohRequestRevision">Solicitar revisão ao Fiscal</label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="cohConfirmRejectBtn">Rejeitar</button>
          </div>
        </div>
      </div>
    </div>`;
    const wrap = document.createElement('div');
    wrap.innerHTML = tpl;
    document.body.appendChild(wrap.firstElementChild);

    // confirma
    qs('#cohConfirmRejectBtn').addEventListener('click', async ()=>{
      const modalEl = document.getElementById(REASON_MODAL_ID);
      const id  = modalEl?.dataset?.itemId;
      const txt = qs('#cohRejectReason');
      const chk = qs('#cohRequestRevision');
      const motivo = (txt.value || '').trim();
      if (!motivo){ txt.classList.add('is-invalid'); txt.focus(); return; }
      txt.classList.remove('is-invalid');
      await doReject(id, motivo, chk.checked ? 1 : 0);
      bootstrap.Modal.getInstance(modalEl).hide();
    });

    // limpar ao abrir
    document.getElementById(REASON_MODAL_ID).addEventListener('show.bs.modal', ()=>{
      const txt = qs('#cohRejectReason'); const chk = qs('#cohRequestRevision');
      if (txt){ txt.value=''; txt.classList.remove('is-invalid'); setTimeout(()=>txt.focus(),100); }
      if (chk){ chk.checked=false; }
    });
  }

  function openReasonModal(id){
    ensureReasonModal();
    const modalEl = document.getElementById(REASON_MODAL_ID);
    modalEl.dataset.itemId = id;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

  // ---------- Ações ----------
  async function doApprove(id){
    const fd = new URLSearchParams(); fd.append('id', id);
    await fetchJSON(APPROVE_URL, {
      method:'POST', body: fd, credentials:'same-origin',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }
    });
    await loadEmbed(); await refreshBadge();
    alert('Aprovado com sucesso.');
  }

  async function doReject(id, motivo, revisao){
    const fd = new URLSearchParams();
    fd.append('id', id); fd.append('motivo', motivo); fd.append('revisao', String(revisao));
    await fetchJSON(REJECT_URL, {
      method:'POST', body: fd, credentials:'same-origin',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }
    });
    await loadEmbed(); await refreshBadge();
    alert(revisao ? 'Rejeitado com solicitação de revisão.' : 'Rejeitado.');
  }

  // ---------- Modal de lista (global ou o da página) ----------
  function ensureListModalShell(){
    // Se a página já tem #coordenadorInboxModal (index), não criamos outro.
    if (document.getElementById(MODAL_ID)) return;
    // Criamos um casulo neutro para outras páginas
    const tpl = `
    <div class="modal fade" id="${MODAL_ID}" data-global-wire="1" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i> Solicitações para Aprovação</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
          </div>
          <div class="modal-body" id="coordenadorInboxBody"></div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
          </div>
        </div>
      </div>
    </div>`;
    const div = document.createElement('div');
    div.innerHTML = tpl;
    document.body.appendChild(div.firstElementChild);
  }

  // ---------- Delegação GLOBAL (pega em qualquer modal/lista) ----------
  function wireGlobal(){
    // abrir lista
    document.addEventListener('click', (ev)=>{
      const btn = ev.target.closest('[data-open-coordenador-inbox],[data-bs-target="#'+MODAL_ID+'"]');
      if (!btn) return;
      ensureListModalShell();
      const el = document.getElementById(MODAL_ID);
      el.addEventListener('show.bs.modal', ()=>{ loadEmbed(); }, { once:true });
      bootstrap.Modal.getOrCreateInstance(el).show();
    });

    // Ações aprovar/rejeitar — GLOBAL
    document.addEventListener('click', (ev)=>{
      const actionBtn = ev.target.closest('.js-acao,[data-action="approve"],[data-action="reject"],.aprovacao-approve,.aprovacao-reject,.btn-approve,.btn-reject,.js-approve,.js-reject');
      if (!actionBtn) return;

      // impede handlers antigos
      ev.preventDefault();
      ev.stopPropagation();
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();

      // pega ID
      let id = actionBtn.getAttribute('data-id')
            || actionBtn.closest('[data-inbox-item]')?.getAttribute('data-id')
            || actionBtn.closest('[data-inbox-item]')?.dataset?.id
            || actionBtn.getAttribute('data-item-id');

      if (!id){
        // fallback: hidden input
        const hid = actionBtn.closest('[data-inbox-item]')?.querySelector('input[name="id"],[data-id]');
        if (hid) id = hid.value || hid.getAttribute('data-id');
      }
      if (!id) return;

      // identifica ação
      const a = (actionBtn.getAttribute('data-action')
              || (actionBtn.classList.contains('aprovacao-approve') ? 'approve' : '')
              || (actionBtn.classList.contains('aprovacao-reject')  ? 'reject'  : '')
              || (actionBtn.classList.contains('js-approve')        ? 'approve' : '')
              || (actionBtn.classList.contains('js-reject')         ? 'reject'  : '')
              || (actionBtn.classList.contains('btn-approve')       ? 'approve' : '')
              || (actionBtn.classList.contains('btn-reject')        ? 'reject'  : '')).toLowerCase();

      if (a === 'approve'){
        if (!confirm('Confirmar aprovação desta solicitação?')) return;
        doApprove(id).catch(e=>alert('Erro: '+(e.message||e)));
        return;
      }

      if (a === 'reject'){
        // tenta mini-modal; se algo der errado, cai no prompt
        try {
          ensureReasonModal();
          openReasonModal(id);
        } catch(e) {
          let motivo = window.prompt('Informe o motivo da rejeição (obrigatório):','');
          if (motivo === null) return;
          motivo = (motivo||'').trim();
          if (!motivo){ alert('Motivo é obrigatório.'); return; }
          const pedirRevisao = window.confirm('Deseja solicitar REVISÃO ao Fiscal? (OK = sim, Cancelar = rejeitar sem revisão)');
          doReject(id, motivo, pedirRevisao?1:0).catch(err=>alert('Erro: '+(err.message||err)));
        }
        return;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    ensureListModalShell();
    ensureReasonModal();
    wireGlobal();
    refreshBadge();
    document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) refreshBadge(); });
    setInterval(refreshBadge, 30000);
    console.debug('[coh-inbox] coordenador_inbox_global.js carregado');
  });
})();
