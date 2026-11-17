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
  const widgets = {};
  let csrf = "";
  let status = null;

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
    csrf = data.csrf_token;
    $("account-name").textContent = data.user.display_name;
    $("account-email").textContent = data.user.email;
    unavailable.hidden = true; workspace.hidden = true; signedIn.hidden = false;
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
      if(response.ok && body.ok && await currentAccount()) return;
      message(response.status===401 ?
        "Email/password not recognized, or the account is not verified." :
        "Sign-in is unavailable. Please retry later.", true);
    } catch (_) { message("Unable to sign in. Check your connection.", true); }
    finally { $("submit-login").disabled = false; }
  });

  signupForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if(!status?.registration_available || !signupForm.reportValidity()) return;
    const captcha=captchaToken("signup");
    if(!captcha){message("Please complete the security check.",true);return;}
    $("submit-signup").disabled=true;
    message("Preparing your verification email…");
    try{
      const {response,body}=await request("./api/auth/signup.php",{
        display_name:$("signup-name").value,
        email:$("signup-email").value,
        password:$("signup-password").value,
        turnstile_token:captcha,
      });
      $("signup-password").value="";
      resetCaptcha("signup");
      message(response.ok && body.ok
        ? "If this email address is eligible, we'll send a verification link. Please check your inbox."
        : "Account registration is unavailable. Please retry later.",
        !(response.ok && body.ok));
    }catch(_){message("Unable to create an account. Please try later.",true);}
    finally{$("submit-signup").disabled=false;}
  });

  $("sign-out").addEventListener("click", async () => {
    try {
      const {response}=await request("./api/auth/logout.php",{},csrf);
      if(!response.ok) throw new Error("logout_failed");
      csrf = "";
      signedIn.hidden = true; workspace.hidden = false;show("login");
    } catch (_) {
      alert("Unable to sign out. Please refresh or try again.");
    }
  });

  async function verifyFromLink(token) {
    if(!/^[a-f0-9]{64}$/.test(token))return;
    state.textContent="Checking your one-time email verification link…";
    try {
      const {response,body}=await request("./api/auth/verify.php",{token});
      state.textContent=response.ok && body.ok
        ? "Email confirmed. You can now sign in."
        : "Verification link is expired or invalid. Please contact the artist for assistance.";
    }catch(_){
      state.textContent="Unable to verify your email at the moment.";
    }
  }
  async function initialize() {
    const fragment = new URLSearchParams(location.hash.slice(1));
    const verificationToken = fragment.get("verify");
    if(verificationToken)history.replaceState(null,"",location.pathname+location.search);
    try {
      const response = await fetch("./api/auth/status.php", {cache:"no-store"});
      if(!response.ok) throw new Error("unavailable");
      status=await response.json();
      if(!status.auth_available || !status.turnstile_site_key){
        state.textContent = verificationToken
          ? "Email verification isn't available yet. Keep your email link and return when account access opens."
          : "Registration and sign-in are not open yet. The artist is still preparing this feature.";
        return;
      }
      if(await currentAccount()) return;
      unavailable.hidden=true;workspace.hidden=false;
      signupTab.hidden = !status.registration_available;
      show("login");
      activateTurnstile(status.turnstile_site_key);
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
