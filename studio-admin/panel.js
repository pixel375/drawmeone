"use strict";
(()=>{
const $=id=>document.getElementById(id);let csrf="",widget=null,version=0,settings=null,art=[],writable=false,busy=false,artLoading=false,token="",settingsBusy=false,requestsReady=false,requestCursor=null,requestLoading=false;const selected=new Set();
function notice(text,error=false){$("notice").textContent=text;$("notice").classList.toggle("error",error);}
async function api(name,body=null){
    const headers={"Accept":"application/json"};let method="GET";
    if(body!==null){method="POST";if(csrf)headers["X-DMO-CSRF"]=csrf;if(!(body instanceof FormData)){headers["Content-Type"]="application/json";body=JSON.stringify(body);}}
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),body instanceof FormData?60000:15000);
    try{
        const r=await fetch("/api/admin/"+name.split("?")[0]+".php"+(name.includes("?")?"?"+name.split("?")[1]:""),{method,headers,body,cache:"no-store",credentials:"same-origin",signal:controller.signal});
        let data;try{data=await r.json();}catch{throw Error("invalid_response_"+r.status);}
        if(!r.ok||data.ok!==true){if(r.status===401&&csrf){csrf="";location.reload();}throw Error(data.error||"http_"+r.status);}
        return data;
    }finally{clearTimeout(timer);}
}
let signingIn=false,captchaRequired=true;
function loginMessage(text,retry=false){$("login-status").textContent=text;$("login-retry").hidden=!retry;}
function verificationFailed(){token="";$("login-submit").disabled=true;loginMessage("Security verification could not load. Check that this browser allows Cloudflare Turnstile, then retry.",true);}
async function captcha(key){
    if(typeof key!=="string"||!key.trim())throw Error("verification_unavailable");
    if(!window.turnstile?.render)await new Promise((resolve,reject)=>{
        const script=document.createElement("script"),timer=setTimeout(()=>{script.remove();reject(Error("verification_timeout"));},15000);
        script.src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
        script.onload=()=>{clearTimeout(timer);resolve();};script.onerror=()=>{clearTimeout(timer);reject(Error("verification_load_failed"));};document.head.append(script);
    });
    if(typeof window.turnstile?.render!=="function")throw Error("verification_unavailable");
    await new Promise((resolve,reject)=>{
        const timer=setTimeout(()=>reject(Error("verification_timeout")),15000);
        const render=()=>{clearTimeout(timer);try{
            widget=window.turnstile.render("#captcha",{sitekey:key,action:"admin_login",callback:t=>{token=t;$("login-submit").disabled=signingIn;if(!signingIn)loginMessage("Sign in to manage your artwork.");},"expired-callback":()=>{token="";$("login-submit").disabled=true;loginMessage("Security verification expired. Complete it again or retry.",true);},"error-callback":verificationFailed,"timeout-callback":verificationFailed});resolve();
        }catch(error){reject(error);}};
        if(typeof window.turnstile.ready==="function")window.turnstile.ready(render);else render();
    });
}
async function start(){
    let stage="availability";
    try{
        const status=await api("status");if(status.admin_available!==true)throw Error("admin_disabled");
        stage="session";
        const me=await api("me");
        if(me.authenticated===true&&typeof me.csrf_token==="string"&&me.csrf_token){
            csrf=me.csrf_token;$("login-area").hidden=true;$("workspace").hidden=false;$("welcome").textContent="Welcome"+(me.admin?.display_name?", "+me.admin.display_name:"")+".";
            await Promise.allSettled([loadArt(),loadSettings(),loadRequests()]);return;
        }
        if(me.authenticated!==false)throw Error("invalid_session_response");
        stage="verification";$("login-form").hidden=false;
        if(typeof status.captcha_required!=="boolean")throw Error("invalid_security_policy");
        captchaRequired=status.captcha_required;
        if(captchaRequired){loginMessage("Loading security verification…");await captcha(status.turnstile_site_key);}
        else{$("captcha").hidden=true;$("login-submit").disabled=false;loginMessage("Sign in to manage your artwork.");}
    }catch(error){
        if(stage==="verification"){verificationFailed();return;}
        const message=stage==="availability"?(error.message==="admin_disabled"?"Artist sign-in is disabled on the server.":"Could not reach the artist sign-in service."):"Could not check your sign-in session.";
        loginMessage(message+" Please retry. ("+(stage==="availability"?"DMO-A01":"DMO-A02")+")",true);
    }
}
$("login-retry").addEventListener("click",()=>location.reload());
$("login-form").addEventListener("submit",async e=>{
    e.preventDefault();if((captchaRequired&&!token)||signingIn)return;signingIn=true;$("login-submit").disabled=true;loginMessage("Signing in…");
    try{await api("login",{email:$("email").value,password:$("password").value,...(captchaRequired?{turnstile_token:token}:{})});location.reload();}
    catch(error){
        signingIn=false;
        const messages={invalid_credentials:"Email or password was not accepted.",verification_failed:"Security verification was not accepted. Please try again.",too_many_attempts:"Too many sign-in attempts. Wait 15 minutes before retrying.",forbidden:"Open https://drawmeone.com/studio-admin/index.php directly in your browser.",admin_service_unavailable:"The sign-in server is temporarily unavailable."};
        $("password").value="";token="";
        if(captchaRequired){if(widget!==null)try{window.turnstile.reset(widget);}catch{verificationFailed();return;}}
        else $("login-submit").disabled=false;
        loginMessage(messages[error.message]||"Sign-in could not be confirmed. Please try again.",true);
    }
});
$("logout").addEventListener("click",async()=>{try{await api("logout",{});location.reload();}catch{notice("Sign-out could not be confirmed. Please try again.",true);}});
document.querySelectorAll("[data-view]").forEach(b=>b.addEventListener("click",()=>{document.querySelectorAll("[data-view]").forEach(x=>x===b?x.setAttribute("aria-current","page"):x.removeAttribute("aria-current"));document.querySelectorAll("[data-panel]").forEach(p=>p.hidden=p.dataset.panel!==b.dataset.view);notice("");}));
function button(text,fn,disabled=false){const b=document.createElement("button");b.type="button";b.textContent=text;b.disabled=disabled;b.addEventListener("click",fn);return b;}
function visibleArt(){const filter=$("art-filter").value;return art.filter(w=>filter==="all"||w.visibility===filter).sort((a,b)=>Number(b.featured)-Number(a.featured)||a.sort_order-b.sort_order||b.id-a.id);}
function artControls(){const blocked=busy||artLoading;$("reload-art").disabled=blocked;$("art-filter").disabled=blocked;$("art-files").disabled=blocked||!writable;$("upload").disabled=blocked||!writable||!$("art-files").files.length;}
function selection(){const rows=visibleArt(),chosen=rows.filter(w=>selected.has(w.id));$("bulk").hidden=!rows.length;$("select-all").checked=rows.length>0&&chosen.length===rows.length;$("select-all").indeterminate=chosen.length>0&&chosen.length<rows.length;$("select-all").disabled=busy||artLoading||!writable;$("selection-status").textContent=chosen.length?chosen.length+" selected"+(chosen.length>60?" — select at most 60 per change.":new Set(chosen.map(w=>w.visibility)).size>1?" — choose only drafts or published drawings to change together.":""):"";$("bulk-publish").disabled=busy||!writable||!chosen.length||chosen.length>60||chosen.some(w=>w.visibility!=="draft");$("bulk-private").disabled=busy||!writable||!chosen.length||chosen.length>60||chosen.some(w=>w.visibility!=="published");}
function renderArt(){const list=$("art-list"),rows=visibleArt();list.replaceChildren();$("art-status").textContent=rows.length?rows.length+" drawing"+(rows.length===1?"":"s"):"No drawings here yet.";for(const w of rows){const card=document.createElement("article"),img=document.createElement("img"),p=document.createElement("p"),check=document.createElement("input"),checkLabel=document.createElement("label"),preview=document.createElement("a"),details=document.createElement("details"),summary=document.createElement("summary"),alt=document.createElement("input"),altLabel=document.createElement("label"),order=document.createElement("div"),orderLabel=document.createElement("span");card.className="art-card";img.alt=w.alt||"Drawing "+w.id;img.loading="lazy";img.src="/api/admin/artwork-preview.php?id="+w.id+"&v=thumb";p.textContent=w.visibility==="published"?"Published":"Private draft";check.type="checkbox";check.checked=selected.has(w.id);check.disabled=busy||artLoading||!writable;checkLabel.className="inline";checkLabel.append(check,document.createTextNode("Select drawing "+w.id));check.addEventListener("change",()=>{if(check.checked)selected.add(w.id);else selected.delete(w.id);selection();});preview.href="/api/admin/artwork-preview.php?id="+w.id+"&v=display";preview.target="_blank";preview.rel="noopener noreferrer";preview.className="art-preview";preview.textContent="View full drawing";preview.setAttribute("aria-label","View full drawing "+w.id+" (opens in a new tab)");summary.textContent="More options";alt.value=w.alt||"";alt.maxLength=240;altLabel.append(document.createTextNode("Image description (optional)"),alt);const siblings=art.filter(x=>x.visibility==="published"&&x.featured===w.featured).sort((a,b)=>a.sort_order-b.sort_order||b.id-a.id),position=siblings.findIndex(x=>x.id===w.id);orderLabel.textContent="Gallery order";order.className="order-actions";for(const [direction,offset,label] of [["earlier",-1,"Move earlier"],["later",1,"Move later"]]){const neighbor=position>=0?siblings[position+offset]:null;order.append(button(label,()=>change(w,{action:"move_published",neighbor_id:neighbor.id,direction,expected_order:w.sort_order,expected_neighbor_order:neighbor.sort_order,expected_featured:w.featured}),!writable||busy||artLoading||!neighbor));}details.append(summary,button(w.featured?"Unfeature":"Feature",()=>change(w,{action:"set_featured",featured:!w.featured,expected:w.featured}),!writable||busy||artLoading),altLabel,button("Save description",()=>change(w,{action:"set_alt",alt:alt.value,expected:w.alt||""}),!writable||busy||artLoading),orderLabel,order);const visibility=button(w.visibility==="published"?"Make private":"Publish",()=>change(w,{action:"set_visibility",visibility:w.visibility==="published"?"draft":"published",expected:w.visibility}),!writable||busy||artLoading);visibility.setAttribute("aria-label",visibility.textContent+" drawing "+w.id);card.append(checkLabel,img,p,preview,visibility,details);list.append(card);}selection();}
$("select-all").addEventListener("change",()=>{for(const w of visibleArt())if($("select-all").checked)selected.add(w.id);else selected.delete(w.id);renderArt();});
async function bulk(target){if(busy||artLoading||!writable)return;const rows=visibleArt().filter(w=>selected.has(w.id));if(!rows.length||rows.length>60||rows.some(w=>w.visibility===target))return;if(!confirm((target==="published"?"Publish ":"Make private ")+rows.length+" selected drawing(s)?"))return;busy=true;renderArt();artControls();try{await api("artworks",{action:"batch_visibility",ids:rows.map(w=>w.id),visibility:target,confirm:true});notice("Selected drawings updated.");selected.clear();}catch{notice("Change could not be confirmed. Refreshing the library.",true);}finally{busy=false;await loadArt();}}
$("bulk-publish").addEventListener("click",()=>bulk("published"));$("bulk-private").addEventListener("click",()=>bulk("draft"));
async function loadArt(){if(busy||artLoading)return;artLoading=true;renderArt();artControls();$("art-status").textContent="Loading artwork…";try{const d=await api("artworks");art=Array.isArray(d.artworks)?d.artworks.filter(x=>Number.isSafeInteger(x.id)&&x.id>0):[];writable=d.write_enabled===true;const present=new Set(art.map(w=>w.id));for(const id of selected)if(!present.has(id))selected.delete(id);}catch{$("art-status").textContent="Artwork unavailable. Try Refresh.";writable=false;$("upload").disabled=true;art=[];selected.clear();$("art-list").replaceChildren();selection();}finally{artLoading=false;renderArt();if(!writable&&art.length)$("art-status").textContent+=" · Editing is unavailable.";if(!writable&&!art.length)$("art-status").textContent="Artwork editing unavailable. Try Refresh.";artControls();}}
async function change(w,body){if(busy||artLoading||!writable)return;if(body.visibility==="published"&&!confirm("Publish this drawing on your public gallery?"))return;busy=true;renderArt();artControls();try{await api("artworks",{id:w.id,...body});notice("Drawing updated.");}catch{notice("Change could not be confirmed. Refreshing the artwork list.",true);}finally{busy=false;await loadArt();}}
$("art-filter").addEventListener("change",()=>{selected.clear();renderArt();});$("reload-art").addEventListener("click",()=>{if(!busy&&!artLoading)loadArt();});$("art-files").addEventListener("change",artControls);
$("upload").addEventListener("click",async()=>{
    if(busy||artLoading||!writable)return;
    const files=[...$("art-files").files];if(!files.length)return;
    if(files.length>60){notice("Choose at most 60 drawings at a time.",true);return;}
    busy=true;renderArt();artControls();$("upload-results").replaceChildren();$("upload-results").hidden=true;
    let added=0,existing=0,failed=0;
    function result(file,message){const li=document.createElement("li");li.textContent=file.name+": "+message;$("upload-results").append(li);$("upload-results").hidden=false;}
    try{
        for(const [index,file] of files.entries()){
            $("upload-status").textContent="Uploading "+(index+1)+" of "+files.length+"…";
            if(file.size<1||file.size>8*1024*1024){failed++;result(file,"Choose a non-empty image up to 8 MB.");continue;}
            const form=new FormData();form.append("artwork",file);form.append("operation_key",crypto.randomUUID());form.append("skip_identical","1");
            try{const response=await api("artwork-upload",form);if(response.identical_skipped||response.already_saved){existing++;}else{added++;}}catch(error){failed++;const messages={unsupported_image_format:"Use JPG, PNG or WebP.",image_dimensions_out_of_range:"Image dimensions are outside the supported range.",image_memory_budget_exceeded:"Image is too large to process; resize it and retry.",invalid_upload:"Image could not be accepted.",image_decode_failed:"Image could not be opened.",identical_artwork_needs_review:"An existing matching drawing needs review."};result(file,messages[error.message]||"Upload not confirmed. Check the library before retrying.");}
        }
        $("upload-status").textContent=added+" new private drawing(s); "+existing+" already in your library; "+failed+" not accepted or confirmed.";
    }finally{busy=false;$("art-files").value="";await loadArt();}
});
function availability(){const open=settings?.accepting===true;$("accepting").checked=open;$("accepting").disabled=!settings||settingsBusy||(!requestsReady&&!open);$("accepting-status").textContent=!settings?"Availability unavailable. Reload to check.":open?(requestsReady?"Accepting commissions.":"Requests unavailable; switch off and review."):(requestsReady?"Commissions are closed.":"Commissions are closed. Request service unavailable.");}
async function loadSettings(){try{const d=await api("studio");settings=d.settings;version=d.version;requestsReady=d.requests_ready===true;availability();for(const k of ["title","intro","price","payments","instagram","tiktok","default_mode"])$(k).value=settings[k]||(k==="default_mode"?"dark":"");document.querySelectorAll("#payments-form button,#website-form button").forEach(b=>b.disabled=false);}catch{settings=null;availability();document.querySelectorAll("#payments-form button,#website-form button").forEach(b=>b.disabled=true);notice("Website settings unavailable.",true);}}
async function save(keys){if(!settings||settingsBusy)return;settingsBusy=true;const next={...settings};for(const k of keys)next[k]=k==="accepting"?$(k).checked:$(k).value;$("accepting").disabled=true;document.querySelectorAll("#payments-form button,#website-form button").forEach(b=>b.disabled=true);try{const d=await api("studio",{version,settings:next});settings=d.settings;version=d.version;requestsReady=d.requests_ready===true;notice("Saved. Your website has been updated.");}catch{notice("Save could not be confirmed. Reload the page before retrying.",true);settings=null;}finally{settingsBusy=false;availability();document.querySelectorAll("#payments-form button,#website-form button").forEach(b=>b.disabled=!settings);}}
$("accepting").addEventListener("change",()=>{if($("accepting").checked&&!confirm("Open commission requests to the public?")){availability();return;}save(["accepting"]);});
function requestCard(w){const card=document.createElement("article"),top=document.createElement("div"),h=document.createElement("strong"),when=document.createElement("span"),email=document.createElement("a"),brief=document.createElement("p"),budget=document.createElement("p"),actions=document.createElement("div");card.className="request-card";top.className="top";h.textContent=w.name+(w.status==="new"?" · New":"");when.className="hint";when.textContent=(w.created_at||"")+" UTC";top.append(h,when);email.href="mailto:"+encodeURIComponent(w.email).replace(/%40/g,"@");email.textContent="Reply by email";brief.className="request-brief";brief.textContent=w.brief;budget.textContent=w.budget?"Budget: "+w.budget:"No budget specified";actions.className="top";actions.append(email);if(w.status==="new")actions.append(button("Mark read",()=>requestChange(w,"read")));if(w.status!=="archived")actions.append(button("Archive",()=>requestChange(w,"archived")));else actions.append(button("Move to inbox",()=>requestChange(w,"read")));card.append(top,brief,budget);if(w.notice_state!=="sent"){const note=document.createElement("p");note.className="hint";note.textContent="Email notification not confirmed. This request is saved in your inbox.";card.append(note);}card.append(actions);return card;}
async function loadRequests(append=false){if(requestLoading)return;requestLoading=true;$("reload-requests").disabled=true;$("more-requests").disabled=true;const view=$("request-view").value;if(!append){requestCursor=null;$("request-list").replaceChildren();}$("inbox-status").textContent="Loading requests…";try{const d=await api("guest-requests?view="+encodeURIComponent(view)+(append&&requestCursor?"&before="+requestCursor:""));if($("request-view").value!==view)return;if(!Array.isArray(d.requests))throw Error("invalid");for(const w of d.requests)if(Number.isSafeInteger(w.id)&&typeof w.email==="string")$("request-list").append(requestCard(w));requestCursor=Number.isSafeInteger(d.next_before)?d.next_before:null;$("more-requests").hidden=!requestCursor;$("inbox-status").textContent=$("request-list").children.length?"":"No requests here yet.";}catch{$("inbox-status").textContent="Requests unavailable. Try Refresh.";$("more-requests").hidden=true;}finally{requestLoading=false;$("reload-requests").disabled=false;$("more-requests").disabled=false;if($("request-view").value!==view)loadRequests();}}
async function requestChange(w,status){if(requestLoading)return;requestLoading=true;try{await api("guest-requests",{id:w.id,status,expected:w.status});notice("Request updated.");}catch{notice("Change could not be confirmed. Refreshing inbox.",true);}finally{requestLoading=false;await loadRequests();}}
$("request-view").addEventListener("change",()=>loadRequests());$("reload-requests").addEventListener("click",()=>loadRequests());$("more-requests").addEventListener("click",()=>loadRequests(true));
$("payments-form").addEventListener("submit",e=>{e.preventDefault();save(["payments","price"]);});$("website-form").addEventListener("submit",e=>{e.preventDefault();save(["title","intro","instagram","tiktok","default_mode"]);});document.querySelectorAll("#payments-form button,#website-form button").forEach(b=>b.disabled=true);start();
})();
