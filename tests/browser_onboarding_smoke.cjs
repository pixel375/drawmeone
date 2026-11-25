"use strict";
const playwright = require("playwright");
const engine = process.env.DMO_BROWSER_ENGINE || "chromium";
if (!["chromium", "firefox", "webkit"].includes(engine)) {
  throw new Error("Unsupported DMO_BROWSER_ENGINE: " + engine);
}
const browserType = playwright[engine];
const assert=require("node:assert/strict");
const base="http://127.0.0.1:18868";
const TOKEN="a".repeat(64);
const captchaJS='window.turnstile={render(){return 19;},getResponse(){return "ci-synthetic-challenge";},reset(){}};';
const reply=(route,data,status=200)=>route.fulfill({status,contentType:"application/json",body:JSON.stringify(data)});
async function fakeService(page,options={}) {
  const counts={signup:0,resend:0,login:0,logout:0,verify:0,resetRequest:0,resetConfirm:0,security:0};
  const writes=[];
  let signedIn=!!options.signedIn;let sessions=2;let privacyCase=null;
  await page.route("https://challenges.cloudflare.com/turnstile/v0/api.js*",
    route=>route.fulfill({status:200,contentType:"application/javascript",body:captchaJS}));
  await page.route("**/api/auth/**",route=>{
    const request=route.request(),endpoint=new URL(request.url()).pathname.split("/").pop();
    if(request.method()!=="GET")writes.push({endpoint,payload:request.postDataJSON()});
    switch(endpoint){
      case "status.php":return reply(route,{ok:true,auth_available:!options.closed,
        registration_available:!options.closed&&!!options.registration,
        policy_version:"qa-policy-2026",policy_sha256:"a".repeat(64),
        turnstile_site_key:options.closed?null:"ci-only-key"});
      case "me.php":return reply(route,{ok:true,authenticated:signedIn,
        user:signedIn?{display_name:"CI Customer",email:"ci@example.invalid",role:"customer"}:undefined,
        privacy_self_service_enabled:!!options.privacy,active_sessions:signedIn?sessions:undefined,csrf_token:signedIn?"synthetic-csrf":undefined});
      case "signup.php":counts.signup++;return reply(route,{ok:true},202);
      case "resend-verification.php":counts.resend++;return reply(route,{ok:true},202);
      case "login.php":
        counts.login++;
        if(options.loginRateLimit&&counts.login===1)
          return reply(route,{ok:false,error:"too_many_attempts"},429);
        signedIn=true;return reply(route,{ok:true,status:"signed_in"});
      case "logout.php":
        counts.logout++;signedIn=false;return reply(route,{ok:true,status:"signed_out"});
      case "privacy.php":{
        if(request.method()==="GET")return reply(route,{ok:true,enabled:true,case:privacyCase});
        const p=request.postDataJSON();assert.equal(p.current_password,"ci-current-password");
        if(p.action==="export")return reply(route,{ok:true,export:{format:"drawmeone-customer-records-v1",profile:{email:"ci@example.invalid"}}});
        if(p.action==="submit"){privacyCase={case_id:17,version:17,kind:p.kind,status:"submitted",note:p.note,reply:""};return reply(route,{ok:true,case:privacyCase,data_deleted:false});}
        assert.equal(p.expected_version,17);privacyCase={...privacyCase,version:18,status:"withdrawn"};return reply(route,{ok:true,case:privacyCase,data_deleted:false});
      }
      case "security.php":{
        counts.security++;const p=request.postDataJSON();
        if(p.action==="change_password"){signedIn=false;return reply(route,{ok:true,status:"password_changed",requires_sign_in:true,sessions_revoked:sessions});}
        sessions=1;return reply(route,{ok:true,status:"other_sessions_revoked",requires_sign_in:false,sessions_revoked:1});
      }
      case "verify.php":
        counts.verify++;return options.invalidVerify
          ?reply(route,{ok:false,error:"invalid_or_expired_link"},422)
          :reply(route,{ok:true,status:"email_verified"});
      case "reset-request.php":
        counts.resetRequest++;
        return options.rateLimitReset&&counts.resetRequest===1
          ?reply(route,{ok:false,error:"too_many_attempts"},429)
          :reply(route,{ok:true},202);
      case "reset-confirm.php":
        counts.resetConfirm++;
        if(options.failResetOnce&&counts.resetConfirm===1)
          return reply(route,{ok:false,error:"account_service_unavailable"},503);
        return options.expiredReset
          ?reply(route,{ok:false,error:"invalid_or_expired_link"},422)
          :reply(route,{ok:true,status:"password_updated"});
      default:return reply(route,{ok:false,error:"unmocked"},404);
    }
  });
  return {counts,writes};
}
async function waitText(page,selector,phrase) {
  await page.waitForFunction(([selector,phrase])=>
    document.querySelector(selector)?.textContent?.includes(phrase),
    [selector,phrase],{timeout:12000});
}
async function overflow(page) {
  const result=await page.evaluate(()=>[document.documentElement.scrollWidth,
    document.documentElement.clientWidth]);
  assert(result[0]<=result[1]+3,"horizontal overflow "+result);
}
async function closed(browser,width) {
  const ctx=await browser.newContext({viewport:{width,height:844}});
  const page=await ctx.newPage(),m=await fakeService(page,{closed:true});
  try {
    await page.goto(base+"/account.html#verify="+TOKEN,{waitUntil:"domcontentloaded"});
    await waitText(page,"#service-state","Reopen the original link");
    assert.equal(await page.locator("#unavailable").isVisible(),true);
    assert.equal(await page.locator("#auth-workspace").isVisible(),false);
    assert.equal(new URL(page.url()).hash,"");
    assert.equal(m.counts.verify,0);
    await page.goto(base+"/password-reset.html#reset="+TOKEN,{waitUntil:"domcontentloaded"});
    await waitText(page,"#reset-state","Reopen the original link");
    assert.equal(await page.locator("#reset-workspace").isVisible(),false);
    assert.equal(new URL(page.url()).hash,"");
    await overflow(page);
    console.log("PASS: closed signup/verification/recovery "+width+"px");
  }finally{await ctx.close();}
}
async function signupAndSignIn(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const m=await fakeService(page,{registration:true,loginRateLimit:true});
  try {
    await page.goto(base+"/account.html",{waitUntil:"domcontentloaded"});
    await page.waitForFunction(()=>!document.querySelector("#submit-signup")?.disabled);
    await page.locator("#tab-signup").click();
    await page.locator("#signup-name").fill("CI Customer");
    await page.locator("#signup-email").fill("ci@example.invalid");
    await page.locator("#signup-password").fill("strong-ci-password-2026");
    await page.locator("#signup-password-confirm").fill("other-ci-password-2026");
    await page.locator("#signup-privacy-ack").check();
    await page.locator("#submit-signup").click();
    await waitText(page,"#form-notice","passwords do not match");
    assert.equal(m.counts.signup,0,"mismatching passwords blocked locally");
    await page.locator("#signup-password-confirm").fill("strong-ci-password-2026");
    await page.locator("#submit-signup").click();
    await waitText(page,"#form-notice","verification link");
    assert.equal(m.counts.signup,1);
    assert.equal(m.writes.find(x=>x.endpoint==="signup.php").payload.acknowledge_privacy,true);
    assert.equal(m.writes.find(x=>x.endpoint==="signup.php").payload.policy_sha256,"a".repeat(64));
    assert.equal(m.writes.find(x=>x.endpoint==="signup.php").payload.password,
      "strong-ci-password-2026");
    assert.equal(await page.locator("#signup-password").inputValue(),"");
    assert.equal(await page.locator("#signup-password-confirm").inputValue(),"");
    await page.locator("#toggle-resend").click();
    await page.locator("#resend-email").fill("ci@example.invalid");
    await page.locator("#submit-resend").click();
    await waitText(page,"#form-notice","pending account");
    assert.equal(m.counts.resend,1);
    await page.locator("#tab-login").click();
    await page.locator("#login-email").fill("ci@example.invalid");
    await page.locator("#login-password").fill("strong-ci-password-2026");
    await page.locator("#submit-login").click();
    await waitText(page,"#form-notice","Too many sign-in attempts");
    await page.locator("#login-password").fill("strong-ci-password-2026");
    await page.locator("#submit-login").click();
    await page.locator("#signed-in").waitFor({state:"visible"});
    assert.equal(await page.locator("#account-name").innerText(),"CI Customer");
    await page.locator("#sign-out").click();
    await page.locator("#login-form").waitFor({state:"visible"});
    assert.equal(m.counts.login,2);
    assert.equal(m.counts.logout,1);
    await overflow(page);
    console.log("PASS: signup confirmation, resend, login rate-limit and logout");
  }finally{await page.close();}
}
async function registrationClosedButLoginReady(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await fakeService(page,{registration:false});
  try{
    await page.goto(base+"/account.html",{waitUntil:"domcontentloaded"});
    await page.locator("#login-form").waitFor({state:"visible"});
    assert.equal(await page.locator("#signup-form").isVisible(),false);
    assert.equal(await page.locator("#tab-signup").isVisible(),false);
    console.log("PASS: login available while registration remains hidden");
  }finally{await page.close();}
}
async function verification(browser,invalid) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const m=await fakeService(page,{invalidVerify:invalid});
  try{
    await page.goto(base+"/account.html#verify="+TOKEN,{waitUntil:"domcontentloaded"});
    await waitText(page,"#form-notice",invalid?"expired or was already used":"Email confirmed");
    assert.equal(new URL(page.url()).hash,"");
    assert.equal(m.counts.verify,1);
    assert.equal(m.writes.find(x=>x.endpoint==="verify.php").payload.token,TOKEN);
    console.log("PASS: "+(invalid?"expired":"valid")+" email verification link");
  }finally{await page.close();}
}
async function requestReset(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const m=await fakeService(page,{rateLimitReset:true});
  try{
    await page.goto(base+"/password-reset.html",{waitUntil:"domcontentloaded"});
    await page.waitForFunction(()=>!document.querySelector("#reset-request-button")?.disabled);
    await page.locator("#reset-email").fill("ci@example.invalid");
    await page.locator("#reset-request-button").click();
    await waitText(page,"#reset-message","Too many recovery requests");
    await page.locator("#reset-request-button").click();
    await waitText(page,"#reset-message","If an eligible account exists");
    assert.equal(m.counts.resetRequest,2);
    console.log("PASS: reset request generic response and rate-limit retry");
  }finally{await page.close();}
}
async function confirmReset(browser,condition) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const m=await fakeService(page,{
    failResetOnce:condition==="temporary",expiredReset:condition==="expired"
  });
  try{
    await page.goto(base+"/password-reset.html#reset="+TOKEN,{waitUntil:"domcontentloaded"});
    await page.locator("#confirm-reset").waitFor({state:"visible"});
    assert.equal(new URL(page.url()).hash,"");
    await page.locator("#reset-password").fill("strong-new-ci-password-2026");
    await page.locator("#reset-confirm").fill("mismatched-ci-password-2026");
    await page.locator("#reset-confirm-button").click();
    await waitText(page,"#reset-message","passwords do not match");
    assert.equal(m.counts.resetConfirm,0);
    await page.locator("#reset-confirm").fill("strong-new-ci-password-2026");
    await page.locator("#reset-confirm-button").click();
    if(condition==="temporary"){
      await waitText(page,"#reset-message","could not be confirmed");
      assert.equal(await page.locator("#confirm-reset").isVisible(),true);
      assert.equal(await page.locator("#reset-password").inputValue(),"strong-new-ci-password-2026");
      await page.locator("#reset-confirm-button").click();
    }
    if(condition==="expired"){
      await waitText(page,"#reset-message","invalid, expired or already used");
      assert.equal(await page.locator("#confirm-reset").isVisible(),false);
      assert.equal(await page.locator("#reset-request-another").isVisible(),true);
      assert.equal(m.counts.resetConfirm,1);
      console.log("PASS: expired recovery link offers fresh link");
      return;
    }
    await waitText(page,"#reset-message","updated successfully");
    assert.equal(await page.locator("#confirm-reset").isVisible(),false);
    assert.equal(await page.locator("#reset-back-to-signin").isVisible(),true);
    assert.equal(await page.locator("#reset-password").inputValue(),"");
    assert.equal(m.counts.resetConfirm,condition==="temporary"?2:1);
    console.log("PASS: reset "+(condition==="temporary"?"temporary failure recovery":"success")+
      " and explicit sign-in link");
  }finally{await page.close();}
}
async function accountSecurity(browser){
  const page=await browser.newPage({viewport:{width:320,height:844}});
  const m=await fakeService(page,{signedIn:true});
  try{
    await page.goto(base+"/account.html",{waitUntil:"domcontentloaded"});
    await page.locator("#signed-in").waitFor();
    await waitText(page,"#account-session-count","2 active");
    await page.locator("#account-security summary").click();
    await page.locator("#security-current").fill("ci-current-password");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#security-submit").click();
    await waitText(page,"#security-notice","Other sessions signed out");
    assert.equal(await page.locator("#security-current").inputValue(),"");
    assert.equal(m.counts.security,1);
    await page.locator("#security-action").selectOption("change_password");
    await page.locator("#security-current").fill("ci-current-password");
    await page.locator("#security-new").fill("ci-new-password-2026");
    await page.locator("#security-confirm").fill("ci-other-password-2026");
    await page.locator("#security-submit").click();
    await waitText(page,"#security-notice","do not match");
    assert.equal(m.counts.security,1);
    await page.locator("#security-confirm").fill("ci-new-password-2026");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#security-submit").click();
    await waitText(page,"#form-notice","Password changed");
    assert.equal(await page.locator("#signed-in").isVisible(),false);
    assert.equal(await page.locator("#account-email").textContent(),"");
    assert.equal(m.counts.security,2);
    await page.waitForFunction(()=>!document.querySelector("#submit-login")?.disabled);
    await overflow(page);
    console.log("PASS: device revocation, password change and fresh sign-in");
  }finally{await page.close();}
}
async function privacySelfService(browser){
  const page=await browser.newPage({viewport:{width:320,height:844},acceptDownloads:true});
  const m=await fakeService(page,{signedIn:true,privacy:true});
  try{
    await page.goto(base+"/account.html",{waitUntil:"domcontentloaded"});
    await page.locator("#account-privacy summary").click();
    await waitText(page,"#privacy-case","No privacy requests");
    await page.locator("#privacy-password").fill("ci-current-password");
    const downloaded=page.waitForEvent("download");
    await page.locator("#privacy-submit").click();
    const file=await downloaded;
    assert.equal(file.suggestedFilename(),"drawmeone-customer-records.json");
    const content=JSON.parse(require("node:fs").readFileSync(await file.path(),"utf8"));
    assert.equal(content.profile.email,"ci@example.invalid");
    await waitText(page,"#privacy-notice","Export prepared");
    await page.locator("#privacy-action").selectOption("deletion");
    await page.locator("#privacy-note").fill("Synthetic privacy request.");
    await page.locator("#privacy-password").fill("ci-current-password");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#privacy-submit").click();
    await waitText(page,"#privacy-case","submitted");
    assert.equal(await page.locator("#privacy-password").inputValue(),"");
    assert.equal(await page.locator("#privacy-action option[value=deletion]").isDisabled(),true);
    await page.locator("#privacy-password").fill("ci-current-password");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#privacy-withdraw").click();
    await waitText(page,"#privacy-case","withdrawn");
    assert.equal(await page.locator("#privacy-withdraw").isVisible(),false);
    assert.equal(m.writes.filter(x=>x.endpoint==="privacy.php").length,3);
    await overflow(page);
    console.log("PASS: private JSON export, deletion request and withdrawal UI");
  }finally{await page.close();}
}
(async()=>{
  const browser=await browserType.launch({headless:true});
  try {
    for(const width of [320,390,768])await closed(browser,width);
    await registrationClosedButLoginReady(browser);
    await signupAndSignIn(browser);
    await accountSecurity(browser);
    await privacySelfService(browser);
    await verification(browser,false);
    await verification(browser,true);
    await requestReset(browser);
    await confirmReset(browser,"success");
    await confirmReset(browser,"temporary");
    await confirmReset(browser,"expired");
    console.log("ONBOARDING_BROWSER_SMOKE=PASS");
  }finally{await browser.close();}
})().catch(err=>{console.error(err.stack||String(err));process.exitCode=1;});
