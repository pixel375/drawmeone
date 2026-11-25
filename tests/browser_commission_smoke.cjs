"use strict";
const playwright = require("playwright");
const engine = process.env.DMO_BROWSER_ENGINE || "chromium";
if (!["chromium", "firefox", "webkit"].includes(engine)) {
  throw new Error("Unsupported DMO_BROWSER_ENGINE: " + engine);
}
const browserType = playwright[engine];
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const base="http://127.0.0.1:18868";
const output=path.join(process.cwd(),"test-results");
fs.mkdirSync(output,{recursive:true});

const A="DMO-"+"a".repeat(20);
const B="DMO-"+"b".repeat(20);
const C="DMO-"+"c".repeat(20);
const requests=[
  {public_ref:A,subject_type:"portrait",tier_name:"Portrait",status:"submitted",submitted_at:"2026-11-22 18:00:00"},
  {public_ref:B,subject_type:"original_character",tier_name:"Character",status:"clarification_needed",submitted_at:"2026-11-21 20:00:00"},
  {public_ref:C,subject_type:"other",tier_name:null,status:"completed",submitted_at:"2026-11-18 11:00:00"}
];
function detail(ref) {
  const item=requests.find(row=>row.public_ref===ref);
  if(!item) return {ok:false,error:"request_not_found"};
  return {
    ok:true,
    request:{
      public_ref:ref,subject_type:item.subject_type,brief:"A long, synthetic artwork brief for a private commission, used only for visual testing.",
      preferred_contact:"onsite",preferred_deadline:null,status:item.status,
      tier_name:item.tier_name,policy_version:"qa-policy-2026"
    },
    addons:[],references:[],can_manage_references:false,
    can_upload_references:false,can_reply:false,
    messages:ref===A?[{
      sender_name:"Fake Artist",body:"Synthetic message visible to only this account.",
      created_at:"2026-11-23"
    }]:[],
    quotes:ref===A?[{
      id:19,version_number:1,status:"sent",
      scope_description:"Drawing a portrait on a simple background",
      total_minor:12500,currency:"CAD",revision_allowance:1,
      validity_ends_at:"2026-12-03 20:00:00",is_expired:false,
      can_respond:true,lines:[{description:"Portrait",amount_minor:12500}]
    }]:[],
    orders:ref===A?[{
      id:9,status:"awaiting_arrangement",payment_status:"unverified",
      total_minor:12500,currency:"CAD",created_at:"2026-11-23"
    }]:[],
    payments_enabled:false
  };
}
const answer=(route,data,status=200)=>route.fulfill({
  status,contentType:"application/json",body:JSON.stringify(data)
});
async function installMocks(page,options={}) {
  const posts=[];
  let storedRequests=options.requests||requests;
  let submitTimes=0;
  await page.route("**/api/**",async route=>{
    const req=route.request(), url=new URL(req.url());
    const endpoint=url.pathname;
    if(req.method()!=="GET"){
      posts.push({endpoint,method:req.method(),body:req.postDataJSON?.()});
      if(options.enableSubmission && endpoint==="/api/requests.php"){
        submitTimes++;
        if(options.submissionFails) return route.abort("failed");
        const payload=req.postDataJSON();
        return answer(route,{
          ok:true,public_ref:"DMO-"+"d".repeat(20),status:"submitted",
          submitted_payload_kind:typeof payload.idempotency_key
        },201);
      }
      return answer(route,{ok:false,error:"synthetic_mutations_disabled"},503);
    }
    if(endpoint==="/api/request-status.php") return answer(route,{
      ok:true,requests_open:!!options.open,text_only:true,
      policy_version:options.open?"qa-policy-2026":null,policy_sha256:options.open?"a".repeat(64):null
    });
    if(endpoint==="/api/catalog.php") return answer(route,{
      ok:true,requests_open:!!options.open,currency_pricing_approved:false,
      tiers:[{
        id:12,name:"Portrait",description:"A simple portrait.",
        estimate_min_minor:5000,estimate_max_minor:15000,currency:"CAD",
        available_addon_ids:[31]
      }],
      addons:[
        {id:31,name:"Additional subject",estimate_min_minor:2000,
         estimate_max_minor:2000,currency:"CAD"},
        {id:32,name:"Detailed background",estimate_min_minor:3000,
         estimate_max_minor:3000,currency:"CAD"}
      ]
    });
    if(endpoint==="/api/auth/status.php") return answer(route,{
      ok:true,auth_available:true,registration_available:false
    });
    if(endpoint==="/api/auth/me.php") return answer(route,{
      ok:true,authenticated:!!options.signedIn,
      user:options.signedIn?{
        display_name:"Fake Customer",role:"customer",email:"fake@example.invalid"
      }:undefined,
      csrf_token:options.signedIn?"synthetic-only-csrf":undefined
    });
    if(endpoint==="/api/requests.php") return answer(route,{
      ok:true,requests:storedRequests,new_requests_open:!!options.open
    });
    if(endpoint==="/api/request.php") {
      const ref=url.searchParams.get("ref");
      return answer(route,detail(ref),requests.some(row=>row.public_ref===ref)?200:404);
    }
    return answer(route,{ok:false,error:"test_endpoint_not_mocked"},404);
  });
  return {posts,get submissions(){return submitTimes;}};
}
async function checkOverflow(page,context) {
  const measured=await page.evaluate(()=>({
    width:document.documentElement.clientWidth,
    content:document.documentElement.scrollWidth
  }));
  assert(measured.content<=measured.width+3,
    context+" horizontal overflow "+JSON.stringify(measured));
}
async function signedInClosed(browser,width) {
  const context=await browser.newContext({
    viewport:{width,height:900},reducedMotion:"reduce",deviceScaleFactor:1
  });
  const page=await context.newPage();
  const jsErrors=[];
  page.on("pageerror",err=>jsErrors.push(err.message));
  const test=await installMocks(page,{signedIn:true,open:false});
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#my-request-list .request-list-item").first()
      .waitFor({state:"visible",timeout:12000});
    assert.equal(await page.locator("#customer-workspace").isVisible(),true);
    assert.equal(await page.locator("#request-create-panel").isVisible(),false);
    assert.equal(await page.locator("#request-closed").isVisible(),false);
    assert.match(await page.locator("#request-status").innerText(),/commissions are closed/i);
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),3);
    assert.match(await page.locator("#request-history-count").innerText(),/3 of 3/);

    await page.locator("#request-history-filter").selectOption("active");
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),2);
    await page.locator("#request-history-filter").selectOption("closed");
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),1);
    assert.match(await page.locator("#my-request-list").innerText(),/Completed/);

    await page.locator("#request-history-filter").selectOption("all");
    await page.locator("#request-history-search").fill(B.slice(-8));
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),1);
    assert.match(await page.locator("#my-request-list").innerText(),/Clarification requested/);
    await page.locator("#request-history-search").fill("nothing-matches-1234");
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),0);
    assert.match(await page.locator("#my-request-list").innerText(),/No requests match/);
    assert.match(await page.locator("#request-history-count").innerText(),/0 of 3/);

    await page.locator("#request-history-search").fill("");
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-quote-section").waitFor({state:"visible"});
    assert.equal(await page.locator("#detail-status").innerText(),"Awaiting artist review");
    assert.match(await page.locator("#detail-brief").innerText(),/synthetic artwork brief/);
    assert.match(await page.locator("#detail-quotes").innerText(),/\$125\.00/);
    assert.match(await page.locator("#detail-orders").innerText(),/Payment has not been verified/);
    assert.equal(await page.locator("#detail-reference-form").isVisible(),false);
    assert.equal(await page.locator("#detail-reply-form").isVisible(),false);
    assert.equal(await page.locator("#detail-quote-section button").count(),2);
    assert.equal(await page.locator("#detail-quote-section input[type=checkbox]").count(),1);
    assert.equal(await page.locator('[data-quote-action="accept"]').isDisabled(),true,
      "quote acceptance disabled until acknowledged");
    assert.equal(await page.locator('[data-quote-action="decline"]').isEnabled(),true,
      "quote can still be declined separately");
    await page.locator("#detail-refresh").click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    assert.match(await page.locator("#detail-quote-section").innerText(),/Awaiting your decision/);
    assert(!await page.locator("body").innerText().then(x=>x.includes("Fake hidden internal note")));
    await page.locator("#detail-close").click();
    assert.equal(await page.locator("#my-request-detail").isVisible(),false);
    await page.locator("#refresh-requests").click();
    await page.locator("#my-request-list .request-list-item").first().waitFor();
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),3);
    assert.deepEqual(test.posts,[],"no account modifications during read-only browser tests");
    assert.deepEqual(jsErrors,[],"no JavaScript errors");
    await checkOverflow(page,"customer history "+width);
    if(width===390||width===1440){
      await page.screenshot({
        path:path.join(output,"commission-history-"+width+".png"),
        animations:"disabled",fullPage:false
      });
    }
    console.log("PASS: signed-in closed-intake history, filters, private detail, quote/order display at "+width+"px");
  } finally {await context.close();}
}
async function quoteDecisionGuards(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const test=await installMocks(page,{signedIn:true,open:false});
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]')
      .waitFor({state:"visible"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    const accept=page.locator('[data-quote-action="accept"]');
    const decline=page.locator('[data-quote-action="decline"]');
    assert.equal(await accept.isDisabled(),true);
    await page.locator(".customer-quote-confirm input").check();
    assert.equal(await accept.isEnabled(),true,"acknowledgement enables Accept");
    page.once("dialog",dialog=>dialog.accept());
    await accept.click();
    await page.waitForFunction(()=>
      document.querySelector("#detail-quote-feedback")?.textContent
        ?.includes("Could not record decision"));
    assert.equal(await accept.isEnabled(),true,"failed quote response restores Accept");
    assert.equal(await decline.isEnabled(),true,"failed quote response restores Decline");
    assert.equal(test.posts.length,1,"one mocked quote decision intercepted");
    assert.equal(test.posts[0].endpoint,"/api/quote-response.php");
    assert.equal(test.posts[0].body.quote_id,19);
    assert.equal(test.posts[0].body.acknowledge_quote,true);
    assert.equal(test.posts[0].body.decision,"accept");
    await page.locator("#detail-close").click();
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    assert.equal(await page.locator('[data-quote-action="accept"]').isDisabled(),true,
      "reopened quote requires fresh acknowledgement");
    console.log("PASS: acknowledgement required, failed quote response restores controls");
  } finally {await page.close();}
}
async function quoteSuccessWithoutPayment(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await installMocks(page,{signedIn:true,open:false});
  let accepted=false,decisionCount=0;
  await page.route("**/api/request.php*",route=>{
    const result=detail(A);
    if(accepted) {
      result.quotes[0].status="accepted";
      result.quotes[0].can_respond=false;
    }
    return answer(route,result);
  });
  await page.route("**/api/quote-response.php",route=>{
    const submitted=route.request().postDataJSON();
    assert.equal(submitted.quote_id,19);
    assert.equal(submitted.decision,"accept");
    assert.equal(submitted.acknowledge_quote,true);
    assert.equal(submitted.policy_version,"qa-policy-2026");
    decisionCount++;
    accepted=true;
    return answer(route,{
      ok:true,quote_id:19,status:"accepted",
      order_created:false,payment_collected:false
    });
  });
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    await page.locator(".customer-quote-confirm input").check();
    page.once("dialog",dialog=>dialog.accept());
    await page.locator('[data-quote-action="accept"]').click();
    await page.waitForFunction(()=>
      document.querySelector(".customer-quote-status")?.textContent === "Accepted");
    assert.equal(decisionCount,1,"one confirmed synthetic quote acceptance");
    assert.equal(await page.locator("#detail-summary").getAttribute("href"),"./commission-summary.php?ref="+A);
    assert.equal(await page.locator("#detail-summary").isVisible(),true);
    assert.equal(await page.locator("#detail-quotes button").count(),0,
      "accepted quote becomes read-only");
    assert.match(await page.locator("#detail-quote-feedback").innerText(),
      /No order or payment has been created/);
    console.log("PASS: confirmed quote acceptance updates display without payment or new order");
  } finally {await page.close();}
}
async function readonlyQuoteStatuses(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await installMocks(page,{signedIn:true,open:false});
  await page.route("**/api/request.php*",route=>{
    const result=detail(A);
    result.quotes=[
      {...result.quotes[0],id:20,version_number:2,is_expired:true,
       can_respond:true,status:"sent"},
      {...result.quotes[0],id:19,version_number:1,status:"superseded",
       can_respond:true,is_expired:false},
    ];
    return answer(route,result);
  });
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    assert.equal(await page.locator(".customer-quote-card").count(),2);
    assert.match(await page.locator("#detail-quotes").innerText(),/Expired/);
    assert.match(await page.locator("#detail-quotes").innerText(),/No longer available/);
    assert.equal(await page.locator("#detail-quotes button").count(),0,
      "expired/superseded quotes cannot be accepted or declined");
    console.log("PASS: historical and expired quote statuses remain read-only");
  } finally {await page.close();}
}
async function detailRaceAndRecovery(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await installMocks(page,{signedIn:true,open:false});
  let delayed=true;
  await page.route("**/api/request.php*",async route=>{
    const ref=new URL(route.request().url()).searchParams.get("ref");
    if(ref===A && delayed){
      delayed=false;
      await new Promise(resolve=>setTimeout(resolve,950));
    }
    return answer(route,detail(ref));
  });
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').waitFor();
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    assert.equal(await page.locator("#detail-content").isVisible(),false,
      "no old request data displayed during loading");
    await page.locator('#my-request-list button[aria-label$="'+B+'"]').click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    assert.equal(await page.locator("#detail-ref").innerText(),B);
    await page.waitForTimeout(1050);
    assert.equal(await page.locator("#detail-ref").innerText(),B,
      "slower request A cannot replace selected request B");
    assert.equal(await page.locator("#detail-quote-section").isVisible(),false,
      "request B must not inherit quote A");
    // A transient server failure may be recovered by the refresh control.
    let fail=true;
    await page.route("**/api/request.php*",route=>{
      if(fail){fail=false;return answer(route,{ok:false,error:"unavailable"},503);}
      return answer(route,detail(B));
    });
    await page.locator("#detail-refresh").click();
    await page.waitForFunction(() =>
      document.querySelector("#detail-load-status")?.textContent
        ?.includes("could not be loaded"));
    assert.match(await page.locator("#detail-load-status").innerText(),/could not be loaded/i);
    assert.equal(await page.locator("#detail-content").isVisible(),false,
      "failed request does not show stale private detail");
    await page.locator("#detail-refresh").click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    assert.equal(await page.locator("#detail-ref").innerText(),B);
    console.log("PASS: out-of-order detail responses isolated; refresh recovers failures");
  } finally {await page.close();}
}
async function openIntake(browser) {
  const context=await browser.newContext({
    viewport:{width:390,height:844},reducedMotion:"reduce"
  });
  const page=await context.newPage();
  const test=await installMocks(page,{signedIn:true,open:true,enableSubmission:true});
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#request-submit").waitFor({state:"visible"});
    assert.equal(await page.locator("#request-create-panel").isVisible(),true);
    assert.equal(await page.locator("#request-submit").isEnabled(),true);
    await page.locator("#request-tier").selectOption("12");
    await page.locator('#addons-options input[value="31"]').check();
    assert.equal(await page.locator('#addons-options input[value="32"]').isVisible(),false,
      "incompatible add-on hidden");
    assert.equal(await page.locator('#addons-options input[value="32"]').isDisabled(),true);
    await page.locator("#request-brief").fill(
      "Synthetic request for a portrait with warm colors and a calm background."
    );
    await page.locator("#request-ack").check();
    await page.locator("#request-submit").click();
    await page.locator("#request-feedback").waitFor({state:"visible"});
    await page.waitForFunction(()=>
      document.querySelector("#request-feedback")?.textContent?.includes("received for artist review"));
    assert.equal(test.submissions,1,"exactly one POST for request creation");
    assert.equal(test.posts[0].endpoint,"/api/requests.php");
    assert.equal(test.posts[0].body.acknowledge_review,true);
    assert.equal(test.posts[0].body.policy_sha256,"a".repeat(64));
    assert.match(await page.locator("#request-policy-link").getAttribute("href"),/version=qa-policy-2026&sha256=/);
    assert.equal(test.posts[0].body.tier_id,12);
    assert.deepEqual(test.posts[0].body.addon_ids,[31]);
    assert.match(test.posts[0].body.idempotency_key,
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.equal(await page.locator("#request-brief").inputValue(),"");
    assert.equal(await page.locator("#request-ack").isChecked(),false);
    console.log("PASS: synthetic signed-in commission form, tier/addon compatibility, single idempotent POST");
  } finally {await context.close();}
}
async function verifySubmissionConflict(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await installMocks(page,{signedIn:true,open:true});
  const keys=[];
  await page.route("**/api/requests.php",route=>{
    if(route.request().method()==="GET")return answer(route,{ok:true,requests});
    keys.push(route.request().postDataJSON().idempotency_key);
    return keys.length<2?answer(route,{ok:false,error:"request_key_conflict",public_ref:A},409):answer(route,{ok:true,public_ref:B},201);
  });
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#request-submit").waitFor();
    await page.waitForFunction(()=>document.querySelector("#request-submit")?.disabled===false);
    const brief="A distinct synthetic commission idea with enough detail for review.";
    await page.locator("#request-brief").fill(brief);
    await page.locator("#request-ack").check();
    await page.locator("#request-submit").click();
    await page.locator("#request-new-key").waitFor({state:"visible"});
    assert.equal(await page.locator("#request-brief").inputValue(),brief);
    page.once("dialog",d=>d.accept());
    await page.locator("#request-new-key").click();
    await page.locator("#request-submit").click();
    await page.waitForFunction(()=>document.querySelector("#request-feedback")?.textContent.includes("received for artist review"));
    assert.equal(keys.length,2);assert.notEqual(keys[0],keys[1]);
    console.log("PASS: conflicting request retry preserves brief until explicit separate submission");
  }finally{await page.close();}
}
async function customerDraftAndSessionIsolation(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion:"reduce"});
  const test=await installMocks(page,{signedIn:true,open:false});
  let expired=false;
  await page.route("**/api/request.php?*",route=>{
    const ref=new URL(route.request().url()).searchParams.get("ref");
    return expired ? answer(route,{ok:false,error:"customer_login_required"},401)
      : answer(route,{...detail(ref),can_reply:true,can_upload_references:true});
  });
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    const button=ref=>page.locator('#my-request-list button[aria-label$="'+ref+'"]');
    await button(A).click();
    await page.locator("#detail-reply-form").waitFor({state:"visible"});
    await page.locator("#detail-reply-body").fill("Synthetic private message A");
    await page.locator("#detail-reference-files").setInputFiles({
      name:"synthetic.png",mimeType:"image/png",buffer:Buffer.from("synthetic fixture")
    });
    assert.match(await page.locator("#detail-drafts-warning").innerText(),/private message, selected reference images/);
    page.once("dialog",d=>d.dismiss());
    await button(B).click();
    assert.equal(await page.locator("#detail-reply-body").inputValue(),"Synthetic private message A");
    page.once("dialog",d=>d.accept());
    await button(B).click();
    await page.waitForFunction(ref=>document.querySelector("#detail-ref")?.textContent===ref,B);
    assert.equal(await page.locator("#detail-reply-body").inputValue(),"");
    assert.equal(await page.locator("#detail-reference-files").evaluate(el=>el.files.length),0);
    await page.locator("#detail-reply-body").fill("Synthetic private message B");
    page.once("dialog",d=>d.dismiss());
    await page.locator("#detail-close").click();
    assert.equal(await page.locator("#my-request-detail").isVisible(),true);
    page.once("dialog",d=>d.accept());
    await page.locator("#detail-close").click();
    await button(B).click();
    await page.waitForFunction(ref=>document.querySelector("#detail-ref")?.textContent===ref,B);
    assert.equal(await page.locator("#detail-reply-body").inputValue(),"");
    expired=true;
    await page.locator("#detail-refresh").click();
    await page.waitForURL("**/account.html");
    assert.equal(await page.locator("#customer-workspace").count(),0);
    assert.deepEqual(test.posts,[]);
    console.log("PASS: customer draft/reference isolation and expired session exits private document");
  }finally{await page.close();}
}

