<?php
// /partials/modal_fiscal_inbox.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$role = (int)($_SESSION['role'] ?? 0);
if (!in_array($role, [1,6], true)) return; // só Fiscal ou Dev
?>
<div class="modal fade" id="fiscalInboxModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-inbox me-2"></i> Itens para revisão do Fiscal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="fiscalInboxBody" style="min-height:300px;">
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
  #fiscalInboxModal .modal-dialog.modal-xl { max-width: 1200px; }
  @media (min-width: 1400px){
    #fiscalInboxModal .modal-dialog.modal-xl { max-width: 1320px; }
  }
</style>
