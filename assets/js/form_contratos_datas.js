// form_contratos_datas.js — validação e datepickers
(function(){
  const SELECTORS=[
    'input[name="Assinatura_Do_Contrato_Data"]',
    'input[name="Data_Inicio"]',
    'input[name="Data_Fim_Prevista"]',
    'input[name="Data_Da_Medicao_Atual"]',
    'input[name="Inicio_Da_Suspensao"]',
    'input[name="Termino_Da_Suspensao"]'
  ];

  function toISO(v){
    const s=(v||'').trim(),m=s.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    return m?`${m[3]}-${m[2]}-${m[1]}`:s;
  }

  function okISO(s){
    if(!/^\d{4}-\d{2}-\d{2}$/.test(s))return false;
    const d=new Date(s+'T00:00:00');
    return !isNaN(d.getTime());
  }

  function validate(){
    const di=document.querySelector('input[name="Data_Inicio"]');
    const df=document.querySelector('input[name="Data_Fim_Prevista"]');
    if(!di||!df)return;
    const a=toISO(di.value),b=toISO(df.value);
    di.classList.remove('is-invalid');
    df.classList.remove('is-invalid');
    if(a&&b&&a>b){
      di.classList.add('is-invalid');
      df.classList.add('is-invalid');
    }
  }

  document.addEventListener('DOMContentLoaded',()=>{
    SELECTORS.forEach(sel=>{
      document.querySelectorAll(sel).forEach(el=>{
        el.addEventListener('change',validate);
      });
    });

    // Fallback datepicker
    if(typeof flatpickr!=='undefined'){
      document.querySelectorAll(SELECTORS.join(',')).forEach(el=>{
        flatpickr(el,{
          dateFormat:'Y-m-d',
          allowInput:true,
          locale:{
            weekdays:{shorthand:['Dom','Seg','Ter','Qua','Qui','Sex','Sáb']},
            months:{shorthand:['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']},
            firstDayOfWeek:1
          }
        });
      });
    }
  });
})();
