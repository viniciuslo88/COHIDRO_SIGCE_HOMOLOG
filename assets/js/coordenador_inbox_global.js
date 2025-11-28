// /assets/js/coordenador_inbox_global.js
(function(){
  const MODAL_ID = 'coordenadorInboxModal';
  const MODAL_BODY_SEL = '#coordenadorInboxBody';
  const COUNT_URL = '/php/coordenador_inbox.php?mode=count';
  const EMBED_URL = '/php/coordenador_inbox.php?embed=1';
  const APPROVE_URL = '/php/coordenador_aprovar.php';
  const REJECT_URL  = '/php/coordenador_rejeitar.php';
  const REASON_MODAL_ID = 'cohRejectReasonModal';

  function qs(s, r){ return (r||document).querySelector(s); }

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

  // ---------- Modal de lista (cria casulo quando a página não tiver um) ----------
  function ensureListModalShell(){
    if (document.getElementById(MODAL_ID)) return;
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

  // ---------- util de toggle (Ver alterações) ----------
  function toggleRow(targetSelOrId){
    if (!targetSelOrId) return;
    const sel = targetSelOrId.startsWith('#') ? targetSelOrId : ('#' + targetSelOrId);
    const row = document.querySelector(sel);
    if (!row) return;
    const hidden = row.classList.contains('d-none');
    row.classList.toggle('d-none');
    return hidden;
  }

  // ---------- Delegação ----------
  function wireGlobal(){
    // abrir lista (não precisa capture aqui)
    document.addEventListener('click', (ev)=>{
      const btn = ev.target.closest('[data-open-coordenador-inbox],[data-bs-target="#'+MODAL_ID+'"]');
      if (!btn) return;
      ensureListModalShell();
      const el = document.getElementById(MODAL_ID);
      el.addEventListener('show.bs.modal', ()=>{ loadEmbed(); }, { once:true });
      bootstrap.Modal.getOrCreateInstance(el).show();
    });

    // Ver alterações — NÃO bloquear outros handlers
    document.addEventListener('click', (ev)=>{
      const tbtn = ev.target.closest('.js-ver-alteracoes, [data-toggle="row"], .js-toggle');
      if (!tbtn) return;
      const target = tbtn.getAttribute('data-target') || tbtn.getAttribute('data-row') || '';
      if (!target) return;
      ev.preventDefault();
      const becameVisible = toggleRow(target.replace(/^#?/, '#'));
      try{
        tbtn.classList.toggle('btn-primary', !!becameVisible);
        tbtn.classList.toggle('btn-outline-primary', !becameVisible);
        tbtn.setAttribute('aria-expanded', becameVisible ? 'true' : 'false');
      }catch(_){}
    });

    // Aprovar/Rejeitar — CAPTURE para bloquear confirm/listener antigos
    document.addEventListener('click', (ev)=>{
      const actionBtn = ev.target.closest('.js-acao,[data-action="approve"],[data-action="reject"],.aprovacao-approve,.aprovacao-reject,.btn-approve,.btn-reject,.js-approve,.js-reject');
      if (!actionBtn) return;

      // CAPTURE: cancela antigos (data-bs-toggle/modal etc.)
      ev.preventDefault();
      ev.stopPropagation();
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();

      // ID
      let id = actionBtn.getAttribute('data-id')
            || actionBtn.closest('[data-inbox-item]')?.getAttribute('data-id')
            || actionBtn.closest('[data-inbox-item]')?.dataset?.id
            || actionBtn.getAttribute('data-item-id');
      if (!id){
        const hid = actionBtn.closest('[data-inbox-item]')?.querySelector('input[name="id"],[data-id]');
        if (hid) id = hid.value || hid.getAttribute('data-id');
      }
      if (!id) return;

      // ação
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
        openReasonModal(id);
        return;
      }
    }, true); // CAPTURE só para approve/reject
  }

  document.addEventListener('DOMContentLoaded', function(){
    ensureListModalShell();
    ensureReasonModal();
    wireGlobal();
    refreshBadge();
    document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) refreshBadge(); });
    setInterval(refreshBadge, 30000);
  });
})();
