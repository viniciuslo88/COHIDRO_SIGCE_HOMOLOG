// form_contratos.js — Máscaras, destaques, drafts, saldo, fiscais, workflow e ações
(function () {
  "use strict";

  // =========================
  // Utils
  // =========================
  function onDomReady(fn) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", fn);
    else fn();
  }

  function escapeHtml(s) {
    return String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  function decodeHtmlEntities(str) {
    if (str == null) return "";
    const t = document.createElement("textarea");
    t.innerHTML = String(str);
    return t.value;
  }

  function safeJsonParse(str, fallback) {
    try {
      const s = decodeHtmlEntities(str);
      if (!s) return fallback;
      const j = JSON.parse(s);
      return j == null ? fallback : j;
    } catch (e) {
      return fallback;
    }
  }

  function ensureCOH() {
    if (!window.COH) window.COH = {};
    if (!window.COH.draft) window.COH.draft = { medicoes: [], aditivos: [], reajustes: [] };
    return window.COH;
  }

  function getPageData() {
    const el = document.getElementById("coh-page-data");
    if (!el) {
      return {
        draft: { medicoes: [], aditivos: [], reajustes: [] },
        changed: [],
        reviewId: 0,
        canEdit: false,
        canReq: false,
      };
    }
    return {
      draft: safeJsonParse(el.getAttribute("data-draft"), { medicoes: [], aditivos: [], reajustes: [] }),
      changed: safeJsonParse(el.getAttribute("data-changed"), []),
      reviewId: parseInt(el.getAttribute("data-review-id") || "0", 10) || 0,
      canEdit: (el.getAttribute("data-can-edit") || "0") === "1",
      canReq: (el.getAttribute("data-can-req") || "0") === "1",
    };
  }

  // =========================
  // [PASSO 2] Bootstrapping do workflow (draft + revisão + permissões)
  // =========================
  function initWorkflowFromPageData() {
    const COH = ensureCOH();
    const pd = getPageData();

    COH.draft = pd.draft || { medicoes: [], aditivos: [], reajustes: [] };
    COH.changedFields = Array.isArray(pd.changed) ? pd.changed : [];
    COH.reviewId = pd.reviewId || 0;
    COH.canEditImmediately = !!pd.canEdit;
    COH.canRequestApproval = !!pd.canReq;

    return pd;
  }

  function ensureHiddenJsonInputs(form) {
    const ids = ["novas_medicoes_json", "novos_aditivos_json", "novos_reajustes_json"];
    ids.forEach((id) => {
      if (!form.querySelector('input[name="' + id + '"]')) {
        const inp = document.createElement("input");
        inp.type = "hidden";
        inp.name = id;
        inp.id = id;
        inp.value = "[]";
        form.appendChild(inp);
      }
    });
  }

  function cohForceSync() {
    const COH = ensureCOH();
    const D = COH.draft || { medicoes: [], aditivos: [], reajustes: [] };

    const iA = document.getElementById("novos_aditivos_json");
    const iM = document.getElementById("novas_medicoes_json");
    const iR = document.getElementById("novos_reajustes_json");

    if (iA) iA.value = JSON.stringify(D.aditivos || []);
    if (iM) iM.value = JSON.stringify(D.medicoes || []);
    if (iR) iR.value = JSON.stringify(D.reajustes || []);
  }
  window.cohForceSync = cohForceSync;

  function applyReviewMode(form, reviewId) {
    if (!form || !reviewId) return;

    // injeta hidden review_id (equivalente ao script inline antigo)
    if (!form.querySelector('input[name="review_id"]')) {
      const i = document.createElement("input");
      i.type = "hidden";
      i.name = "review_id";
      i.value = String(reviewId);
      form.appendChild(i);
    }

    // alerta visual fixo
    if (!document.getElementById("coh-review-banner")) {
      const alertDiv = document.createElement("div");
      alertDiv.id = "coh-review-banner";
      alertDiv.className = "alert alert-warning fixed-bottom m-3 shadow";
      alertDiv.innerHTML = "<strong>Modo de Revisão:</strong> Você está corrigindo a solicitação #" + reviewId + ".";
      document.body.appendChild(alertDiv);
    }
  }

  function highlightRevisionFields(changedFields) {
    if (!Array.isArray(changedFields) || !changedFields.length) return;

    const touched = [];
    changedFields.forEach((fieldName) => {
      if (!fieldName) return;
      const el = document.querySelector('[name="' + CSS.escape(String(fieldName)) + '"]');
      if (!el) return;

      // estilo visual igual ao que você tinha inline
      el.style.border = "2px solid #dc3545";
      el.style.backgroundColor = "#fff8f8";
      el.setAttribute("title", "DADO ANTERIOR (RECUSADO). Por favor, corrija.");

      const wrap = el.closest(".col-md-3,.col-md-4,.col-md-6,.col-12,.col-md-12");
      const label = wrap ? wrap.querySelector(".coh-label") : null;
      if (label && !label.innerHTML.includes("(Revisar)")) {
        label.innerHTML += ' <span class="text-danger small fw-bold">(Revisar)</span>';
      }

      touched.push(el);
    });

    // rola até o primeiro campo marcado
    if (touched.length) {
      for (let i = 0; i < touched.length; i++) {
        const el = touched[i];
        if (el && typeof el.scrollIntoView === "function") {
          el.scrollIntoView({ behavior: "smooth", block: "center" });
          break;
        }
      }
    }
  }

  function configureSaveButton(form, canEditImmediately, canRequestApproval) {
    if (!form) return;

    // input hidden action
    let hidden = form.querySelector('input[name="action"]');
    if (!hidden) {
      hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = "action";
      form.appendChild(hidden);
    }

    const btnSalvar = document.getElementById("btnSalvarContrato");
    if (!btnSalvar) return;

    const handler = function (e, actionType) {
      if (e) e.preventDefault();
      cohForceSync();

      hidden.value = actionType;
      form.submit();
    };

    if (canEditImmediately) {
      btnSalvar.innerText = "Salvar Alterações";
      btnSalvar.classList.remove("d-none");
      btnSalvar.addEventListener("click", (e) => handler(e, "salvar"));
    } else if (canRequestApproval) {
      btnSalvar.innerText = "Solicitar Aprovação";
      btnSalvar.classList.replace("btn-success", "btn-primary");
      btnSalvar.classList.remove("d-none");
      btnSalvar.addEventListener("click", (e) => handler(e, "solicitar_aprovacao"));
    } else {
      btnSalvar.remove();
    }

    // garantia extra (enter/submit manual)
    form.addEventListener("submit", () => cohForceSync());
  }

  function repositionLastChangeCard() {
    const secMed = document.getElementById("sec-med");
    const lastChange = document.getElementById("contrato-last-change");
    if (secMed && lastChange) {
      secMed.insertAdjacentElement("afterend", lastChange);
      lastChange.classList.remove("d-none");
    }
  }

  // =========================
  // Máscara data dd/mm/yyyy
  // =========================
  function wireDateMasks(root) {
    (root || document).querySelectorAll(".date-br").forEach((inp) => {
      if (inp.__cohWiredDate) return;
      inp.__cohWiredDate = true;

      inp.addEventListener("input", function () {
        let v = this.value.replace(/\D/g, "").slice(0, 8);
        if (v.length >= 5) this.value = v.replace(/(\d{2})(\d{2})(\d{1,4})/, "$1/$2/$3");
        else if (v.length >= 3) this.value = v.replace(/(\d{2})(\d{1,2})/, "$1/$2");
        else this.value = v;
      });
    });
  }

  // =========================
  // Máscara BRL (classe .brl)
  // =========================
  function wireBrlMasks(root) {
    (root || document).querySelectorAll(".brl").forEach((inp) => {
      if (inp.__cohWiredBrl) return;
      inp.__cohWiredBrl = true;

      inp.addEventListener("input", function () {
        let v = this.value.replace(/[^0-9]/g, "");
        if (!v) {
          this.value = "";
          return;
        }
        while (v.length < 3) v = "0" + v;
        let cents = v.slice(-2),
          intp = v.slice(0, -2);
        intp = intp.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        this.value = "R$ " + intp + "," + cents;
      });

      inp.addEventListener("blur", () => {
        if (inp.value.trim() === "") inp.value = "";
      });
    });
  }

  // =========================
  // Máscara percentual (classe .perc)
  // =========================
  function wirePercMasks(root) {
    (root || document).querySelectorAll(".perc").forEach((inp) => {
      if (inp.__cohWiredPerc) return;
      inp.__cohWiredPerc = true;

      inp.addEventListener("input", () => {
        let v = inp.value.replace(/[^0-9,]/g, "");
        inp.value = v ? v.replace(/,+/g, ",") + "%" : "";
      });

      inp.addEventListener("blur", () => {
        if (inp.value && !/%$/.test(inp.value)) inp.value = inp.value + "%";
      });
    });
  }

  // =========================
  // Destaque de campos alterados (data-orig)
  // =========================
  function normalize(v) {
    return (v ?? "").toString().trim();
  }

  function toggleChanged(input) {
    const orig = normalize(input.getAttribute("data-orig"));
    const cur = normalize(input.value);

    const wrap = input.closest(".col-md-3,.col-md-4,.col-md-6,.col-12,.col-md-12");
    const label = wrap ? wrap.querySelector(".coh-label") : null;

    const changed = cur !== orig;

    input.classList.toggle("coh-changed", changed);
    if (label) label.classList.toggle("changed", changed);
  }

  function wireChangedHighlights(container) {
    const sel = "input.form-control, textarea.form-control, select.form-select";
    (container || document).querySelectorAll(sel).forEach((el) => {
      if (!el.hasAttribute("data-orig")) return;
      if (el.__cohWiredChanged) return;
      el.__cohWiredChanged = true;

      toggleChanged(el);
      el.addEventListener("input", () => toggleChanged(el));
      el.addEventListener("change", () => toggleChanged(el));
    });
  }

  // =========================
  // Modal de Adição de Medições (local)
  // =========================
  function wireMedicaoModalLocal() {
    const modalEl = document.getElementById("modalAddMedicaoCols");
    const mainForm = document.getElementById("coh-form");
    let newMedIndex = 0;

    const addBtn = document.querySelector('[data-bs-target="#modalAddMedicaoCols"]');
    if (addBtn && window.bootstrap && modalEl) {
      addBtn.addEventListener("click", () => {
        const inst = bootstrap.Modal.getOrCreateInstance(modalEl);
        inst.show();
      });
    }

    const btnAddLocal = document.getElementById("coh-btn-add-med-local");
    if (btnAddLocal && modalEl && mainForm) {
      btnAddLocal.addEventListener("click", () => {
        const medData = modalEl.querySelector('[name="med_data"]');
        const medValorLiq = modalEl.querySelector('[name="med_valor_liq"]');
        const medLiqAcum = modalEl.querySelector('[name="med_liq_acum"]');
        const medPerc = modalEl.querySelector('[name="med_perc"]');
        const medObs = modalEl.querySelector('[name="med_obs"]');

        if (!medData || !medData.value) {
          alert("Por favor, informe a Data.");
          medData && medData.focus();
          return;
        }

        const hoje = new Date().toISOString().split("T")[0];
        if (medData.value > hoje) {
          alert("A data da medição não pode ser no futuro.");
          medData.focus();
          return;
        }

        const fields = {
          data: medData.value,
          valor_liq: medValorLiq ? medValorLiq.value : "",
          liq_acum: medLiqAcum ? medLiqAcum.value : "",
          perc: medPerc ? medPerc.value : "",
          obs: medObs ? medObs.value : "",
        };

        for (const key in fields) {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = `new_med[${newMedIndex}][${key}]`;
          input.value = fields[key];
          mainForm.appendChild(input);
        }
        newMedIndex++;

        const container = document.getElementById("coh-medicoes-container");
        const noneAlert = document.getElementById("coh-medicoes-none");
        let tbody = document.getElementById("coh-medicoes-tbody");

        const dataBr = medData.value.split("-").reverse().join("/");
        const valorBr = (medValorLiq && medValorLiq.value) || "R$ 0,00";
        const acumBr = (medLiqAcum && medLiqAcum.value) || "R$ 0,00";
        const percBr = (medPerc && medPerc.value) || "0,00%";
        const obsHtml = escapeHtml((medObs && medObs.value) || "");

        if (!tbody && container) {
          if (noneAlert) noneAlert.remove();
          container.innerHTML = `
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>#</th><th>Data</th><th>Valor (R$)</th><th>Acumulado (R$)</th><th>%</th><th>Obs</th><th class="text-center" style="width:110px">Ações</th>
                  </tr>
                </thead>
                <tbody id="coh-medicoes-tbody"></tbody>
              </table>
            </div>`;
          tbody = document.getElementById("coh-medicoes-tbody");
        }

        if (tbody) {
          const rowCount = tbody.rows.length;
          const newRow = tbody.insertRow();
          newRow.innerHTML = `
            <td>${rowCount + 1}</td>
            <td>${dataBr}</td>
            <td>${valorBr}</td>
            <td>${acumBr}</td>
            <td>${percBr}</td>
            <td>${obsHtml}</td>
            <td class="text-center"><small>(Salvar p/ excluir)</small></td>`;
        }

        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) modalInstance.hide();
      });
    }
  }

  // =========================
  // Saldo / BRL / Percentuais
  // =========================
  function parseBRL(str) {
    if (!str) return NaN;
    str = String(str).replace(/[^\d.,-]/g, "").trim();
    if (!str) return NaN;

    if (str.includes(",")) str = str.replace(/\./g, "").replace(",", ".");
    else {
      const parts = str.split(".");
      if (parts.length > 2) str = parts.slice(0, -1).join("") + "." + parts.at(-1);
    }
    const n = parseFloat(str);
    return isNaN(n) ? NaN : n;
  }

  function formatBRL(num) {
    if (typeof num !== "number" || !isFinite(num)) return "";
    const sign = num < 0 ? "-" : "";
    num = Math.abs(num);
    const fixed = num.toFixed(2);
    let [intPart, decPart] = fixed.split(".");
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    return sign + intPart + "," + decPart;
  }

  function atualizarPercentuaisSaldo() {
    const totalInp = document.querySelector('input[name="Valor_Total_Atualizado_Contrato"]');
    const totalNum = parseBRL(totalInp ? totalInp.value : "");
    const percEls = document.querySelectorAll(".coh-saldo-perc");

    if (!totalInp || isNaN(totalNum) || totalNum <= 0) {
      percEls.forEach((el) => (el.textContent = ""));
      return;
    }

    function setPerc(inputName, key) {
      const inp = document.querySelector('input[name="' + inputName + '"]');
      const span = document.querySelector('.coh-saldo-perc[data-coh-perc="' + key + '"]');
      if (!span) return;

      const val = parseBRL(inp ? inp.value : "");
      if (isNaN(val) || val <= 0) {
        span.textContent = "";
        return;
      }
      span.textContent = ((val / totalNum) * 100).toFixed(2).replace(".", ",") + "% do contrato";
    }

    setPerc("Medicao_Anterior_Acumulada_RS", "anterior");
    setPerc("Valor_Liquidado_Na_Medicao_RS", "medicao");
    setPerc("Valor_Liquidado_Acumulado", "acumulado");
  }

  function atualizarValorTotalContrato() {
    const outTotal = document.querySelector('input[name="Valor_Total_Atualizado_Contrato"]');
    const outSaldo = document.querySelector('input[name="Saldo_Atualizado_Contrato"]');
    const inpLiqAcum = document.querySelector('input[name="Valor_Liquidado_Acumulado"]');
    if (!outTotal) return;

    const inpValorInicial = document.querySelector('input[name="Valor_Do_Contrato"]');
    let baseVal = parseBRL(outTotal.defaultValue);
    if (isNaN(baseVal)) baseVal = parseBRL(inpValorInicial ? inpValorInicial.value : 0);

    let currentTotal = baseVal;

    // drafts locais (aditivos/reajustes) se existirem
    if (window.COH && window.COH.draft) {
      const ads = window.COH.draft.aditivos || [];
      const rjs = window.COH.draft.reajustes || [];
      let lastA = NaN,
        lastR = NaN;

      if (ads.length) lastA = parseBRL((ads[ads.length - 1] || {}).valor_total_apos_aditivo);
      if (rjs.length) lastR = parseBRL((rjs[rjs.length - 1] || {}).valor_total_apos_reajuste);

      if (!isNaN(lastR)) currentTotal = lastR;
      else if (!isNaN(lastA)) currentTotal = lastA;
    }

    outTotal.value = formatBRL(currentTotal);

    if (outSaldo) {
      let vLiq = parseBRL(inpLiqAcum ? inpLiqAcum.value : 0);
      if (isNaN(vLiq)) vLiq = 0;
      outSaldo.value = formatBRL(currentTotal - vLiq);
    }

    atualizarPercentuaisSaldo();
  }

  function atualizarSaldoPorMedicoes() {
    const secMed = document.getElementById("sec-med");
    if (!secMed) return;

    const table = secMed.querySelector("table");
    const tbody = table ? table.querySelector("tbody") : null;
    if (!table || !tbody) return;

    const rows = tbody.querySelectorAll("tr");
    if (!rows.length) return;

    const headCells = table.querySelectorAll("thead tr th");
    let idxValor = -1,
      idxAcum = -1,
      idxPerc = -1;

    headCells.forEach((th, idx) => {
      const txt = th.textContent.toLowerCase();
      if (idxValor === -1 && txt.includes("valor") && txt.includes("r$")) idxValor = idx;
      if (idxAcum === -1 && (txt.includes("acumulado") || txt.includes("acum."))) idxAcum = idx;
      if (idxPerc === -1 && (txt.includes("%") || txt.includes("percent"))) idxPerc = idx;
    });

    if (idxValor === -1) idxValor = 1;
    if (idxAcum === -1) idxAcum = 2;
    if (idxPerc === -1) idxPerc = 3;

    const lastRow = rows[rows.length - 1];
    const tds = lastRow.querySelectorAll("td");
    if (!tds.length) return;

    const txtValor = (tds[idxValor]?.textContent || "").trim();
    const txtAcum = (tds[idxAcum]?.textContent || "").trim();
    const txtPerc = (tds[idxPerc]?.textContent || "").trim();

    const vValor = parseBRL(txtValor);
    const vAcum = parseBRL(txtAcum);
    const vAnt = !isNaN(vValor) && !isNaN(vAcum) ? vAcum - vValor : NaN;

    const inpAnt = document.querySelector('input[name="Medicao_Anterior_Acumulada_RS"]');
    const inpVal = document.querySelector('input[name="Valor_Liquidado_Na_Medicao_RS"]');
    const inpAcum = document.querySelector('input[name="Valor_Liquidado_Acumulado"]');
    const inpPerc = document.querySelector('input[name="Percentual_Executado"]');

    if (inpVal && txtValor) inpVal.value = txtValor;
    if (inpAcum && txtAcum) inpAcum.value = txtAcum;
    if (inpAnt && !isNaN(vAnt)) inpAnt.value = formatBRL(vAnt);
    if (inpPerc && txtPerc) inpPerc.value = txtPerc;

    atualizarValorTotalContrato();
  }

  function wireSaldoContrato() {
    atualizarSaldoPorMedicoes();
    atualizarValorTotalContrato();
    atualizarPercentuaisSaldo();

    // Ajuste específico do Valor_Do_Contrato (campo sem classe .brl)
    const inpValorContrato = document.querySelector('input[name="Valor_Do_Contrato"]');
    if (inpValorContrato && !inpValorContrato.__cohWiredValorContrato) {
      inpValorContrato.__cohWiredValorContrato = true;

      const iniNum = parseBRL(inpValorContrato.value);
      if (!isNaN(iniNum) && iniNum > 0) inpValorContrato.value = "R$ " + formatBRL(iniNum);

      inpValorContrato.addEventListener("focus", function () {
        let v = this.value || "";
        v = v.replace(/\s/g, "").replace(/^R\$\s?/, "").replace(/\./g, "");
        this.value = v;
      });

      inpValorContrato.addEventListener("blur", function () {
        const n = parseBRL(this.value);
        if (isNaN(n) || !this.value.trim()) {
          this.value = "";
          atualizarValorTotalContrato();
          return;
        }
        this.value = "R$ " + formatBRL(n);
        atualizarValorTotalContrato();
      });
    }

    // Observa mudanças na tabela de medições
    const secMed = document.getElementById("sec-med");
    const tbody = secMed ? secMed.querySelector("table tbody") : null;
    if (tbody && "MutationObserver" in window && !tbody.__cohWiredObserver) {
      tbody.__cohWiredObserver = true;
      const obs = new MutationObserver(atualizarSaldoPorMedicoes);
      obs.observe(tbody, { childList: true, subtree: false });
    }

    // Mudanças em hidden json (quando você usa drafts)
    const jsonHiddenMed = document.getElementById("novas_medicoes_json");
    if (jsonHiddenMed && !jsonHiddenMed.__cohWiredMedJson) {
      jsonHiddenMed.__cohWiredMedJson = true;
      ["change", "input"].forEach((ev) => jsonHiddenMed.addEventListener(ev, atualizarSaldoPorMedicoes));
    }

    const jsonHiddenAdit = document.getElementById("novos_aditivos_json");
    if (jsonHiddenAdit && !jsonHiddenAdit.__cohWiredAditJson) {
      jsonHiddenAdit.__cohWiredAditJson = true;
      ["change", "input"].forEach((ev) => jsonHiddenAdit.addEventListener(ev, atualizarValorTotalContrato));
    }

    const jsonHiddenReaj = document.getElementById("novos_reajustes_json");
    if (jsonHiddenReaj && !jsonHiddenReaj.__cohWiredReajJson) {
      jsonHiddenReaj.__cohWiredReajJson = true;
      ["change", "input"].forEach((ev) => jsonHiddenReaj.addEventListener(ev, atualizarValorTotalContrato));
    }

    // Recalcula quando mexer nos inputs do saldo
    ["Valor_Do_Contrato", "Valor_Liquidado_Acumulado", "Medicao_Anterior_Acumulada_RS", "Valor_Liquidado_Na_Medicao_RS"].forEach((name) => {
      const el = document.querySelector('input[name="' + name + '"]');
      if (!el) return;
      if (el.__cohWiredSaldoInputs) return;
      el.__cohWiredSaldoInputs = true;
      ["change", "input", "blur"].forEach((ev) => el.addEventListener(ev, atualizarValorTotalContrato));
    });

    window.cohAtualizarSaldoPorMedicoes = atualizarSaldoPorMedicoes;
    window.cohAtualizarValorTotalContrato = atualizarValorTotalContrato;
  }

  // =========================
  // Draft helpers (render + add)
  // =========================
  function renderDraftSafe() {
    const COH = ensureCOH();
    if (typeof window.cohRenderDraft !== "function") return;

    try {
      window.cohRenderDraft("draft-list-medicoes", COH.draft.medicoes || []);
      window.cohRenderDraft("draft-list-aditivos", COH.draft.aditivos || []);
      window.cohRenderDraft("draft-list-reajustes", COH.draft.reajustes || []);
    } catch (e) {}
  }

  window.cohAddMedicao = function (p) {
    const COH = ensureCOH();
    const obj = Object.assign(
      { _label: "Medição " + (p.data_medicao || p.data || ""), _desc: "Valor: " + (p.valor_rs || p.valor || "") },
      p
    );
    COH.draft.medicoes = COH.draft.medicoes || [];
    COH.draft.medicoes.push(obj);
    renderDraftSafe();
    cohForceSync();
    wireSaldoContrato();
  };

  window.cohAddAditivo = function (p) {
    const COH = ensureCOH();
    const obj = Object.assign(
      { _label: "Aditivo " + (p.numero_aditivo || ""), _desc: "Valor: " + (p.valor_aditivo_total || "") },
      p
    );
    COH.draft.aditivos = COH.draft.aditivos || [];
    COH.draft.aditivos.push(obj);
    renderDraftSafe();
    cohForceSync();
    wireSaldoContrato();
  };

  window.cohAddReajuste = function (p) {
    const COH = ensureCOH();
    const obj = Object.assign(
      { _label: "Reajuste " + (p.data_base || p.indice || ""), _desc: "Perc: " + (p.percentual || "") },
      p
    );
    COH.draft.reajustes = COH.draft.reajustes || [];
    COH.draft.reajustes.push(obj);
    renderDraftSafe();
    cohForceSync();
    wireSaldoContrato();
  };

  // =========================
  // Timers 24h
  // =========================
  function wireTimers24h() {
    const timers = document.querySelectorAll(".timer-24h");
    if (!timers.length) return;

    setInterval(() => {
      timers.forEach((span) => {
        let seconds = parseInt(span.getAttribute("data-seconds") || "0", 10);
        if (seconds <= 0) {
          span.innerHTML = "<span class='text-danger fw-bold'>Tempo esgotado</span>";
          const btnGroup = span.closest("td")?.querySelector(".btn-group");
          if (btnGroup) btnGroup.remove();
          return;
        }
        seconds--;
        span.setAttribute("data-seconds", String(seconds));
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        span.textContent = `Restam: ${h < 10 ? "0" + h : h}:${m < 10 ? "0" + m : m}:${s < 10 ? "0" + s : s}`;
      });
    }, 1000);
  }

  // =========================
  // Delete / Edit itens do banco
  // =========================
  window.cohDeleteDbItem = function (tipo, id) {
    if (!confirm("ATENÇÃO: Você excluirá este registro do banco e os totais serão recalculados.\n\nContinuar?")) return;

    const fd = new FormData();
    fd.append("action", "delete_item");
    fd.append("type", tipo);
    fd.append("id", id);

    fetch("ajax/delete_contract_item.php", { method: "POST", body: fd })
      .then((r) => r.json())
      .then((d) => {
        if (d.success) {
          alert("Excluído com sucesso!");
          location.reload();
        } else {
          alert("Erro: " + (d.message || "Desconhecido"));
        }
      })
      .catch(() => alert("Erro de conexão."));
  };

  window.cohEditDbItem = function (tipo, item) {
    let modalId = "";
    const fmt = (v) =>
      typeof v === "number" ? v.toLocaleString("pt-BR", { minimumFractionDigits: 2 }) : v || "";

    if (tipo === "medicao") {
      modalId = "modalMedicao";
      const root = document.getElementById(modalId);
      if (root) {
        const inpData = root.querySelector('input[name="data_medicao"]');
        const inpValor = root.querySelector('input[name="valor_rs"]');
        const txtObs = root.querySelector('textarea[name="observacao"]');

        if (inpData) inpData.value = item.data_medicao ? String(item.data_medicao).split(" ")[0] : "";
        if (inpValor) inpValor.value = fmt(item.valor_rs);
        if (txtObs) txtObs.value = item.observacao || "";

        if (inpValor) inpValor.dispatchEvent(new Event("input", { bubbles: true }));
      }
    } else if (tipo === "aditivo") {
      modalId = "modalAditivo";
      const root = document.getElementById(modalId);
      if (root) {
        const inpNum = root.querySelector('input[name="numero_aditivo"]');
        const inpData = root.querySelector('input[name="data"]');
        const selTipo = root.querySelector('select[name="tipo"]');
        const inpValAd = root.querySelector('input[name="valor_aditivo_total"]');
        const inpValTot = root.querySelector('input[name="valor_total_apos_aditivo"]');
        const inpPrazo = root.querySelector('input[name="novo_prazo"]');
        const txtObs = root.querySelector('textarea[name="observacao"]');

        if (inpNum) inpNum.value = item.numero_aditivo || "";
        if (inpData) inpData.value = item.data || (item.created_at ? String(item.created_at).split(" ")[0] : "");
        if (selTipo) {
          selTipo.value = item.tipo || "";
          selTipo.dispatchEvent(new Event("change"));
        }
        if (inpValAd) inpValAd.value = fmt(item.valor_aditivo_total);
        if (inpValTot) inpValTot.value = fmt(item.valor_total_apos_aditivo);
        if (inpPrazo) inpPrazo.value = item.novo_prazo || "";
        if (txtObs) txtObs.value = item.observacao || "";

        if (inpValAd) inpValAd.dispatchEvent(new Event("input", { bubbles: true }));
      }
    } else if (tipo === "reajuste") {
      modalId = "modalReajuste";
      const root = document.getElementById(modalId);
      if (root) {
        const inpData = root.querySelector('input[name="data_base"]');
        const inpPerc = root.querySelector('input[name="percentual"]');
        const inpValTot = root.querySelector('input[name="valor_total_apos_reajuste"]');
        const txtObs = root.querySelector('textarea[name="observacao"]');

        if (inpData) inpData.value = item.data_base || "";
        if (inpPerc)
          inpPerc.value =
            item.percentual ||
            (item.reajustes_percentual ? String(item.reajustes_percentual).replace(".", ",") : "");
        if (inpValTot) inpValTot.value = fmt(item.valor_total_apos_reajuste);
        if (txtObs) txtObs.value = item.observacao || "";

        if (inpValTot) inpValTot.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    if (modalId && window.bootstrap) {
      const m = bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId));
      m.show();
      setTimeout(() => {
        alert("MODO DE EDIÇÃO:\n\n1. Ajuste os dados.\n2. Salve como NOVO.\n3. Exclua o item antigo da lista.");
      }, 300);
    }
  };

  // =========================
  // Fiscais (criar + renomear + extras)
  // =========================
  function normName(v) {
    return String(v || "").trim().replace(/\s+/g, " ");
  }

  function stripSelectedAttrs(html) {
    return String(html || "").replace(/\sselected(="selected")?/gi, "");
  }

  function getOptionsTemplateHtml() {
    const first = document.querySelector(".coh-fiscal-select");
    if (!first) return "";
    return stripSelectedAttrs(first.innerHTML);
  }

  async function createFiscalOnServer(nome) {
    const fd = new FormData();
    fd.append("nome", nome);

    const r = await fetch("/ajax/fiscal_create.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });

    const txt = await r.text();
    let j = null;
    try {
      j = JSON.parse(txt);
    } catch (e) {}

    if (!r.ok || !j || !j.ok) {
      const msg = j && j.message ? j.message : txt ? txt.slice(0, 200) : "HTTP " + r.status;
      throw new Error(msg);
    }
    return j.nome;
  }

  async function renameFiscalOnServer(payload) {
    const fd = new FormData();
    Object.keys(payload).forEach((k) => fd.append(k, payload[k]));

    const r = await fetch("/ajax/fiscal_rename.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });

    const txt = await r.text();
    let j = null;
    try {
      j = JSON.parse(txt);
    } catch (e) {}

    if (!r.ok || !j || !j.ok) {
      const msg = j && j.message ? j.message : txt ? txt.slice(0, 200) : "HTTP " + r.status;
      throw new Error(msg);
    }
    return j;
  }

  function showNewInput(selectEl, forceShow) {
    const row = selectEl.closest(".col-md-4, .coh-fiscal-extra-row, .col-12") || document;
    const inp = row.querySelector(".coh-fiscal-new-input");
    if (!inp) return null;

    const should = forceShow || selectEl.value === "__novo__";
    inp.classList.toggle("d-none", !should);
    if (should) setTimeout(() => inp.focus(), 0);
    return inp;
  }

  function ensureOption(sel, value) {
    value = normName(value);
    if (!value) return null;

    let opt = Array.from(sel.options).find((o) => normName(o.value) === value);
    if (opt) return opt;

    opt = document.createElement("option");
    opt.value = value;
    opt.textContent = value;

    const novoOpt = Array.from(sel.options).find((o) => o.value === "__novo__");
    sel.insertBefore(opt, novoOpt || null);
    return opt;
  }

  function addOptionToAll(value) {
    document.querySelectorAll(".coh-fiscal-select").forEach((s) => ensureOption(s, value));
  }

  function forceSelect(sel, value) {
    value = normName(value);
    const opt = ensureOption(sel, value);
    if (!opt) return;

    sel.value = value;
    opt.selected = true;

    requestAnimationFrame(() => {
      sel.value = value;
      sel.dispatchEvent(new Event("input", { bubbles: true }));
      sel.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  function makeExtraRow(optionsTemplateHtml) {
    const div = document.createElement("div");
    div.className = "coh-fiscal-extra-row d-flex gap-2 align-items-start";
    div.innerHTML = `
      <div class="flex-grow-1">
        <select class="form-select coh-fiscal-select" name="Fiscais_Extras[]" data-role="fiscal">
          ${optionsTemplateHtml}
        </select>
        <input class="form-control mt-2 d-none coh-fiscal-new-input" type="text" placeholder="Digite o nome do novo fiscal…">
      </div>

      <div class="btn-group" role="group" aria-label="Ações do fiscal">
        <button type="button" class="btn btn-outline-secondary coh-fiscal-edit-btn" title="Editar nome do fiscal">
          <i class="bi bi-pencil"></i>
        </button>
        <button type="button" class="btn btn-outline-danger coh-fiscal-remove" title="Remover fiscal">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    `;
    return div;
  }

  async function promptRenameFromSelect(sel) {
    if (!sel) return;

    const oldName = normName(sel.value);
    if (!oldName || oldName === "__novo__") {
      alert("Selecione um fiscal para editar.");
      return;
    }

    const opt = sel.options[sel.selectedIndex];
    const fid = opt ? opt.getAttribute("data-id") || "" : "";

    const newName = normName(prompt("Editar nome do fiscal:", oldName) || "");
    if (!newName || newName === oldName) return;

    const resp = await renameFiscalOnServer({ id: fid, old_nome: oldName, new_nome: newName });
    const finalName = normName(resp.nome || newName);

    document.querySelectorAll(".coh-fiscal-select").forEach((s) => {
      Array.from(s.options).forEach((o) => {
        if (normName(o.value) === oldName) {
          o.value = finalName;
          o.textContent = finalName;
          if (resp.id) o.setAttribute("data-id", String(resp.id));
        }
      });

      if (normName(s.value) === oldName) forceSelect(s, finalName);
    });
  }

  function wireFiscais() {
    const optionsTemplateHtml = getOptionsTemplateHtml();
    if (!optionsTemplateHtml) return;

    document.addEventListener("change", (ev) => {
      const sel = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-select") : null;
      if (!sel) return;
      showNewInput(sel, false);
    });

    document.addEventListener("click", async (ev) => {
      const rm = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-remove") : null;
      if (rm) {
        rm.closest(".coh-fiscal-extra-row")?.remove();
        return;
      }

      const btnNew = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-new-btn") : null;
      if (btnNew) {
        const col = btnNew.closest(".col-md-4");
        const sel = col ? col.querySelector(".coh-fiscal-select") : null;
        if (!sel) return;
        sel.value = "__novo__";
        showNewInput(sel, true);
        return;
      }

      const btnEdit = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-edit-btn") : null;
      if (btnEdit) {
        const row = btnEdit.closest(".col-md-4, .coh-fiscal-extra-row, .col-12") || document;
        const sel = row.querySelector(".coh-fiscal-select");
        try {
          await promptRenameFromSelect(sel);
        } catch (e) {
          alert(e.message || "Não foi possível editar.");
        }
        return;
      }

      if (ev.target && ev.target.id === "coh-add-fiscal-extra") {
        const wrap = document.getElementById("coh-fiscais-extra-wrap");
        if (!wrap) return;
        wrap.appendChild(makeExtraRow(optionsTemplateHtml));
        return;
      }
    });

    document.addEventListener(
      "keydown",
      (ev) => {
        const inp = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-new-input") : null;
        if (inp && ev.key === "Enter") {
          ev.preventDefault();
          inp.blur();
          return;
        }

        const sel = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-select") : null;
        if (sel && (ev.key === "F2" || (ev.ctrlKey && (ev.key === "e" || ev.key === "E")))) {
          ev.preventDefault();
          promptRenameFromSelect(sel).catch((e) => alert(e.message || "Não foi possível editar."));
        }
      },
      true
    );

    document.addEventListener(
      "dblclick",
      (ev) => {
        const sel = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-select") : null;
        if (!sel) return;
        promptRenameFromSelect(sel).catch((e) => alert(e.message || "Não foi possível editar."));
      },
      true
    );

    document.addEventListener(
      "blur",
      async (ev) => {
        const inp = ev.target && ev.target.closest ? ev.target.closest(".coh-fiscal-new-input") : null;
        if (!inp) return;

        const row = inp.closest(".col-md-4, .coh-fiscal-extra-row");
        const sel = row ? row.querySelector(".coh-fiscal-select") : null;
        if (!sel || sel.value !== "__novo__") return;

        const nome = normName(inp.value);
        if (nome.length < 3) return;

        try {
          const saved = await createFiscalOnServer(nome);
          addOptionToAll(saved);
          forceSelect(sel, saved);

          inp.classList.add("d-none");
          inp.value = "";
        } catch (e) {
          alert(e.message || "Não foi possível cadastrar o fiscal.");
        }
      },
      true
    );
  }

  // =========================
  // Boot final
  // =========================
  onDomReady(() => {
    const pd = initWorkflowFromPageData();

    wireDateMasks(document);
    wireBrlMasks(document);
    wirePercMasks(document);

    const form = document.getElementById("coh-form") || document.querySelector('form[data-form="emop-contrato"]');
    if (form) {
      ensureHiddenJsonInputs(form);
      cohForceSync(); // já sincroniza ao carregar
      configureSaveButton(form, pd.canEdit, pd.canReq);
      if (pd.reviewId) applyReviewMode(form, pd.reviewId);
      wireChangedHighlights(form);
    }

    // renderiza drafts (se existir cohRenderDraft no seu projeto)
    renderDraftSafe();

    // revisões: pinta campos recusados
    highlightRevisionFields(pd.changed || []);

    wireMedicaoModalLocal();
    wireSaldoContrato();
    wireFiscais();

    repositionLastChangeCard();
    wireTimers24h();
  });
})();
