"use strict";
(async()=>{
  const $=id=>document.getElementById(id);
  const q=new URLSearchParams(location.search);
  const version=q.get("version"),sha=q.get("sha256");
  const valid=typeof version==="string"&&/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/.test(version)&&typeof sha==="string"&&/^[a-f0-9]{64}$/.test(sha);
  if((version!==null||sha!==null)&&!valid){$("policy-status").textContent="This policy reference is not valid.";return;}
  try{
    const url="./api/policy.php"+(valid?"?"+new URLSearchParams({version,sha256:sha}):"");
    const r=await fetch(url,{cache:"no-store",credentials:"same-origin"});
    if(r.status===404){$("policy-status").textContent="Approved customer policies are not available for this version yet.";return;}
    const data=await r.json(),p=data.policy;
    if(!r.ok||!data.ok||!p||typeof p.terms!=="string"||typeof p.privacy!=="string"||(valid&&(p.version!==version||p.sha256!==sha)))throw Error("unavailable");
    $("policy-version").textContent="Version "+p.version+" · Effective "+p.effective_date+" (UTC date)";
    $("policy-terms").textContent=p.terms;$("policy-privacy").textContent=p.privacy;
    $("policy-document").hidden=false;$("policy-status").hidden=true;
    if(["#terms","#privacy"].includes(location.hash))$(location.hash.slice(1)).scrollIntoView();
  }catch(_){$("policy-status").textContent="Policies could not be loaded. Please try again later.";}
})();