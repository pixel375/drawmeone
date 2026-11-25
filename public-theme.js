/* Shared visitor preference for public server-rendered pages. No network calls. */
(() => {
  "use strict";
  const key="dmo-visitor-mode",root=document.documentElement;
  let saved=null;
  try{saved=localStorage.getItem(key);}catch(_){}
  if(saved==="light"||saved==="dark")root.dataset.publicMode=saved;
  document.addEventListener("DOMContentLoaded",()=>{
    const button=document.querySelector("[data-public-theme]");
    if(!button)return;
    function reflect(){
      const light=root.dataset.publicMode==="light"||(!root.dataset.publicMode&&matchMedia("(prefers-color-scheme: light)").matches);
      button.textContent=light?"Dark mode":"Light mode";
      button.setAttribute("aria-label",light?"Switch to dark mode":"Switch to light mode");
      const meta=document.querySelector('meta[name="theme-color"]');
      if(meta)meta.content=getComputedStyle(document.body).backgroundColor;
    }
    button.hidden=false;
    button.addEventListener("click",()=>{
      const light=root.dataset.publicMode==="light"||(!root.dataset.publicMode&&matchMedia("(prefers-color-scheme: light)").matches);
      root.dataset.publicMode=light?"dark":"light";
      try{localStorage.setItem(key,root.dataset.publicMode);}catch(_){}
      reflect();
    });
    addEventListener("storage",event=>{
      if(event.key!==key)return;
      if(event.newValue==="dark"||event.newValue==="light")root.dataset.publicMode=event.newValue;
      else delete root.dataset.publicMode;
      reflect();
    });
    matchMedia("(prefers-color-scheme: light)").addEventListener?.("change",reflect);
    reflect();
  });
})();