async function verifyReferenceRecovery(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await installMocks(page,{signedIn:true,open:false});
  await page.route("**/api/request.php?*",route=>answer(route,{...detail(A),can_upload_references:true,can_reply:true}));
  const uploaded=[];
  await page.route("**/api/request-attachment.php",route=>{
    const body=route.request().postData()||"";
    uploaded.push(body.includes("first.png")?"first":body.includes("second.png")?"second":body.includes("third.png")?"third":"unknown");
    if(uploaded.length===2)return route.fulfill({status:502,contentType:"text/plain",body:"Gateway failure"});
    return answer(route,{ok:true,attachment_id:uploaded.length},201);
  });
  let messageCalls=0,keys=[];
  await page.route("**/api/request-message.php",route=>{
    keys.push(route.request().postDataJSON().operation_key);messageCalls++;
    return messageCalls===1?route.abort("failed"):answer(route,{ok:true,message_id:91,already_saved:true});
  });
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-reference-form").waitFor({state:"visible"});
    await page.locator("#detail-reference-files").setInputFiles(["first.png","second.png","third.png"].map(name=>({name,mimeType:"image/png",buffer:Buffer.from("synthetic reference")})));
    await page.locator("#detail-reference-upload").click();
    await page.waitForFunction(()=>document.querySelector("#detail-reference-feedback")?.textContent.includes("1 unconfirmed · 1 remaining"));
    assert.deepEqual(uploaded,["first","second"]);
    await page.locator("#detail-reference-resume").click();
    await page.waitForFunction(()=>document.querySelector("#detail-reference-feedback")?.textContent.includes("0 unconfirmed · 0 remaining"));
    assert.deepEqual(uploaded,["first","second","third"]);
    await page.locator("#detail-reply-body").fill("Synthetic retry message");
    await page.locator("#detail-reply-send").click();
    await page.waitForFunction(()=>document.querySelector("#detail-reply-feedback")?.textContent.includes("Retry the unchanged"));
    await page.locator("#detail-reply-send").click();
    await page.waitForFunction(()=>document.querySelector("#detail-reply-feedback")?.textContent.includes("Message saved"));
    assert.equal(keys.length,2);assert.equal(keys[0],keys[1]);assert.match(keys[0],/^[0-9a-f-]{36}$/);
    assert.equal(await page.locator("#detail-reply-body").inputValue(),"");
    console.log("PASS: private reference upload recovery and unchanged message operation key");
  }finally{await page.close();}
}
async function verifyOlderCustomerHistory(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const test=await installMocks(page,{signedIn:true,open:false});
  let failOlder=true;
  const pages=[];
  await page.route("**/api/requests.php?*",route=>{
    const n=Number(new URL(route.request().url()).searchParams.get("page"));
    pages.push(n);
    if(n===2&&failOlder){failOlder=false;return answer(route,{ok:false},503);}
    return answer(route,{ok:true,page:n,has_more:n===1,requests:n===1?[requests[0],requests[1]]:[requests[1],requests[2]]});
  });
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    const older=page.locator("#request-history-older");
    await older.waitFor({state:"visible"});
    await page.locator("#request-history-filter").selectOption("closed");
    assert.match(await page.locator("#my-request-list").innerText(),/Load older requests/);
    await older.click();
    await page.waitForFunction(()=>document.querySelector("#request-history-feedback")?.textContent.includes("Could not load"));
    assert.equal(await older.isEnabled(),true);
    await older.click();
    await page.waitForFunction(()=>document.querySelector("#my-request-list")?.textContent.includes("Completed"));
    assert.equal(await older.isVisible(),false);
    await page.locator("#request-history-filter").selectOption("all");
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),3);
    await page.locator("#refresh-requests").click();
    await older.waitFor({state:"visible"});
    assert.equal(await page.locator("#my-request-list .request-list-item").count(),2);
    assert.deepEqual(pages,[1,2,2,1]);
    await checkOverflow(page,"customer history older pages");
    assert.deepEqual(test.posts,[]);
    console.log("PASS: customer older history, retry, deduplication and refresh at 320px");
  }finally{await page.close();}
}

