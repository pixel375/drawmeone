/* Shared visitor preference and public design settings for secondary pages. */
(() => {
  "use strict";
  const key="dmo-visitor-mode",root=document.documentElement;
  let saved=null;
  try{saved=localStorage.getItem(key);}catch(_){}
  if(saved==="light"||saved==="dark")root.dataset.publicMode=saved;
  // Match the homepage default without overriding an explicit visitor choice.
  fetch("/api/studio.php",{cache:"no-store",signal:AbortSignal.timeout(10000)}).then(r=>r.ok?r.json():null).then(d=>{const mode=d?.settings?.default_mode;if(["showcase","gallery","sidebar","editorial"].includes(d?.settings?.layout))root.dataset.layout=d.settings.layout;if(["blurple","midnight","paper"].includes(d?.settings?.theme))root.dataset.theme=d.settings.theme;if(saved!=="light"&&saved!=="dark"&&(mode==="dark"||mode==="light")){root.dataset.publicMode=mode;document.dispatchEvent(new Event("dmo-default-theme"));}}).catch(()=>{});
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
      root.dataset.publicMode=light?"dark":"light";saved=root.dataset.publicMode;
      try{localStorage.setItem(key,root.dataset.publicMode);}catch(_){}
      reflect();
    });
    addEventListener("storage",event=>{
      if(event.key!==key)return;
      saved=event.newValue;
      if(event.newValue==="dark"||event.newValue==="light")root.dataset.publicMode=event.newValue;
      else delete root.dataset.publicMode;
      reflect();
    });
    matchMedia("(prefers-color-scheme: light)").addEventListener?.("change",reflect);
    document.addEventListener("dmo-default-theme",reflect);
    reflect();
    const share=document.querySelector("[data-share-artwork]"),copy=document.querySelector("[data-copy-artwork]"),status=document.querySelector("[data-share-status]");
    const url=document.querySelector('link[rel="canonical"]')?.href;
    if(!share||!copy||!status||!/^https:\/\/drawmeone\.com\/artwork\.php\?id=[1-9][0-9]{0,17}$/.test(url||""))return;
    if(typeof navigator.share==="function"){
      share.hidden=false;share.addEventListener("click",async()=>{
        try{await navigator.share({title:"Original drawing — Draw Me One",url});status.textContent="Share completed.";}
        catch(error){if(error?.name!=="AbortError")status.textContent="Sharing unavailable. Use the permanent drawing link.";}
      });
    }
    if(typeof navigator.clipboard?.writeText==="function"){
      copy.hidden=false;copy.addEventListener("click",async()=>{
        try{await navigator.clipboard.writeText(url);status.textContent="Drawing link copied.";}
        catch(_){status.textContent="Could not copy automatically. Use the permanent drawing link.";}
      });
    }
  });
})();
