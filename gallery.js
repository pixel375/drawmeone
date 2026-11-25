"use strict";
(() => {
  const root = document.documentElement;
  const gallery = document.getElementById("portfolio-gallery");
  const notice = document.getElementById("gallery-demo-notice");
  const empty = document.getElementById("portfolio-empty");
  const error = document.getElementById("portfolio-error");
  const more = document.getElementById("gallery-more");
  const moreButton = document.getElementById("gallery-more-button");
  const remaining = document.getElementById("gallery-remaining");
  const progress = document.getElementById("gallery-progress");
  const dialog = document.getElementById("portfolio-lightbox");
  const big = document.getElementById("lightbox-image");
  const count = document.getElementById("lightbox-counter");
  const pageLink = document.getElementById("lightbox-detail-link");
  if (![gallery,notice,empty,error,more,moreButton,remaining,progress,dialog,big,count,pageLink].every(Boolean)) return;

  const realImage = /^(?:assets\/portfolio\/[a-z0-9][a-z0-9/_-]*\.(?:avif|webp|png|jpe?g)|api\/artwork\.php\?id=[1-9][0-9]{0,17}&v=display)$/i;
  const optimizedThumb = /^api\/artwork\.php\?id=[1-9][0-9]{0,17}&v=thumb$/i;
  const demoImage = /^assets\/demo\/art-[0-9]{2}\.svg$/;
  const fallback = "./assets/demo/placeholder.svg";
  const unavailable="./assets/artwork-unavailable.svg";
  let hostedHasMore=false;
  let entries=[],isDemo=false,visible=0,renderedCount=0,expanded=false,activeIndex=0,lastFocused=null,startX=null;
  const step=()=>window.matchMedia("(max-width:620px)").matches ? 8 : 12;
  const isScroll=()=>root.dataset.layout === "rows";

  const acceptable=(item,demo)=>!!item && typeof item.src === "string" &&
    (demo ? demoImage.test(item.src) : realImage.test(item.src)) &&
    (item.thumb == null || (typeof item.thumb === "string" && optimizedThumb.test(item.thumb) &&
      item.src === item.thumb.replace(/&v=thumb$/i,"&v=display"))) &&
    (item.alt == null || (typeof item.alt==="string" && item.alt.length<=260));
  const imageAlt=(item)=>typeof item.alt==="string" && item.alt.trim() ?
    item.alt.trim() : (isDemo ? "Decorative sample design image" : "Original drawing by Draw Me One");
  function safeguard(img) {
    img.addEventListener("error",()=>{
      if (!img.getAttribute("src") || img.dataset.fallback === "true") return;
      img.dataset.fallback="true";
      img.src=isDemo?fallback:unavailable;
      if(!isDemo)img.alt="Artwork image currently unavailable";
    });
  }
  function showAt(i) {
    if (!entries.length) return;
    activeIndex=(i+entries.length)%entries.length;
    delete big.dataset.fallback;
    big.alt=imageAlt(entries[activeIndex]);
    big.src=entries[activeIndex].src;
    count.textContent=(activeIndex+1)+" / "+entries.length;
    const match=/^api\/artwork\.php\?id=([1-9][0-9]{0,17})&v=display$/.exec(entries[activeIndex].src);
    pageLink.hidden=!match;
    if(match) pageLink.href="./artwork.php?id="+match[1];
    else pageLink.removeAttribute("href");
  }
  function openAt(index,button) {
    lastFocused=button;showAt(index);
    if (typeof dialog.showModal === "function") {
      dialog.showModal();
      dialog.querySelector("[data-gallery-close]").focus();
    } else window.open(entries[index].src,"_blank","noopener,noreferrer");
  }
  dialog.querySelector("[data-gallery-close]").addEventListener("click",()=>dialog.close());
  dialog.querySelector("[data-gallery-prev]").addEventListener("click",()=>showAt(activeIndex-1));
  dialog.querySelector("[data-gallery-next]").addEventListener("click",()=>showAt(activeIndex+1));
  dialog.addEventListener("click",(event)=>{if(event.target===dialog)dialog.close();});
  dialog.addEventListener("close",()=>{
    big.removeAttribute("src");delete big.dataset.fallback;
    if(lastFocused&&lastFocused.isConnected)lastFocused.focus();
  });
  dialog.addEventListener("keydown",(event)=>{
    if(event.key==="Escape"){event.preventDefault();if(dialog.open)dialog.close();return;}
    if(event.key==="ArrowLeft"){event.preventDefault();showAt(activeIndex-1);}
    if(event.key==="ArrowRight"){event.preventDefault();showAt(activeIndex+1);}
  });
  const stage=dialog.querySelector(".lightbox-stage");
  stage.addEventListener("touchstart",event=>{
    if(event.touches.length===1)startX=event.touches[0].clientX;
  },{passive:true});
  stage.addEventListener("touchend",event=>{
    if(startX==null||event.changedTouches.length!==1)return;
    const d=event.changedTouches[0].clientX-startX;startX=null;
    if(Math.abs(d)>65)showAt(activeIndex+(d<0?1:-1));
  },{passive:true});
  safeguard(big);

  function syncShowcase(){
    document.documentElement.dataset.hasArtwork=String(!isDemo && entries.length>0);
    root.dataset.galleryState=entries.length?(isDemo?"demo":"artwork"):"empty";
    document.querySelectorAll("[data-showcase-image]").forEach(img=>{
      if(!img.dataset.defaultSrc) img.dataset.defaultSrc=img.getAttribute("src") || "";
      if(!img.dataset.safeguarded){safeguard(img);img.dataset.safeguarded="true";}
      delete img.dataset.fallback;
      if(!img.dataset.defaultAlt) img.dataset.defaultAlt=img.getAttribute("alt") || "";
      const n=Math.max(0,Number.parseInt(img.dataset.showcaseImage||"0",10)||0);
      if(!isDemo && entries.length){
        const work=entries[n%entries.length];
        img.alt=imageAlt(work);
        img.src=work.src;
      }else if(root.dataset.presentation==="preview"){
        img.src=img.dataset.defaultSrc;
        img.alt=img.dataset.defaultAlt;
      }else{
        img.removeAttribute("src");img.alt="";
      }
    });
    document.querySelectorAll("[data-demo-caption]").forEach(el=>{
      el.hidden=root.dataset.presentation!=="preview"||(!isDemo && entries.length>0);
    });
  }
  document.addEventListener("dmo:site-change",()=>queueMicrotask(syncShowcase));

  function makeTile(item,index) {
    const button=document.createElement("button");
    button.type="button";button.className="art-tile";
    button.setAttribute("aria-label","View image "+(index+1)+" of "+entries.length+": "+imageAlt(item));
    const img=document.createElement("img");
    safeguard(img);img.alt=imageAlt(item);img.src=item.thumb||item.src;
    img.loading=index<4?"eager":"lazy";img.decoding="async";
    if(Number.isInteger(item.width)&&item.width>0)img.width=item.width;
    if(Number.isInteger(item.height)&&item.height>0)img.height=item.height;
    button.appendChild(img);
    button.addEventListener("click",()=>openAt(index,button));
    return button;
  }
  function render() {
    if(!entries.length)return;
    const total=isScroll()?entries.length:Math.min(visible,entries.length);
    // Append only new images; avoid redownloading and recreating all old tiles.
    if(total<renderedCount || gallery.childElementCount!==renderedCount){
      gallery.replaceChildren();
      renderedCount=0;
    }
    if(total>renderedCount){
      const fragment=document.createDocumentFragment();
      for(let i=renderedCount;i<total;i++)fragment.appendChild(makeTile(entries[i],i));
      gallery.appendChild(fragment);
      renderedCount=total;
    }
    gallery.classList.add("is-loaded");
    gallery.setAttribute("aria-label",isDemo?"Temporary sample images, not the artist's work":"Original drawings by Draw Me One");
    const moreRemain=entries.length-total;
    more.hidden=isScroll() || moreRemain<=0;
    remaining.textContent=moreRemain>0?"+ "+Math.min(step(),moreRemain):"";
    moreButton.setAttribute("aria-label","Load "+Math.min(step(),moreRemain)+" more images");
    progress.textContent="Showing "+total+" of "+entries.length+" images"+(hostedHasMore?" in this selection":"");
    document.getElementById("portfolio-directory-note").hidden=!hostedHasMore;
  }
  moreButton.addEventListener("click",()=>{
    expanded=true;visible=Math.min(entries.length,visible+step());render();
    // Only move focus when all items have loaded and the button disappears.
    if(more.hidden){progress.tabIndex=-1;progress.focus({preventScroll:true});}
  });
  document.addEventListener("dmo:layout-change",()=>{
    // Going back to a grid returns to the compact default.
    visible=step();expanded=false;render();
  });
  window.addEventListener("resize",()=>{
    if(!expanded && !isScroll() && entries.length && visible!==step()) {visible=step();render();}
  });

  async function manifest(path){
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),8000);
    try{
    const response=await fetch(path,{cache:"no-cache",signal:controller.signal});
    if(!response.ok)throw new Error(path+" returned "+response.status);
    const data=await response.json();
    if(!data||!Array.isArray(data.works))throw new Error("Invalid manifest at "+path);
    if(path==="./api/portfolio.php")hostedHasMore=data.has_more===true;
    return data.works;
    }finally{clearTimeout(timer);}
  }
  async function load(){
    const preview=root.dataset.presentation==="preview";
    let hosted = [];
    try {
      hosted = (await manifest("./api/portfolio.php")).filter(item=>acceptable(item,false));
    } catch (_) {
      if(!preview)throw Error("Published portfolio unavailable");
      // Keep existing fallback during preview outages.
      console.warn("Draw Me One: Database portfolio temporarily unavailable.");
    }
    let local = [];
    try {
      if(preview)local = (await manifest("./data/portfolio.json")).filter(item=>acceptable(item,false));
    } catch (issue) {
      // A missing legacy manifest must not hide successfully fetched published art.
      if (!hosted.length) throw issue;
      console.warn("Draw Me One: Local portfolio temporarily unavailable.");
    }
    const unique = new Map();
    for (const item of [...hosted, ...local]) {
      if (!unique.has(item.src)) unique.set(item.src,item);
    }
    const real=[...unique.values()];
    isDemo=preview&&real.length===0;
    entries=isDemo?(await manifest("./data/demo-portfolio.json")).filter(item=>acceptable(item,true)):real;
    notice.hidden=!isDemo;empty.hidden=entries.length>0;
    syncShowcase();
    if(!entries.length)return;
    visible=step();render();
  }
  function startLoad(){load().catch(issue=>{
    console.warn("Draw Me One gallery:",issue);
    entries=[];isDemo=false;syncShowcase();root.dataset.galleryState="error";
    notice.hidden=true;empty.hidden=true;error.hidden=false;
  });}
  if(root.dataset.presentation)startLoad();
  else document.addEventListener("dmo:presentation-ready",startLoad,{once:true});
})();
