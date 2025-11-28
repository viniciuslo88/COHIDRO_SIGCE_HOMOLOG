// form_contratos.js — Máscaras, destaques e medições locais
(function () {

  // ===== Máscara data dd/mm/yyyy =====
  document.querySelectorAll('.date-br').forEach(inp => {
    inp.addEventListener('input', function(){
      let v=this.value.replace(/\D/g,'').slice(0,8);
      if(v.length>=5)this.value=v.replace(/(\d{2})(\d{2})(\d{1,4})/,'$1/$2/$3');
      else if(v.length>=3)this.value=v.replace(/(\d{2})(\d{1,2})/,'$1/$2');
      else this.value=v;
    });
  });

  // ===== Máscara BRL =====
  document.querySelectorAll('.brl').forEach(inp=>{
    inp.addEventListener('input',function(){
      let v=this.value.replace(/[^0-9]/g,'');
      if(!v){this.value='';return;}
      while(v.length<3)v='0'+v;
      let cents=v.slice(-2),intp=v.slice(0,-2);
      intp=intp.replace(/\B(?=(\d{3})+(?!\d))/g,'.');
      this.value='R$ '+intp+','+cents;
    });
    inp.addEventListener('blur',()=>{if(inp.value.trim()==='')inp.value='';});
  });

  // ===== Máscara percentual =====
  document.querySelectorAll('.perc').forEach(inp=>{
    inp.addEventListener('input',()=>{
      let v=inp.value.replace(/[^0-9,]/g,'');
      inp.value=v?(v.replace(/,+/g,',')+'%'):'';
    });
    inp.addEventListener('blur',()=>{
      if(inp.value&&!/%$/.test(inp.value))inp.value=inp.value+'%';
    });
  });

  // ===== Destaque de campos alterados =====
  function normalize(v){return(v??'').toString().trim();}
  function toggleChanged(input){
    const orig=normalize(input.getAttribute('data-orig')),cur=normalize(input.value);
    const wrap=input.closest('.col-md-3,.col-md-4,.col-md-6,.col-12,.col-md-12');
    const label=wrap?wrap.querySelector('.coh-label'):null;
    const changed=(cur!==orig);
    input.classList.toggle('coh-changed',changed);
    if(label)label.classList.toggle('changed',changed);
  }
  function wire(container){
    const sel='input.form-control, textarea.form-control, select.form-select';
    container.querySelectorAll(sel).forEach(el=>{
      if(!el.hasAttribute('data-orig'))return;
      toggleChanged(el);
      el.addEventListener('input',()=>toggleChanged(el));
      el.addEventListener('change',()=>toggleChanged(el));
    });
  }

  document.addEventListener('DOMContentLoaded',()=>{
    const form=document.getElementById('coh-form');
    if(form)wire(form);
  });

  // ===== Modal de Adição de Medições =====
  document.addEventListener('DOMContentLoaded',()=>{
    const modalEl=document.getElementById('modalAddMedicaoCols');
    const mainForm=document.getElementById('coh-form');
    let newMedIndex=0;

    const addBtn=document.querySelector('[data-bs-target="#modalAddMedicaoCols"]');
    if(addBtn&&window.bootstrap&&modalEl){
      addBtn.addEventListener('click',()=>{
        const inst=bootstrap.Modal.getOrCreateInstance(modalEl);
        inst.show();
      });
    }

    const btnAddLocal=document.getElementById('coh-btn-add-med-local');
    if(btnAddLocal&&modalEl&&mainForm){
      btnAddLocal.addEventListener('click',()=>{
        const medData=modalEl.querySelector('[name="med_data"]');
        const medValorLiq=modalEl.querySelector('[name="med_valor_liq"]');
        const medLiqAcum=modalEl.querySelector('[name="med_liq_acum"]');
        const medPerc=modalEl.querySelector('[name="med_perc"]');
        const medObs=modalEl.querySelector('[name="med_obs"]');

        if(!medData.value){alert('Por favor, informe a Data.');medData.focus();return;}
        const hoje=new Date().toISOString().split('T')[0];
        if(medData.value>hoje){alert('A data da medição não pode ser no futuro.');medData.focus();return;}

        const fields={
          data:medData.value,
          valor_liq:medValorLiq.value,
          liq_acum:medLiqAcum.value,
          perc:medPerc.value,
          obs:medObs.value
        };
        for(const key in fields){
          const input=document.createElement('input');
          input.type='hidden';
          input.name=`new_med[${newMedIndex}][${key}]`;
          input.value=fields[key];
          mainForm.appendChild(input);
        }
        newMedIndex++;

        const container=document.getElementById('coh-medicoes-container');
        const noneAlert=document.getElementById('coh-medicoes-none');
        let tbody=document.getElementById('coh-medicoes-tbody');

        const dataBr=medData.value.split('-').reverse().join('/');
        const valorBr=medValorLiq.value||'R$ 0,00';
        const acumBr=medLiqAcum.value||'R$ 0,00';
        const percBr=medPerc.value||'0,00%';
        const obsHtml=medObs.value.replace(/</g,"&lt;").replace(/>/g,"&gt;");

        if(!tbody&&container){
          if(noneAlert)noneAlert.remove();
          container.innerHTML=`
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
          tbody=document.getElementById('coh-medicoes-tbody');
        }

        if(tbody){
          const rowCount=tbody.rows.length;
          const newRow=tbody.insertRow();
          newRow.innerHTML=`
            <td>${rowCount+1}</td>
            <td>${dataBr}</td>
            <td>${valorBr}</td>
            <td>${acumBr}</td>
            <td>${percBr}</td>
            <td>${obsHtml}</td>
            <td class="text-center"><small>(Salvar p/ excluir)</small></td>`;
        }

        const modalInstance=bootstrap.Modal.getInstance(modalEl);
        if(modalInstance)modalInstance.hide();
      });
    }
  });

})();
