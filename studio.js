"use strict";
(()=>{
const $=id=>document.getElementById(id),root=document.documentElement;
let works=[],shown=0,active=0,returnFocus=null,revision=0;
function theme(mode){root.dataset.publicMode=mode;$("theme").textContent=mode==="dark"?"☀":"☾";$("theme").setAttribute("aria-label","Switch to "+(mode==="dark"?"light":"dark")+" mode");}
let visitorMode=null;
try{const stored=localStorage.getItem("dmo-visitor-mode");if(stored==="light"||stored==="dark")visitorMode=stored;}catch{}
theme(visitorMode||"dark");
$("theme").addEventListener("click",()=>{const mode=root.dataset.publicMode==="dark"?"light":"dark";visitorMode=mode;theme(mode);try{localStorage.setItem("dmo-visitor-mode",mode);}catch{}});
async function get(url){const response=await fetch(url,{cache:"no-store",signal:AbortSignal.timeout(10000)});if(!response.ok)throw Error("unavailable");const data=await response.json();if(data.ok!==true)throw Error("unavailable");return data;}
function setText(id,value){if(typeof value==="string"&&value.trim())$(id).textContent=value;}
get("/api/studio.php").then(({settings:s,turnstile_site_key:key})=>{if(!s)return;if(["blurple","midnight","paper"].includes(s.theme))root.dataset.theme=s.theme;if(!visitorMode&&["dark","light"].includes(s.default_mode))theme(s.default_mode);if(s.accepting===true)setupRequest(key,s.guest_captcha!==false);setText("hero-title",s.title);setText("hero-intro",s.intro);for(const [id,key] of [["price","price"],["payment-methods","payments"]]){if(typeof s[key]==="string"&&s[key].trim()){setText(id,s[key]);$(id).hidden=false;}}for(const [key,label,host] of [["instagram","Instagram","www.instagram.com"],["tiktok","TikTok","www.tiktok.com"]]){try{const u=new URL(s[key]);if(u.protocol!=="https:"||u.hostname!==host||u.username||u.password||u.port)continue;const a=document.createElement("a");a.href=u.href;a.textContent=label;a.rel="noopener noreferrer";$("socials").append(a);}catch{}}if(s.discord_enabled===true){try{const u=new URL(s.discord_url);if(u.protocol==="https:"&&!u.username&&!u.password&&!u.port&&!u.search&&!u.hash&&((u.hostname==="discord.gg"&&/^\/[A-Za-z0-9_-]{2,64}\/?$/.test(u.pathname))||(u.hostname==="discord.com"&&/^\/invite\/[A-Za-z0-9_-]{2,64}\/?$/.test(u.pathname)))){const a=document.createElement("a");a.href=u.href;a.textContent="Discord";a.rel="noopener noreferrer";$("socials").append(a);}}catch{}}}).catch(()=>{});
let requestWidget=null,requestToken="",requestBusy=false,requestClosed=false,requestKey="",lastPayload="";
let requestCaptchaRequired=true,challengeKey="",challengeLoading=false,challengeScript=null,challengeTimer=null;
function challengeFailed(){clearTimeout(challengeTimer);challengeLoading=false;requestToken="";$("request-submit").disabled=requestBusy||requestClosed;$("request-retry").hidden=false;$("request-status").textContent="Security verification could not load. Retry verification, or contact me directly.";}
function loadChallenge(){
    if(challengeLoading||requestClosed)return;
    requestToken="";$("request-retry").hidden=true;$("request-submit").disabled=false;challengeLoading=true;
    $("request-status").textContent="Loading security verification…";
    if(!challengeKey){challengeFailed();return;}
    if(requestWidget!==null&&window.turnstile?.remove){try{window.turnstile.remove(requestWidget);}catch{}requestWidget=null;}
    const render=()=>{try{requestWidget=window.turnstile.render("#request-captcha",{sitekey:challengeKey,action:"guest_request",appearance:"always",callback:t=>{clearTimeout(challengeTimer);challengeLoading=false;requestToken=t;$("request-retry").hidden=true;$("request-submit").disabled=requestBusy||requestClosed;$("request-status").textContent="";},"expired-callback":challengeFailed,"error-callback":challengeFailed,"timeout-callback":challengeFailed});}catch{challengeFailed();}};
    challengeTimer=setTimeout(challengeFailed,20000);
    if(window.turnstile?.render){render();return;}
    challengeScript?.remove();challengeScript=document.createElement("script");challengeScript.src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";challengeScript.async=true;challengeScript.onload=()=>{if(window.turnstile?.render)render();else challengeFailed();};challengeScript.onerror=challengeFailed;document.head.append(challengeScript);
}
$("request-retry").addEventListener("click",loadChallenge);
function setupRequest(key,required){
    requestCaptchaRequired=required;challengeKey=typeof key==="string"?key:"";
    $("availability").textContent="I'm accepting commissions!";
    $("contact-copy").textContent="Tell me about your character or idea and we'll work out the details together.";
    $("request-form").hidden=false;$("request-submit").disabled=false;
    $("request-captcha").hidden=!required;
    if(required)loadChallenge();
}
$("request-form").addEventListener("submit",async e=>{
    e.preventDefault();if(requestBusy||requestClosed)return;if(requestCaptchaRequired&&!requestToken){$("request-status").textContent="Complete security verification before sending, or retry verification.";$("request-retry").hidden=false;return;}
    const fields={name:$("request-name").value,email:$("request-email").value,brief:$("request-brief").value,budget:$("request-budget").value},serialized=JSON.stringify(fields);
    if(serialized!==lastPayload||!requestKey){requestKey=crypto.randomUUID();lastPayload=serialized;}
    requestBusy=true;for(const id of ["request-name","request-email","request-brief","request-budget"])$(id).readOnly=true;$("request-submit").disabled=true;$("request-status").textContent="Sending your request…";
    try{
        const response=await fetch("/api/guest-request.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({...fields,operation_key:requestKey,website:$("request-website").value,...(requestCaptchaRequired?{turnstile_token:requestToken}:{})}),cache:"no-store",signal:AbortSignal.timeout(25000)});
        const d=await response.json();
        if(!response.ok||d.received!==true){if(d.error==="commissions_closed"){requestClosed=true;$("availability").textContent="Commissions are currently closed.";$("request-status").textContent="Commissions have closed. Please email me if you have a question.";requestToken="";return;}if(d.error==="verification_required"){requestClosed=true;$("request-status").textContent="Verification settings changed. Reload this page before sending again.";return;}throw Error(d.error||"unavailable");}
        $("request-status").textContent="Thanks! Your request has been received. I'll reply by email.";
        $("request-form").reset();requestKey="";lastPayload="";
    }catch{$("request-status").textContent="We couldn't confirm your request. Your text is still here; verify again to retry, or email me.";}
    finally{requestBusy=false;for(const id of ["request-name","request-email","request-brief","request-budget"])$(id).readOnly=false;requestToken="";$("request-submit").disabled=requestClosed;if(requestCaptchaRequired&&requestWidget!==null&&!requestClosed){try{window.turnstile.reset(requestWidget);}catch{challengeFailed();}}}
});
function valid(w){return w&&typeof w.src==="string"&&/^api\/artwork\.php\?id=[1-9][0-9]{0,17}&v=display$/.test(w.src)&&w.thumb===w.src.replace(/&v=display$/,"&v=thumb")&&Number.isSafeInteger(w.width)&&w.width>0&&Number.isSafeInteger(w.height)&&w.height>0;}
function label(w){return typeof w.alt==="string"&&w.alt.trim()?w.alt:"Original drawing by Draw Me One";}
function render(){const end=Math.min(shown+18,works.length),frag=document.createDocumentFragment();for(let i=shown;i<end;i++){const w=works[i],b=document.createElement("button"),img=document.createElement("img");b.type="button";b.className="tile";b.setAttribute("aria-label","View drawing: "+label(w));img.alt=label(w);img.width=w.width;img.height=w.height;img.loading=i<4?"eager":"lazy";img.decoding="async";img.addEventListener("error",()=>{img.hidden=true;b.textContent="Drawing unavailable";},{once:true});img.src="/"+w.thumb;b.append(img);b.addEventListener("click",()=>open(i,b));frag.append(b);}$("gallery").append(frag);shown=end;$("more").hidden=shown>=works.length;$("gallery-status").hidden=works.length>0;$("all-art").hidden=!works.length;}
async function load(){const status=$("gallery-status");status.hidden=false;status.textContent="Loading artwork…";$("gallery").setAttribute("aria-busy","true");try{const data=await get("/api/portfolio.php");if(!Array.isArray(data.works)||data.works.length>500)throw Error("invalid");works=[...new Map(data.works.filter(valid).map(w=>[w.src,w])).values()];shown=0;$("gallery").replaceChildren();status.textContent="New drawings will appear here soon. Thanks for visiting!";render();}catch{status.textContent="The gallery is temporarily unavailable. ";const retry=document.createElement("button");retry.type="button";retry.textContent="Try again";retry.addEventListener("click",load,{once:true});status.append(retry);}finally{$("gallery").removeAttribute("aria-busy");}}
$("more").addEventListener("click",()=>{const before=shown;render();$("gallery").children[before]?.focus({preventScroll:false});});
const dialog=$("viewer");
function show(i){if(!works.length)return;active=(i+works.length)%works.length;const w=works[active],id=++revision,img=document.createElement("img"),old=$("large-art");img.id="large-art";img.alt=label(w);img.addEventListener("error",()=>{if(id===revision){img.hidden=true;$("viewer-error").hidden=false;}},{once:true});old.replaceWith(img);$("viewer-error").hidden=true;img.src="/"+w.src;$("counter").textContent=(active+1)+" / "+works.length;$("detail").href="/artwork.php?id="+new URLSearchParams(w.src.split("?")[1]).get("id");$("prev").disabled=$("next").disabled=works.length<2;}
function open(i,b){returnFocus=b;dialog.showModal();show(i);$("close-viewer").focus();}
$("close-viewer").addEventListener("click",()=>dialog.close());$("prev").addEventListener("click",()=>show(active-1));$("next").addEventListener("click",()=>show(active+1));dialog.addEventListener("keydown",e=>{if(e.key==="ArrowLeft"||e.key==="ArrowRight"){e.preventDefault();show(active+(e.key==="ArrowLeft"?-1:1));}});dialog.addEventListener("close",()=>{revision++;$("large-art").removeAttribute("src");returnFocus?.focus({preventScroll:true});});window.addEventListener("pageshow",e=>{if(e.persisted)location.reload();});load();
})();
