"use strict";
(() => {
  const root=document.documentElement;
  const isCommission=document.body.dataset.preview==="commission";
  const isAdmin=document.body.dataset.preview==="admin";
  let mode="dark";
  function applyMode() {
    root.dataset.previewMode=mode;
    document.querySelectorAll("[data-toggle-mode]").forEach(button=>{
      button.textContent=mode==="dark"?"☀":"☾";
      button.setAttribute("aria-label",mode==="dark"?"Switch to light mode":"Switch to dark mode");
    });
  }
  document.querySelectorAll("[data-toggle-mode]").forEach(button=>button.addEventListener("click",()=>{
    mode=mode==="dark"?"light":"dark";applyMode();
  }));
  applyMode();

  if(isCommission){
    const prices={simple:[5,10],normal:[15,20],advanced:[25,30]};
    let tier="simple",step=1;
    const panels=[...document.querySelectorAll("[data-step-panel]")];
    function showStep(n){
      step=Math.min(3,Math.max(1,n));
      panels.forEach(panel=>{panel.hidden=Number(panel.dataset.stepPanel)!==step;});
      document.querySelectorAll("[data-step-button]").forEach(button=>{
        const selected=Number(button.dataset.stepButton)===step;
        button.classList.toggle("active",selected);
        if(selected)button.setAttribute("aria-current","step");
        else button.removeAttribute("aria-current");
      });
      if(step===3)updateReview();
      document.querySelector(".quote-workspace")?.scrollIntoView({block:"start",behavior:"instant"});
    }
    function chooseTier(next){
      if(!prices[next])return;
      tier=next;
      document.querySelectorAll("[data-tier]").forEach(button=>{
        const selected=button.dataset.tier===next;
        button.classList.toggle("selected",selected);
        button.setAttribute("aria-checked",String(selected));
      });
    }
    function updateReview(){
      const labels={simple:"Simple",normal:"Normal",advanced:"Advanced"};
      const selected=[...document.querySelectorAll("[data-addon]:checked")].map(x=>x.value);
      const safeText=id=>document.getElementById(id);
      const brief=safeText("request-brief").value.trim();
      safeText("review-tier").textContent=labels[tier];
      safeText("review-range").textContent="$"+prices[tier][0]+"–$"+prices[tier][1]+"*";
      safeText("review-kind").textContent=safeText("request-kind").value;
      safeText("review-contact").textContent=safeText("request-contact").value;
      safeText("review-addons").textContent=selected.length?selected.join(", "):"None selected";
      safeText("review-brief").textContent=brief||"No details added yet.";
    }
    document.querySelectorAll("[data-tier]").forEach(button=>{
      button.addEventListener("click",()=>chooseTier(button.dataset.tier));
      button.addEventListener("keydown",event=>{
        if(!["ArrowRight","ArrowDown","ArrowLeft","ArrowUp"].includes(event.key))return;
        event.preventDefault();
        const keys=Object.keys(prices);
        const next=(keys.indexOf(tier)+(event.key==="ArrowRight"||event.key==="ArrowDown"?1:keys.length-1))%keys.length;
        chooseTier(keys[next]);
        document.querySelector('[data-tier="'+keys[next]+'"]')?.focus();
      });
    });
    document.querySelectorAll("[data-next]").forEach(button=>button.addEventListener("click",()=>showStep(step+1)));
    document.querySelectorAll("[data-back]").forEach(button=>button.addEventListener("click",()=>showStep(step-1)));
    document.querySelectorAll("[data-step-button]").forEach(button=>button.addEventListener("click",()=>showStep(Number(button.dataset.stepButton))));
    document.getElementById("finish-demo")?.addEventListener("click",()=>{
      const result=document.getElementById("commission-finish");
      if(result){result.hidden=false;result.scrollIntoView({block:"nearest",behavior:"smooth"});}
    });
    showStep(1);
  }

  if(isAdmin){
    const names={overview:"Overview",requests:"Orders & quotes",messages:"Messages",artwork:"Portfolio & upload",packages:"Services & pricing",appearance:"Themes & branding",settings:"Website & security"};
    function showPanel(name){
      if(!names[name])return;
      document.querySelectorAll("[data-admin-tab]").forEach(button=>{
        const selected=button.dataset.adminTab===name;
        button.classList.toggle("selected",selected);
        if(selected)button.setAttribute("aria-current","page");
        else button.removeAttribute("aria-current");
      });
      document.querySelectorAll("[data-admin-panel]").forEach(panel=>{panel.hidden=panel.dataset.adminPanel!==name;});
      const breadcrumb=document.getElementById("admin-breadcrumb");
      if(breadcrumb)breadcrumb.textContent=names[name];
      window.scrollTo({top:0,behavior:"instant"});
    }
    document.querySelectorAll("[data-admin-tab]").forEach(button=>button.addEventListener("click",()=>showPanel(button.dataset.adminTab)));
    document.querySelectorAll("[data-jump-admin]").forEach(button=>button.addEventListener("click",()=>showPanel(button.dataset.jumpAdmin)));
    const threads={
      one:["Example conversation A","Could we add a second character to the illustration?","Absolutely — I'll review the scope and include it in the exact quote."],
      two:["Example conversation B","Should I send reference images for both outfits?","That would help. The real site will let you attach them privately to the request."],
      three:["Example conversation C","Can the finished drawing be delivered as a PNG?","The artist can confirm file format and resolution when reviewing your brief."]
    };
    document.querySelectorAll("[data-thread]").forEach(button=>button.addEventListener("click",()=>{
      const messages=threads[button.dataset.thread];
      if(!messages)return;
      document.querySelectorAll("[data-thread]").forEach(el=>el.classList.toggle("selected",el===button));
      document.getElementById("thread-title").textContent=messages[0];
      document.getElementById("thread-incoming").textContent=messages[1];
      document.getElementById("thread-outgoing").textContent=messages[2];
    }));
    showPanel("overview");
  }
})();
