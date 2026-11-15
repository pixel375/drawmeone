"use strict";
(() => {
  const root = document.documentElement;
  const key = "dmo-preview-settings";
  const allowed = {
    site:["studio","archive","journal","cinema"],
    design:["noir","canvas","editorial","aurora","ember"],
    layout:["masonry","mosaic","rows"],
    mode:["dark","light"]
  };
  const defaultLayouts = {studio:"masonry",archive:"mosaic",journal:"masonry",cinema:"rows"};
  const siteDescriptions = {studio:"Studio — the original landing-page layout.",archive:"Atelier — boutique studio with an identity rail, project gallery, and clear commission experience.",journal:"Edition — an independent art journal with a masthead, exhibition and editorial chapters.",cinema:"Signature — a cinematic flagship with a full-screen showcase and spacious visual storytelling."};
  const modeIcon = document.getElementById("header-mode-icon");
  const modeShortcut = document.getElementById("header-mode");
  const lab = document.getElementById("design-lab");
  const collapse = document.getElementById("lab-collapse");

  function save() {
    try {
      localStorage.setItem(key,JSON.stringify({
        site:root.dataset.site,design:root.dataset.design,layout:root.dataset.layout,mode:root.dataset.mode
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
  }
  function choose(name, value) {
    if (!allowed[name] || !allowed[name].includes(value)) return;
    const oldLayout = root.dataset.layout;
    root.dataset[name] = value;
    if (name === "site") root.dataset.layout = defaultLayouts[value];
    reflect();
    save();
    if (name === "site") document.dispatchEvent(new CustomEvent("dmo:site-change", {detail:{site:value}}));
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
  reflect();
})();
