"use strict";
(() => {
  const $=id=>document.getElementById(id);
  const params=new URLSearchParams(location.hash.slice(1));
  const resetToken=params.get("reset");
  if(resetToken)history.replaceState(null,"",location.pathname+location.search);
  const validToken=typeof resetToken==="string"&&/^[a-f0-9]{64}$/.test(resetToken);
  const requestForm=$("request-reset"),confirmForm=$("confirm-reset"),message=$("reset-message");
  let widgetId=null;
  function feedback(text,error=false){
    message.textContent=text;message.dataset.error=String(error);message.hidden=false;
  }
  async function post(url,data){
    const response=await fetch(url,{method:"POST",credentials:"same-origin",
      cache:"no-store",headers:{"Content-Type":"application/json"},body:JSON.stringify(data)});
    return {response,body:await response.json()};
  }
  requestForm.addEventListener("submit",async event=>{
    event.preventDefault();
    if(!requestForm.reportValidity())return;
    if(!window.turnstile||widgetId===null)return feedback("Security check isn't ready.",true);
    const token=window.turnstile.getResponse(widgetId);
    if(!token)return feedback("Please complete the security check.",true);
    $("reset-request-button").disabled=true;
    feedback("Checking the request…");
    try{
      const {response,body}=await post("./api/auth/reset-request.php",{
        email:$("reset-email").value,turnstile_token:token});
      window.turnstile.reset(widgetId);
      feedback(response.ok&&body.ok
        ? "If an eligible account exists, a recovery link will be emailed."
        : "Recovery is unavailable right now. Please try again later.",!(response.ok&&body.ok));
    }catch(_){feedback("Connection issue. Please retry later.",true);}
    finally{$("reset-request-button").disabled=false;}
  });
  confirmForm.addEventListener("submit",async event=>{
    event.preventDefault();
    if(!validToken||!confirmForm.reportValidity())return;
    const password=$("reset-password").value;
    if(password!==$("reset-confirm").value){
      return feedback("The passwords do not match.",true);
    }
    $("reset-confirm-button").disabled=true;
    feedback("Updating your password…");
    try{
      const {response,body}=await post("./api/auth/reset-confirm.php",{token:resetToken,password});
      $("reset-password").value="";$("reset-confirm").value="";
      if(response.ok&&body.ok){
        confirmForm.hidden=true;
        message.textContent="Password updated successfully. All other sessions were signed out. Return to the account page to sign in.";
        message.dataset.error="false";message.hidden=false;
      }else{
        feedback("This link is invalid or expired. You can request another recovery email.",true);
      }
    }catch(_){feedback("Password recovery is unavailable right now.",true);}
    finally{$("reset-confirm-button").disabled=false;}
  });
  async function boot(){
    try{
      const response=await fetch("./api/auth/status.php",{cache:"no-store"});
      const state=await response.json();
      if(!response.ok||!state.auth_available){
        $("reset-state").textContent="Account service is still closed. Recovery isn't active yet.";
        return;
      }
      $("reset-unavailable").hidden=true;$("reset-workspace").hidden=false;
      requestForm.hidden=validToken;confirmForm.hidden=!validToken;
      if(validToken)return;
      if(!state.turnstile_site_key)throw new Error("key_missing");
      const script=document.createElement("script");
      script.src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      script.async=true;
      script.addEventListener("load",()=>{
        if(!window.turnstile)return;
        widgetId=window.turnstile.render("#reset-captcha",{
          sitekey:state.turnstile_site_key,action:"reset",appearance:"always"});
        $("reset-request-button").disabled=false;
      });
      script.addEventListener("error",()=>feedback("CAPTCHA is unavailable.",true));
      document.head.appendChild(script);
    }catch(_){$("reset-state").textContent="Recovery is unavailable. Please try later.";}
  }
  boot();
})();