async function verifyFullCustomerThread(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const test=await installMocks(page,{signedIn:true,open:false});
  await page.route("**/api/request.php?*",route=>{
    const url=new URL(route.request().url()),ref=url.searchParams.get("ref"),full=url.searchParams.get("thread")==="full";
    return answer(route,{...detail(ref),messages_have_older:!full,messages:[{sender_name:"Synthetic artist",body:full?"Earlier visible message":"Recent visible message"}]});
  });
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-thread-full").click();
    await page.waitForFunction(()=>document.querySelector("#detail-messages")?.textContent.includes("Earlier visible message"));
    assert.equal(await page.locator("#detail-thread-full").isVisible(),false);
    await checkOverflow(page,"full customer thread");
    assert.deepEqual(test.posts,[]);
    console.log("PASS: customer full conversation history at 320px");
  }finally{await page.close();}
}

async function verifyOrderHistoryStage(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const test=await installMocks(page,{signedIn:true,open:false});
  await page.route("**/api/requests.php?*",route=>answer(route,{ok:true,requests:[
    {...requests[0],workflow_stage:"awaiting_arrangement",order_deadline:"2027-01-15"},
    {...requests[1],workflow_stage:"quote_available"}],has_more:false}));
  await page.route("**/api/request.php?*",route=>answer(route,{...detail(A),orders:[{id:71,status:"awaiting_arrangement",payment_status:"unverified",agreed_deadline:"2027-01-15",total_minor:3000,currency:"CAD"}],checkout_links:[{id:72,order_id:71,provider_host:"checkout.example.invalid",expires_at:"2027-01-10 00:00:00"}]}));
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#my-request-list .request-list-item").first().waitFor();
    assert.match(await page.locator("#my-request-list").innerText(),/Awaiting payment arrangements/);
    assert.match(await page.locator("#my-request-list").innerText(),/2027-01-15/);
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-orders").waitFor({state:"visible"});
    assert.match(await page.locator("#detail-orders").innerText(),/Agreed delivery date: 2027-01-15/);
    assert.match(await page.locator("#detail-orders").innerText(),/unverified/);
    const invoice=page.getByRole("link",{name:"Open external invoice"});
    assert.equal(await invoice.getAttribute("href"),"./api/checkout.php?id=72");
    assert.equal(await invoice.getAttribute("rel"),"noopener noreferrer");
    await checkOverflow(page,"customer order history");
    assert.deepEqual(test.posts,[]);
  }finally{await page.close();}
}

