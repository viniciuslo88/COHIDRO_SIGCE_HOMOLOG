// form_contratos_datas.js — validação e datepickers (Flatpickr com BR na tela e ISO no POST)
(function () {
  const SELECTORS = [
    'input[name="Assinatura_Do_Contrato_Data"]',
    'input[name="Data_Inicio"]',
    'input[name="Data_Fim_Prevista"]',
    'input[name="Data_Da_Medicao_Atual"]',
    'input[name="Inicio_Da_Suspensao"]',
    'input[name="Termino_Da_Suspensao"]'
  ];

  const SELECTOR_ALL = SELECTORS.join(',');

  function toISO(v) {
    const s = (v || '').trim();
    const m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    return m ? `${m[3]}-${m[2]}-${m[1]}` : s;
  }

  function okISO(s) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
    const d = new Date(s + 'T00:00:00');
    return !isNaN(d.getTime());
  }

  function getISOValue(el) {
    // Se flatpickr com altInput estiver ativo, o input original (hidden) guarda ISO
    const v = (el && el.value) ? String(el.value).trim() : '';
    if (okISO(v)) return v;
    return toISO(v);
  }

  function validate(root = document) {
    const di = root.querySelector('input[name="Data_Inicio"]');
    const df = root.querySelector('input[name="Data_Fim_Prevista"]');
    if (!di || !df) return;

    const a = getISOValue(di);
    const b = getISOValue(df);

    di.classList.remove('is-invalid');
    df.classList.remove('is-invalid');

    if (a && b && okISO(a) && okISO(b) && a > b) {
      di.classList.add('is-invalid');
      df.classList.add('is-invalid');
    }
  }

  function bindChangeValidation(root = document) {
    root.querySelectorAll(SELECTOR_ALL).forEach(el => {
      if (el.dataset.cohDateBound === '1') return;
      el.dataset.cohDateBound = '1';
      el.addEventListener('change', () => validate(document));
      el.addEventListener('blur', () => validate(document));
    });
  }

  function initFlatpickr(root = document) {
    if (typeof flatpickr === 'undefined') return;

    const ptLocale =
      (flatpickr.l10ns && (flatpickr.l10ns.pt || flatpickr.l10ns['pt'])) || {
        weekdays: { shorthand: ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] },
        months: { shorthand: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'] },
        firstDayOfWeek: 1
      };

    root.querySelectorAll(SELECTOR_ALL).forEach(el => {
      // evita inicializar 2x
      if (el._flatpickr) return;

      // Se veio com dd/mm/aaaa no value, converte para ISO antes de iniciar
      const v = (el.value || '').trim();
      if (v && !okISO(v)) {
        const iso = toISO(v);
        if (okISO(iso)) el.value = iso;
      }

      flatpickr(el, {
        // Visual BR + envio ISO
        altInput: true,
        altFormat: 'd/m/Y',
        dateFormat: 'Y-m-d',

        allowInput: true,
        disableMobile: true, // força o calendário do flatpickr também no mobile

        locale: ptLocale,

        onChange: function () { validate(document); },
        onClose: function () { validate(document); }
      });
    });
  }

  // Função global pra você chamar após inserir/atualizar trechos (partials, modais etc.)
  window.cohInitContratosDatas = function (root) {
    const r = root || document;
    bindChangeValidation(r);
    initFlatpickr(r);
    validate(document);
  };

  document.addEventListener('DOMContentLoaded', () => {
    window.cohInitContratosDatas(document);

    // Se os campos estiverem em modal, reinicializa ao abrir
    document.querySelectorAll('.modal').forEach(m => {
      m.addEventListener('shown.bs.modal', () => window.cohInitContratosDatas(m));
    });
  });
})();
