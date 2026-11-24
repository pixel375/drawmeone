"use strict";
(() => {
  const $=id=>document.getElementById(id);
  const params=new URLSearchParams(location.hash.slice(1));
  const resetToken=params.get("reset");
  if(resetToken)history.replaceState(null,"",location.pathname+location.search);
  const validToken=typeof resetToken==="string"&&/^[a-f0-9]{64}$/.test(resetToken);
  const requestForm=$("request-reset"),confirmForm=$("confirm-reset"),message=$("reset-message");
  const requestAnother = $("reset-request-another");
  const signInNext = $("reset-back-to-signin");
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
        : response.status===429
          ? "Too many recovery requests. Please try again later."
          : response.status===422
            ? "The security check was not accepted. Complete it and retry."
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
    if (new TextEncoder().encode(password).length > 128) {
      return feedback("The new password must not exceed 128 bytes.",true);
    }
    $("reset-confirm-button").disabled=true;
    feedback("Updating your password…");
    try{
      const {response,body}=await post("./api/auth/reset-confirm.php",{token:resetToken,password});
      if (response.ok && body.ok) {
        $("reset-password").value="";
        $("reset-confirm").value="";
        confirmForm.hidden=true;
        signInNext.hidden=false;
        requestAnother.hidden=true;
        feedback("Password updated successfully. Previous sessions were signed out. You can now sign in with your new password.");
      } else if (response.status === 422) {
        $("reset-password").value="";
        $("reset-confirm").value="";
        confirmForm.hidden=true;
        requestAnother.hidden=false;
        feedback("This recovery link is invalid, expired or already used. Request a fresh link.",true);
      } else if (response.status === 429) {
        feedback("Too many recovery attempts. Please try again later.",true);
      } else {
        // A transient error does not prove that the one-time link expired.
        // Keep the typed password and the form available for a safe retry.
        feedback("Password update could not be confirmed. Please try again. If you already changed your password, use the sign-in page to check.",true);
        requestAnother.hidden=false;
      }
    }catch(_){
      feedback("Connection issue. The reset may not have completed. Check your connection before retrying.",true);
      requestAnother.hidden=false;
    }
    finally{$("reset-confirm-button").disabled=false;}
  });
  async function boot(){
    try{
      const response=await fetch("./api/auth/status.php",{cache:"no-store"});
      const state=await response.json();
      if(!response.ok||!state.auth_available){
        $("reset-state").textContent=resetToken
          ? "Recovery isn't active yet. Reopen the original link from your email when account access is available."
          : "Account service is still closed. Recovery isn't active yet.";
        return;
      }
      $("reset-unavailable").hidden=true;$("reset-workspace").hidden=false;
      requestForm.hidden=validToken;confirmForm.hidden=!validToken;
      if(validToken)return;
      if(resetToken && !validToken) {
        feedback("This reset link is malformed. Request a new recovery email.",true);
      }
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
