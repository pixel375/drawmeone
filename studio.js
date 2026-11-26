"use strict";
(() => {
  const root = document.documentElement;
  const key = "dmo-preview-settings";
  const allowed = {
    site:["studio","archive","journal","cinema"],
    design:["noir","canvas","editorial","aurora","ember"],
    layout:["masonry","mosaic","rows"],
    mode:["dark","light"],
    logo:["original","01-minimal-pencil","02-signature","03-dmo-monogram","04-portrait-seal","05-editorial","06-brushstroke","07-portrait","08-paintbrush-badge","09-pen-lettering","10-creative-palette"]
  };
  const originalMarks = new WeakMap();
  const brandSelectors = ".wordmark, .at-mark, .at-footer > a:first-child, .ed-mast > a:first-child, .sg-wordmark";

  // Every layout shares the same chosen brand. A new layout creates new links,
  // so original HTML is retained per element for the Original option.
  function syncLogos() {
    const logo = allowed.logo.includes(root.dataset.logo) ? root.dataset.logo : "original";
    const color = root.dataset.mode === "light" ? "black" : "white";
    document.querySelectorAll(brandSelectors).forEach(link => {
      if (!originalMarks.has(link)) originalMarks.set(link,link.innerHTML);
      if (logo === "original") {
        if (link.classList.contains("is-custom-logo")) {
          link.innerHTML = originalMarks.get(link);
          link.classList.remove("is-custom-logo");
        }
        return;
      }
      let img = link.querySelector("img.brand-logo");
      if (!img) {
        img = document.createElement("img");
        img.className = "brand-logo";
        img.alt = "";
        img.decoding = "async";
        link.replaceChildren(img);
        link.classList.add("is-custom-logo");
      }
      link.setAttribute("aria-label","Draw Me One home");
      const url = "./assets/branding/" + logo + "-" + color + ".svg?v=8";
      if (img.getAttribute("src") !== url) img.src = url;
    });
    if(!previewEnabled)return;
    document.querySelectorAll("[data-logo-preview]").forEach(img => {
      const slug = img.dataset.logoPreview;
      if (!allowed.logo.includes(slug)) return;
      const url = "./assets/branding/" + slug + "-" + color + ".svg?v=8";
      if (img.getAttribute("src") !== url) img.src = url;
    });
  }
  const defaultLayouts = {studio:"masonry",archive:"mosaic",journal:"masonry",cinema:"rows"};
  const siteDescriptions = {studio:"Studio — the original landing-page layout.",archive:"Atelier — boutique studio with an identity rail, project gallery, and clear commission experience.",journal:"Edition — an independent art journal with a masthead, exhibition and editorial chapters.",cinema:"Signature — a cinematic flagship with a full-screen showcase and spacious visual storytelling."};
  const modeIcon = document.getElementById("header-mode-icon");
  const modeShortcut = document.getElementById("header-mode");
  const lab = document.getElementById("design-lab");
  const collapse = document.getElementById("lab-collapse");

  let previewEnabled=false;
  const defaults={site:"studio",design:"noir",layout:"masonry",mode:"dark",logo:"original"};
  function visitorMode(){
    try{const mode=localStorage.getItem("dmo-visitor-mode");return allowed.mode.includes(mode)?mode:null;}catch(_){return null;}
  }
  function save() {
    if(!previewEnabled)return;
    try {
      localStorage.setItem(key,JSON.stringify({
        site:root.dataset.site,design:root.dataset.design,layout:root.dataset.layout,mode:root.dataset.mode,logo:root.dataset.logo
      }));
    } catch (_) { /* Still works when storage is blocked. */ }
  }
  function reflect() {
    for (const name of Object.keys(allowed)) {
      document.querySelectorAll("[data-" + name + "-choice]").forEach(button => {
        button.setAttribute("aria-pressed",String(button.getAttribute("data-" + name + "-choice") === root.dataset[name]));
      });
    }
    if (modeIcon) modeIcon.textContent = root.dataset.mode === "dark" ? "☀" : "☾";
    if (modeShortcut) modeShortcut.setAttribute("aria-label",root.dataset.mode === "dark" ? "Switch to light mode" : "Switch to dark mode");
    const siteDescription = document.getElementById("lab-site-description");
    if (siteDescription) siteDescription.textContent = siteDescriptions[root.dataset.site] || siteDescriptions.studio;
    const themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor) themeColor.setAttribute("content",getComputedStyle(document.body).backgroundColor);
    syncLogos();
  }
  function choose(name, value) {
    if (!allowed[name] || !allowed[name].includes(value) || (!previewEnabled&&name!=="mode")) return;
    if(name==="mode"&&!previewEnabled){try{localStorage.setItem("dmo-visitor-mode",value);}catch(_){}}
    const oldLayout = root.dataset.layout;
    root.dataset[name] = value;
    if (name === "site") root.dataset.layout = defaultLayouts[value];
    reflect();
    save();
    if (name === "site") {
      document.dispatchEvent(new CustomEvent("dmo:site-change", {detail:{site:value}}));
      syncLogos(); // Site templates are rebuilt synchronously by concepts.js.
    }
    if (root.dataset.layout !== oldLayout) document.dispatchEvent(new CustomEvent("dmo:layout-change"));
  }
  for (const name of Object.keys(allowed)) {
    document.querySelectorAll("[data-" + name + "-choice]").forEach(button => {
      button.addEventListener("click",()=>choose(name,button.getAttribute("data-" + name + "-choice")));
    });
  }
  if (modeShortcut) modeShortcut.addEventListener("click",()=>choose("mode",root.dataset.mode === "dark" ? "light" : "dark"));
  if(lab && collapse){
    const mobile=window.matchMedia("(max-width:650px)");
    function setCollapsed(collapsed){
      lab.classList.toggle("is-collapsed",collapsed);
      collapse.setAttribute("aria-expanded",String(!collapsed));
      collapse.innerHTML=collapsed ? 'Expand <span aria-hidden="true">⌃</span>' : 'Minimize <span aria-hidden="true">⌄</span>';
      try{sessionStorage.setItem("dmo-lab-collapsed",String(collapsed));}catch(_){}
    }
    let state=null;
    try{state=sessionStorage.getItem("dmo-lab-collapsed");}catch(_){}
    setCollapsed(state===null ? mobile.matches : state==="true");
    collapse.addEventListener("click",()=>setCollapsed(!lab.classList.contains("is-collapsed")));
    document.querySelectorAll(".lab-controls button").forEach(button=>{
      button.addEventListener("click",()=>{if(mobile.matches)setCollapsed(true);});
    });
  }
  const bar=document.getElementById("page-progress");
  if(bar){
    let scheduled=false;
    function tick(){
      scheduled=false;
      const size=Math.max(1,document.documentElement.scrollHeight-window.innerHeight);
      bar.style.transform="scaleX("+Math.min(1,Math.max(0,window.scrollY/size))+")";
    }
    window.addEventListener("scroll",()=>{if(!scheduled){scheduled=true;requestAnimationFrame(tick);}}, {passive:true});
    window.addEventListener("resize",tick,{passive:true});
    document.addEventListener("dmo:site-change",()=>requestAnimationFrame(tick));
    tick();
  }
  // Published design settings are allowlisted as a complete document.
  // Missing, malformed or unreachable settings fall back to Studio with
  // preview controls hidden. A draft is never read by this runtime.
  function publishedChoices(data){
    const map={site:"site_style",design:"color_theme",layout:"gallery_layout",mode:"default_mode",logo:"logo"};
    const result={};
    if(!data||typeof data!=="object"||Array.isArray(data)||Object.keys(data).length!==5)return null;
    for(const [name,field] of Object.entries(map)){
      const value=data[field];
      if(!allowed[name].includes(value)||(name==="site"&&value!=="studio"))return null;
      result[name]=value;
    }
    return result;
  }
  function applyPresentation(settings,preview){
    const oldSite=root.dataset.site,oldLayout=root.dataset.layout;
    previewEnabled=preview;
    for(const [name,value] of Object.entries(settings))root.dataset[name]=value;
    if(lab)lab.hidden=!preview;
    if(oldSite!==root.dataset.site)document.dispatchEvent(new CustomEvent("dmo:site-change",{detail:{site:root.dataset.site}}));
    if(oldLayout!==root.dataset.layout)document.dispatchEvent(new CustomEvent("dmo:layout-change"));
    reflect();
    root.dataset.presentation=preview?"preview":"public";
    document.dispatchEvent(new CustomEvent("dmo:presentation-ready"));
  }
  async function loadPresentation(){
    let choices={...defaults},preview=false;
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),5000);
    try{
      const response=await fetch("./api/site-settings.php",{cache:"no-store",signal:controller.signal});
      if(response.ok){
        const data=await response.json();
        if(data?.ok===true&&data.presentation==="preview"){
          preview=true;
          try{
            const pref=JSON.parse(localStorage.getItem(key)||"{}");
            for(const name of Object.keys(allowed))if(allowed[name].includes(pref?.[name]))choices[name]=pref[name];
          }catch(_){}
        }else if(data?.ok===true&&data.presentation==="published"&&data.published===true&&Number.isSafeInteger(data.version)&&data.version>0){
          choices=publishedChoices(data.settings)||choices;
        }
      }
    }catch(_){}finally{clearTimeout(timer);}
    if(!preview)choices.mode=visitorMode()||choices.mode;
    applyPresentation(choices,preview);
  }
  // All four layouts share server-reported availability. No client-side
  // setting grants permission to submit requests or initiate payment.
  const intakeCopy={
    not_available:["Requests not open","New commission requests are not open. General studio questions are welcome by email.","Commission information"],
    paused:["Requests temporarily paused","New commission requests are temporarily paused. General studio questions are welcome by email.","Commission information"],
    at_capacity:["Currently at capacity","The studio is at capacity for new requests. Check the commission page for availability or email a general question.","Commission information"],
    open:["Commission requests open","Share your idea through the private commission workspace. A request is not a confirmed order; scope and price are agreed before any payment.","Start a commission request"]
  };
  let intakeState="not_available",intakeGeneration=0;
  function renderIntake(){
    const [label,description,cta]=intakeCopy[intakeState];
    for(const [selector,value] of [["[data-intake-label]",label],["[data-intake-description]",description],["[data-intake-cta]",cta]]){
      document.querySelectorAll(selector).forEach(el=>{el.textContent=value;});
    }
  }
  async function refreshIntake(){
    const generation=++intakeGeneration;
    intakeState="not_available";renderIntake();
    try{
      const response=await fetch("./api/request-status.php",{cache:"no-store"});
      if(!response.ok)return;
      const data=await response.json();
      if(generation!==intakeGeneration||data?.ok!==true)return;
      if(data.requests_open===true&&data.intake_reason==="open")intakeState="open";
      else if(data.requests_open===false&&["paused","at_capacity"].includes(data.intake_reason))intakeState=data.intake_reason;
      renderIntake();
    }catch(_){/* default closed; never advertise unconfirmed availability */}
  }
  document.addEventListener("dmo:site-change",renderIntake);
  window.addEventListener("pageshow",event=>{if(event.persisted)refreshIntake();});
  document.addEventListener("visibilitychange",()=>{if(document.visibilityState==="visible")refreshIntake();});
  // Native navigation updates the hash and scroll; explicitly focus the
  // current gallery section, including after a layout switch.
  document.querySelector(".skip-link")?.addEventListener("click",()=>{
    document.getElementById("portfolio")?.focus({preventScroll:true});
  });
  refreshIntake();
  reflect();
  loadPresentation();
})();
