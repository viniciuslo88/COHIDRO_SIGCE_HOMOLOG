<!-- Modal global da Inbox do Coordenador (compatível com coordenador_inbox_global.js) -->
<div class="modal fade" id="coordenadorInboxModal" data-global-wire="1" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-clipboard-check me-2"></i> Solicitações para Aprovação
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="coordenadorInboxBody">
        <!-- conteúdo será carregado via JS -->
        <div class="text-center text-muted py-5">
          <div class="spinner-border" role="status" aria-hidden="true"></div>
          <div class="mt-2">Carregando…</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>