async function verifyRequestWithdrawal(browser){
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  await installMocks(page,{signedIn:true,open:false});
  let withdrawn=false;
  await page.route("**/api/request.php?*",route=>{
    const data=detail(A);data.request.status=withdrawn?"cancelled":"submitted";data.request.workflow_stage=data.request.status;
    return answer(route,{...data,can_withdraw:!withdrawn,can_reply:!withdrawn});
  });
  await page.route("**/api/request-withdraw.php",route=>{
    assert.deepEqual(route.request().postDataJSON(),{ref:A,expected_status:"submitted",confirm_withdrawal:true});
    withdrawn=true;return answer(route,{ok:true,status:"cancelled",money_moved:false});
  });
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-withdraw").waitFor({state:"visible"});
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#detail-withdraw").click();
    await page.waitForFunction(()=>document.querySelector("#detail-status")?.textContent.includes("Cancelled"));
    assert.equal(await page.locator("#detail-withdraw").isVisible(),false);
    assert.equal(withdrawn,true);
    console.log("PASS: customer withdraws unaccepted request");
  }finally{await page.close();}
}
async function verifyCustomerAttention(browser){
  const page=await browser.newPage({viewport:{width:320,height:844}});
  const mock=await installMocks(page,{signedIn:true,open:false});
  await page.route("**/api/requests.php?*",route=>answer(route,{ok:true,requests:[requests[1]],attention:{quotes:[A],proofs:[],deliveries:[],invoices:[]}}));
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#customer-attention").waitFor({state:"visible"});
    assert.equal(await page.locator("#my-request-list button").count(),1);
    await page.getByRole("button",{name:"Review quote for "+A}).click();
    await page.waitForFunction(ref=>document.querySelector("#detail-ref")?.textContent.includes(ref),A);
    await checkOverflow(page,"customer attention");
    assert.deepEqual(mock.posts,[]);
    console.log("PASS: attention shortcut opens older request without loading history");
  }finally{await page.close();}
}
async function verifyPublicServiceFailureIsolation(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const mock=await installMocks(page,{signedIn:true,open:true});
  await page.route("**/api/catalog.php",route=>route.fulfill({status:503,body:"temporarily unavailable"}));
  await page.route("**/api/request-status.php",route=>route.abort("failed"));
  try{
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#my-request-list .request-list-item").first().waitFor({state:"visible"});
    assert.equal(await page.locator("#customer-workspace").isVisible(),true);
    assert.equal(await page.locator("#request-create-panel").isVisible(),false);
    assert.match(await page.locator("#request-status").innerText(),/existing commissions remain accessible/);
    await page.locator('#my-request-list button[aria-label$="'+A+'"]').click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    assert.deepEqual(mock.posts,[]);
    console.log("PASS: public service failure does not hide signed-in history");
  }finally{await page.close();}
}
async function openWithoutLogin(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await installMocks(page,{signedIn:false,open:true});
  try {
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#request-signin").waitFor({state:"visible"});
    assert.equal(await page.locator("#request-create-panel").isVisible(),false);
    assert.equal(await page.locator("#customer-workspace").isVisible(),false);
    console.log("PASS: open intake requires authenticated customer");
  } finally {await page.close();}
}
(async()=>{
  const browser=await browserType.launch({headless:true});
  try {
    for(const width of [320,390,768,1440]) await signedInClosed(browser,width);
    await openIntake(browser);
    await verifySubmissionConflict(browser);
    await openWithoutLogin(browser);
    await quoteDecisionGuards(browser);
    await quoteSuccessWithoutPayment(browser);
    await readonlyQuoteStatuses(browser);
    await detailRaceAndRecovery(browser);
    await customerDraftAndSessionIsolation(browser);
    await verifyReferenceRecovery(browser);
    await verifyOlderCustomerHistory(browser);
    await verifyFullCustomerThread(browser);
    await verifyOrderHistoryStage(browser);
    await verifyRequestWithdrawal(browser);
    await verifyCustomerAttention(browser);
    await verifyPublicServiceFailureIsolation(browser);
    console.log("COMMISSION_BROWSER_SMOKE=PASS");
  } finally {await browser.close();}
})().catch(err=>{console.error(err.stack||String(err));process.exitCode=1;});
