"use strict";
(() => {
  const $ = id => document.getElementById(id);
  const unavailable = $("unavailable");
  const workspace = $("auth-workspace");
  const signedIn = $("signed-in");
  const note = $("form-notice");
  const state = $("service-state");
  const loginForm = $("login-form");
  const signupForm = $("signup-form");
  const loginTab = $("tab-login");
  const signupTab = $("tab-signup");
  const resendForm = $("resend-form");
  const signupPassword = $("signup-password");
  const signupPasswordConfirm = $("signup-password-confirm");
  for (const field of [signupPassword, signupPasswordConfirm]) {
    field.addEventListener("input", () => signupPasswordConfirm.setCustomValidity(""));
  }
  const widgets = {};
  let csrf = "";
  let status = null;
  let securityBusy=false;
  let privacyBusy=false,privacyCase=null,privacyGeneration=0;
  let leaving=false;
  const securityForm=$("security-form");
  function clearPrivateAccount(){csrf="";signedIn.hidden=true;$("account-name").textContent="";$("account-email").textContent="";$("account-session-count").textContent="";securityForm.reset();$("security-notice").textContent="";++privacyGeneration;privacyCase=null;$("privacy-form").reset();$("privacy-case").textContent="";$("privacy-notice").textContent="";$("account-privacy").hidden=true;}
  function freshSignIn(reason){leaving=true;clearPrivateAccount();workspace.hidden=true;location.replace(location.pathname+"?session="+encodeURIComponent(reason));}
  window.addEventListener("pagehide",()=>{clearPrivateAccount();});
  window.addEventListener("pageshow",event=>{if(event.persisted)location.reload();});

  function message(text, isError = false) {
    note.hidden = false;
    note.dataset.error = String(isError);
    note.textContent = text;
  }
  function show(tab) {
    const signup = tab === "signup" && status?.registration_available;
    signupForm.hidden = !signup;
    loginForm.hidden = signup;
    signupTab.setAttribute("aria-selected", String(signup));
    loginTab.setAttribute("aria-selected", String(!signup));
    note.hidden = true;
    resendForm.hidden = true;
  }
  async function request(path, payload, token) {
    const headers = {"Content-Type":"application/json"};
    if(token) headers["X-DMO-CSRF"] = token;
    const response = await fetch(path, {
      method:"POST", credentials:"same-origin", cache:"no-store",
      headers, body:JSON.stringify(payload)
    });
    const body = await response.json();
    return {response, body};
  }
  async function currentAccount() {
    const response = await fetch("./api/auth/me.php", {cache:"no-store",credentials:"same-origin"});
    if(!response.ok) return false;
    const data = await response.json();
    if(!data.authenticated || !data.user) return false;
    if(leaving)return false;
    csrf = data.csrf_token;
    $("account-session-count").textContent=Number.isSafeInteger(data.active_sessions)?data.active_sessions+" active session(s), including this device.":"Active session count unavailable.";
    $("account-name").textContent = data.user.display_name;
    $("account-email").textContent = data.user.email;
    unavailable.hidden = true; workspace.hidden = true; signedIn.hidden = false;
    $("account-privacy").hidden=data.privacy_self_service_enabled!==true;
    if(data.privacy_self_service_enabled===true)loadPrivacyCase();
    return true;
  }
  function resetCaptcha(which) {
    const widget = widgets[which];
    if(widget !== undefined && window.turnstile) window.turnstile.reset(widget);
  }
  function captchaToken(which) {
    if(widgets[which] === undefined || !window.turnstile) return "";
    return window.turnstile.getResponse(widgets[which]) || "";
  }
  function activateTurnstile(key) {
    const sdk = document.createElement("script");
    sdk.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    sdk.async = true;
    sdk.defer = true;
    sdk.addEventListener("load", () => {
      if (!window.turnstile) return;
      widgets.login = window.turnstile.render("#captcha-login", {
        sitekey:key, action:"login", appearance:"always",
      });
      $("submit-login").disabled = false;
      widgets.resend = window.turnstile.render("#captcha-resend", {
        sitekey:key, action:"resend", appearance:"always",
      });
      $("submit-resend").disabled = false;
      if(status.registration_available) {
        widgets.signup = window.turnstile.render("#captcha-signup", {
          sitekey:key, action:"signup", appearance:"always",
        });
        $("submit-signup").disabled = false;
      }
    });
    sdk.addEventListener("error", () => message("CAPTCHA failed to load. Please try again later.", true));
    document.head.appendChild(sdk);
  }
  loginTab.addEventListener("click", () => show("login"));
  signupTab.addEventListener("click", () => show("signup"));
  $("toggle-resend").addEventListener("click", () => {
    resendForm.hidden = !resendForm.hidden;
    note.hidden = true;
  });
  resendForm.addEventListener("submit", async event => {
    event.preventDefault();
    if (!status?.auth_available || !resendForm.reportValidity()) return;
    const token = captchaToken("resend");
    if (!token) { message("Please complete the security check.", true); return; }
    $("submit-resend").disabled = true;
    message("Requesting another verification link…");
    try {
      const {response,body} = await request("./api/auth/resend-verification.php", {
        email:$("resend-email").value,turnstile_token:token,
      });
      resetCaptcha("resend");
      message(response.ok && body.ok
        ? "If your address has a pending account, we'll send a new verification link. Check Inbox and Spam."
        : response.status === 429
          ? "Too many requests. Please retry later."
          : "Verification email could not be requested. Please retry later.",
        !(response.ok && body.ok));
    } catch (_) { message("Unable to request a new link. Please retry later.", true); }
    finally { $("submit-resend").disabled = false; }
  });

  loginForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if(!status?.auth_available || !loginForm.reportValidity()) return;
    const token = captchaToken("login");
    if(!token){ message("Please complete the security check.", true); return; }
    $("submit-login").disabled = true;
    message("Checking your details…");
    try {
      const {body,response} = await request("./api/auth/login.php",{
        email:$("login-email").value,
        password:$("login-password").value,
        turnstile_token:token,
      });
      $("login-password").value = "";
      resetCaptcha("login");
      if (response.ok && body.ok) {
        if (await currentAccount()) return;
        message("Your sign-in was accepted, but your account details could not load. " +
          "Refresh this page to check your session before signing in again.", true);
        return;
      }
      message(response.status===401 ?
        "Email/password not recognized, or the account is not verified." :
        response.status===429 ?
          "Too many sign-in attempts. Please try again later." :
        response.status===422 ?
          "Security verification was not accepted. Complete the security check and retry." :
          "Sign-in is unavailable. Please retry later.", true);
    } catch (_) { message("Unable to sign in. Check your connection.", true); }
    finally { $("submit-login").disabled = false; }
  });

  signupForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if(!status?.registration_available || !signupForm.reportValidity()) return;
    const password = signupPassword.value;
    if (password !== signupPasswordConfirm.value) {
      signupPasswordConfirm.setCustomValidity("The passwords do not match.");
      signupPasswordConfirm.reportValidity();
      message("The passwords do not match. Please re-enter your confirmation.", true);
      return;
    }
    if (new TextEncoder().encode(password).length > 128) {
      message("Use a password of at most 128 bytes, including special characters.", true);
      return;
    }
    const captcha=captchaToken("signup");
    if(!captcha){message("Please complete the security check.",true);return;}
    $("submit-signup").disabled=true;
    message("Preparing your verification email…");
    try{
      const {response,body}=await request("./api/auth/signup.php",{
        display_name:$("signup-name").value,
        email:$("signup-email").value,
        password,
        turnstile_token:captcha,
        policy_version:status.policy_version,policy_sha256:status.policy_sha256,acknowledge_privacy:$("signup-privacy-ack").checked,
      });
      signupPassword.value="";
      signupPasswordConfirm.value="";
      resetCaptcha("signup");
      message(response.ok && body.ok
        ? "If this email address is eligible, we'll send a verification link. Please check your inbox."
        : response.status === 429
          ? "Too many registration attempts. Please try again later."
          : response.status === 422
            ? "Registration details or the security check were not accepted. Please review the form."
            : "Account registration is unavailable. Please retry later.",
        !(response.ok && body.ok));
    }catch(_){message("Unable to create an account. Please try later.",true);}
    finally{$("submit-signup").disabled=false;}
  });

  function renderPrivacyCase(value){
    privacyCase=value||null;
    const open=privacyCase&&["submitted","in_review"].includes(privacyCase.status);
    $("privacy-case").textContent=privacyCase?"Latest request #"+privacyCase.case_id+" · "+privacyCase.kind+" · "+privacyCase.status.replaceAll("_"," ")+(privacyCase.reply?"\n"+privacyCase.reply:""):"No privacy requests recorded.";
    $("privacy-withdraw").hidden=!open;
    for(const opt of $("privacy-action").options)opt.disabled=!!open&&opt.value!=="export";
    if(open)$("privacy-action").value="export";
    syncPrivacyForm();
  }
  function syncPrivacyForm(){
    $("privacy-note-fields").hidden=$("privacy-action").value==="export";
  }
  async function loadPrivacyCase(){
    const generation=++privacyGeneration;
    try{
      const response=await fetch("./api/auth/privacy.php",{credentials:"same-origin",cache:"no-store"});
      if(generation!==privacyGeneration||leaving||!csrf)return;
      if(response.status===401)return freshSignIn("expired");
      const data=await response.json();
      if(generation!==privacyGeneration||leaving||!csrf)return;
      if(!response.ok||!data.ok)throw Error("privacy_status");
      renderPrivacyCase(data.case);
    }catch(_){if(generation===privacyGeneration)$("privacy-case").textContent="Request status unavailable. Refresh before retrying.";}
  }
  async function applyPrivacy(withdraw=false){
    const form=$("privacy-form");
    if(privacyBusy||securityBusy||!csrf||!form.reportValidity())return;
    const action=$("privacy-action").value;
    const payload={current_password:$("privacy-password").value,action:withdraw?"withdraw":action==="export"?"export":"submit"};
    if(withdraw){
      if(!privacyCase||!window.confirm("Withdraw this open privacy request?"))return;
      payload.expected_version=privacyCase.version;
    }else if(action!=="export"){
      if(!window.confirm("Submit this privacy request for manual review? No data is changed or deleted automatically."))return;
      payload.kind=action;payload.note=$("privacy-note").value.trim();
    }
    ++privacyGeneration;const generation=privacyGeneration;
    privacyBusy=true;for(const field of form.querySelectorAll("input,select,textarea,button"))field.disabled=true;
    $("sign-out").disabled=true;$("security-submit").disabled=true;$("privacy-notice").textContent="Processing…";
    try{
      const {response,body}=await request("./api/auth/privacy.php",payload,csrf);
      if(generation!==privacyGeneration||leaving||!csrf)return;
      if(response.status===401)return freshSignIn("expired");
      if(!response.ok||!body.ok){
        if(response.status===409)await loadPrivacyCase();
        $("privacy-notice").textContent=response.status===413?"Your records exceed the self-service export limit. Submit an additional access request.":response.status===403?"Password or security confirmation not accepted.":response.status===409?"Request changed. Refresh before retrying.":"Action not confirmed. Please try again later.";return;
      }
      if(payload.action==="export"){
        if(!body.export||body.export.format!=="drawmeone-customer-records-v1")throw Error("invalid_export");
        const url=URL.createObjectURL(new Blob([JSON.stringify(body.export,null,2)],{type:"application/json"}));
        const link=document.createElement("a");link.href=url;link.download="drawmeone-customer-records.json";document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),30000);
        $("privacy-notice").textContent="Export prepared. Store this private file securely.";
      }else{
        renderPrivacyCase(body.case);$("privacy-note").value="";
        $("privacy-notice").textContent=withdraw?"Request withdrawn.":"Request recorded for manual review. No data has been deleted.";
      }
    }catch(_){if(generation===privacyGeneration)$("privacy-notice").textContent="Outcome uncertain. Refresh before retrying.";}
    finally{
      privacyBusy=false;$("privacy-password").value="";
      for(const field of form.querySelectorAll("input,select,textarea,button"))field.disabled=false;
      $("sign-out").disabled=false;$("security-submit").disabled=false;
    }
  }
  $("privacy-action").addEventListener("change",syncPrivacyForm);
  $("privacy-form").addEventListener("submit",event=>{event.preventDefault();applyPrivacy();});
  $("privacy-withdraw").addEventListener("click",()=>applyPrivacy(true));

  function syncSecurityForm(){
    const change=$("security-action").value==="change_password";
    $("security-new-fields").hidden=!change;
    $("security-new").required=$("security-confirm").required=change;
    $("security-new").value=$("security-confirm").value="";
  }
  $("security-action").addEventListener("change",syncSecurityForm);
  securityForm.addEventListener("submit",async event=>{
    event.preventDefault();if(securityBusy||privacyBusy||!csrf||!securityForm.reportValidity())return;
    const action=$("security-action").value;
    const payload={action,current_password:$("security-current").value};
    if(action==="change_password"){
      if($("security-new").value!==$("security-confirm").value){
        $("security-notice").textContent="New passwords do not match.";return;
      }
      if(new TextEncoder().encode($("security-new").value).length>128){
        $("security-notice").textContent="New password must be at most 128 bytes.";return;
      }
      payload.new_password=$("security-new").value;
    }
    if(!window.confirm(action==="change_password"?"Change your password and sign out all devices?":"Sign out all other devices?"))return;
    securityBusy=true;$("sign-out").disabled=true;
    for(const field of securityForm.querySelectorAll("input,select,button"))field.disabled=true;
    $("security-notice").textContent="Applying security action…";
    try{
      const {response,body}=await request("./api/auth/security.php",payload,csrf);
      if(response.status===401)return freshSignIn("expired");
      if(!response.ok||!body.ok){$("security-notice").textContent=response.status===403?"Current password or security check was not accepted.":response.status===429?"Too many attempts. Try again later.":"Security action not confirmed. Review your details.";return;}
      if(body.requires_sign_in===true)return freshSignIn("password-changed");
      if(body.status!=="other_sessions_revoked")throw new Error("unexpected_result");
      $("account-session-count").textContent="1 active session, including this device.";
      $("security-notice").textContent="Other sessions signed out.";
    }catch(_){$("security-notice").textContent="Security action outcome uncertain. Refresh before trying again.";}
    finally{
      securityBusy=false;for(const field of securityForm.querySelectorAll("input,select,button"))field.disabled=false;
      $("sign-out").disabled=false;$("security-current").value=$("security-new").value=$("security-confirm").value="";
    }
  });
  $("sign-out").addEventListener("click",async()=>{
    if(securityBusy||privacyBusy||!csrf)return;
    securityBusy=true;$("sign-out").disabled=true;$("security-submit").disabled=true;
    try{
      const {response,body}=await request("./api/auth/logout.php",{},csrf);
      if(!response.ok||body.ok!==true||body.status!=="signed_out")throw new Error("logout_failed");
      freshSignIn("signed-out");
    }catch(_){$("security-notice").textContent="Unable to confirm sign out. Refresh or try again.";}
    finally{securityBusy=false;$("sign-out").disabled=false;$("security-submit").disabled=false;}
  });

  async function verifyFromLink(token) {
    if(!/^[a-f0-9]{64}$/.test(token))return;
    state.textContent="Checking your one-time email verification link…";
    try {
      const {response,body}=await request("./api/auth/verify.php",{token});
      state.textContent=response.ok && body.ok
        ? "Email confirmed. You can now sign in."
        : response.status === 429
          ? "Too many verification attempts. Please try again later."
          : "This verification link has expired or was already used. If the account is still pending, request another link below.";
    }catch(_){
      state.textContent="Unable to verify your email at the moment.";
    }
  }
  async function initialize() {
    const reason=new URLSearchParams(location.search).get("session");
    if(reason)history.replaceState(null,"",location.pathname+location.hash);
    const fragment = new URLSearchParams(location.hash.slice(1));
    const verificationToken = fragment.get("verify");
    if(verificationToken)history.replaceState(null,"",location.pathname+location.search);
    try {
      const response = await fetch("./api/auth/status.php", {cache:"no-store"});
      if(!response.ok) throw new Error("unavailable");
      status=await response.json();
      if(status.registration_available){
        if(typeof status.policy_version!=="string"||!/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/.test(status.policy_version)||typeof status.policy_sha256!=="string"||!/^[a-f0-9]{64}$/.test(status.policy_sha256))status.registration_available=false;
        else $("signup-policy-link").href="./policy.php?"+new URLSearchParams({version:status.policy_version,sha256:status.policy_sha256})+"#privacy";
      }
      if(!status.auth_available || !status.turnstile_site_key){
        state.textContent = verificationToken
          ? "Email verification isn't available yet. Reopen the original link from your email when account access becomes available."
          : "Registration and sign-in are not open yet. The artist is still preparing this feature.";
        return;
      }
      if(await currentAccount()) return;
      unavailable.hidden=true;workspace.hidden=false;
      $("resend-wrap").hidden = false;
      signupTab.hidden = !status.registration_available;
      show("login");
      activateTurnstile(status.turnstile_site_key);
      if(reason==="signed-out")message("You have been signed out.");
      if(reason==="password-changed")message("Password changed. Sign in with your new password.");
      if(reason==="expired")message("Your session expired. Please sign in again.");
      if(verificationToken) {
        await verifyFromLink(verificationToken);
        message(state.textContent, state.textContent !== "Email confirmed. You can now sign in.");
      }
    } catch (_) {
      state.textContent="Account service is temporarily unavailable. Please check again later.";
    }
  }
  initialize();
})();
