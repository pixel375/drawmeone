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

const base = "http://127.0.0.1:18868";
const output = path.join(process.cwd(), "test-results");
fs.mkdirSync(output, { recursive: true });

const mockAdmin = {
  "status.php": {
    ok: true, admin_schema_ready: true, admin_available: true,
    turnstile_site_key: "ci-only-unusable-site-key"
  },
  "me.php": {
    ok: true, authenticated: true,
    admin: {display_name: "Synthetic QA Artist",email: "fake-admin@example.invalid"},
    csrf_token: "ci-browser-only-no-server-credentials"
  },
  "overview.php": {
    ok: true, readonly: true, counts: {
      requests_for_review: 0, active_orders: 0,
      payments_to_verify: 0, messages_last_seven_days: 0
    },
    readiness: [
      {key:"mail",label:"Transactional email",status:"ready",detail:"CI mock only"},
      {key:"accounts",label:"Customer accounts",status:"blocked",detail:"Closed"},
      {key:"payments",label:"Verified payments",status:"planned",detail:"Not launched"}
    ]
  },
  "requests.php": {ok:true,readonly:true,requests:[]},
  "messages.php": {ok:true,readonly:true,messages:[]},
  "artworks.php": {
    ok:true,write_enabled:false,
    artworks:[
      {id:4,visibility:"draft",featured:false,sort_order:0,
       width:620,height:420,created_at:"2026-11-24"},
      {id:5,visibility:"published",featured:true,sort_order:2,
       width:320,height:240,created_at:"2026-11-24"}
    ]
  },
  "packages.php": {
    ok:true,write_enabled:false,publish_enabled:false,
    catalog:{tiers:[],addons:[]}
  },
  "design.php": {
    ok:true,write_enabled:false,
    draft:{exists:false,version:0},published:{exists:false}
  },
  "email.php": {
    ok:true,mail_ready:false,transport:"none",smtp_settings_present:false,
    customer_login_enabled:false,customer_registration_enabled:false
  }
};
const tabs = ["overview","requests","messages","artwork","packages","settings"];
async function simulateBackend(page, observedPosts) {
  await page.route("**/api/admin/**", route => {
    if (route.request().method() !== "GET") {
      observedPosts.push(route.request().url());
      return route.fulfill({
        status: 503, contentType: "application/json",
        body: JSON.stringify({ok:false,error:"ci_mutations_forbidden"})
      });
    }
    const endpoint = route.request().url().split("/").pop().split("?")[0];
    const result = mockAdmin[endpoint];
    if (!result) return route.fulfill({
      status: 404, contentType: "application/json",
      body: JSON.stringify({ok:false,error:"ci_unmocked_route"})
    });
    return route.fulfill({
      status: 200, contentType: "application/json", body: JSON.stringify(result)
    });
  });
}
async function horizontalOverflow(page) {
  return page.evaluate(() => ({
    available:document.documentElement.clientWidth,
    measured:document.documentElement.scrollWidth,
    internalOverflow:[...document.querySelectorAll("body *")].filter(x=>
      x.clientWidth>0 && x.scrollWidth>x.clientWidth+3 && getComputedStyle(x).overflowX==="visible"
    ).slice(0,12).map(x=>({tag:x.tagName,id:x.id,className:typeof x.className==="string"?x.className.slice(0,90):"",client:x.clientWidth,scroll:x.scrollWidth})),
    culprits:[...document.querySelectorAll("body *")]
      .filter(x => {
        const rect=x.getBoundingClientRect();
        return getComputedStyle(x).position !== "fixed" &&
          rect.width > 0 && rect.right > document.documentElement.clientWidth+3;
      }).slice(0,7).map(x=>({
        tag:x.tagName,id:x.id,className:typeof x.className === "string"
          ? x.className.slice(0,90):"",
        right:Math.round(x.getBoundingClientRect().right)
      }))
  }));
}
async function verifyArtistDesk(browser, width) {
  const context = await browser.newContext({
    viewport: {width,height:900}, reducedMotion:"reduce",
    deviceScaleFactor:1
  });
  const page=await context.newPage();
  const errors=[],posts=[];
  page.on("pageerror",e=>errors.push(e.message));
  await simulateBackend(page,posts);
  try {
    const response=await page.goto(base+"/studio-admin/index.php",{
      waitUntil:"domcontentloaded",timeout:20000
    });
    assert.equal(response.status(),200,"admin page responds 200");
    await page.locator("#workspace").waitFor({state:"visible",timeout:15000});
    await page.locator("#launch-checks .launch-check").first().waitFor();
    assert.equal(await page.locator("#closed").isVisible(),false);
    assert.equal(await page.locator("#sign-in").isVisible(),false);
    assert.equal(await page.locator("#stat-orders").innerText(),"0");
    assert.equal(await page.locator("#launch-checks .launch-check").count(),3);
    assert.match(await page.locator("#launch-status").innerText(),/1 of 3/);
    const nav=page.locator(".nav-tabs");
    const area=await nav.boundingBox();
    assert(area,"admin navigation must be rendered");
    for(const name of tabs) {
      const button=page.locator('[data-view="'+name+'"]');
      assert.equal(await button.isVisible(),true,name+" tab is visible");
      const rect=await button.boundingBox();
      assert(rect,"nav tab bounding box");
      if(width<=650){
        assert(rect.x>=area.x-2 && rect.x+rect.width<=area.x+area.width+2,
          name+" tab fits inside mobile tab grid");
      }
      await button.click();
      assert.equal(await button.getAttribute("aria-current"),"page",
        name+" active tab semantics");
      const active=page.locator('[data-panel="'+name+'"]');
      assert.equal(await active.isVisible(),true,name+" panel shown");
      assert.equal(await page.locator(".content-panel:visible").count(),1,
        name+" is only visible work section");
      if(name==="requests"){
        await page.locator("#request-list .empty-state").waitFor();
        assert.equal(await page.locator("#request-detail").isVisible(),false);
      } else if(name==="messages"){
        await page.locator("#message-list .empty-state").waitFor();
      } else if(name==="artwork"){
        await page.locator("#artwork-list .artwork-library-card").first()
          .waitFor({state:"visible",timeout:12000});
        assert.equal(await page.locator("#artwork-upload").isEnabled(),false,
          "uploads disabled");
        assert.equal(await page.locator("#artwork-publish-selected").isEnabled(),false,
          "publish disabled");
        assert.equal(await page.locator("#artwork-unpublish-selected").isEnabled(),false,
          "unpublish disabled");
        assert.equal(await page.locator("#artwork-list .artwork-library-card").count(),2,
          "mixed artwork library rendered");
        await page.locator("#artwork-filter").selectOption("draft");
        assert.equal(await page.locator("#artwork-list .artwork-library-card").count(),1,
          "draft filter selects only private drawings");
        await page.locator("#artwork-filter").selectOption("published");
        assert.equal(await page.locator("#artwork-list .artwork-library-card").count(),1,
          "published filter selects only public drawings");
        assert.match(await page.locator("#artwork-visible-count").innerText(),/1 of 2/);
        await page.locator("#artwork-filter").selectOption("all");
        for(const checkbox of await active.locator('input[type="checkbox"]').all()){
          const rect=await checkbox.boundingBox();
          assert(rect && rect.width>=16 && rect.width<=24,"artwork checkbox uses compact control sizing");
        }
      } else if(name==="packages"){
        await page.locator("#package-editor-status").waitFor();
        assert.equal(await page.locator("#package-save").isEnabled(),false,
          "package editing disabled");
        assert.equal(await page.locator("#package-visibility").isEnabled(),false,
          "package publishing disabled");
      } else if(name==="settings"){
        await page.locator("#design-state").waitFor();
        assert.equal(await page.locator("#design-save").isEnabled(),false,
          "design publish switch disabled");
        assert.equal(await page.locator("#design-publish").isEnabled(),false,
          "design update switch disabled");
        assert.equal(await page.locator("#mail-check-send").isEnabled(),false,
          "test email action disabled for synthetic backend");
      }
      const overflow=await horizontalOverflow(page);
      assert(overflow.measured<=overflow.available+3,
        name+" "+width+"px document horizontal overflow: "+JSON.stringify(overflow));
    }
    assert.deepEqual(errors,[],"no admin UI exceptions "+width);
    assert.deepEqual(posts,[],"no admin mutations were attempted "+width);
    await page.locator('[data-view="overview"]').click();
    await page.locator("#launch-refresh").click();
    await page.locator("#launch-checks .launch-check").first().waitFor();
    if(width===390||width===1440){
      await page.screenshot({
        path:path.join(output,"artist-desk-"+width+".png"),
        animations:"disabled",fullPage:false
      });
    }
    console.log("PASS: artist desk tabs, locked actions and no-overflow at "+width+"px");
  } catch(error) {
    try {await page.screenshot({path:path.join(output,"artist-desk-"+width+"-failure.png")});}
    catch(_){}
    throw error;
  } finally {await context.close();}
}
async function verifyBulkArtworkChoices(browser) {
  const context=await browser.newContext({viewport:{width:390,height:844}});
  const page=await context.newPage();
  const posts=[];
  await simulateBackend(page,posts);
  // Synthetic admin backend enables ONLY UI buttons; every POST is still rejected.
  await page.route("**/api/admin/artworks.php*",route=>{
    if(route.request().method()!=="GET"){
      posts.push(route.request().url());
      return route.fulfill({
        status:503,contentType:"application/json",
        body:JSON.stringify({ok:false,error:"ci_mutations_forbidden"})
      });
    }
    return route.fulfill({
      status:200,contentType:"application/json",
      body:JSON.stringify({...mockAdmin["artworks.php"],write_enabled:true})
    });
  });
  try {
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="artwork"]').click();
    await page.locator("#artwork-list .artwork-library-card").first().waitFor();
    const publish=page.locator("#artwork-publish-selected");
    const unpublish=page.locator("#artwork-unpublish-selected");
    assert.equal(await publish.isEnabled(),false);
    assert.equal(await unpublish.isEnabled(),false);
    await page.locator("#artwork-select-all").check();
    assert.equal(await publish.isEnabled(),true,
      "selected draft can be bulk published");
    assert.equal(await unpublish.isEnabled(),true,
      "selected published work can be bulk unpublished");
    await page.locator("#artwork-filter").selectOption("draft");
    assert.equal(await publish.isEnabled(),false,"filter clears old draft selection");
    assert.equal(await unpublish.isEnabled(),false);
    await page.locator("#artwork-list .artwork-select").check();
    assert.equal(await publish.isEnabled(),true);
    assert.equal(await unpublish.isEnabled(),false);
    await page.locator("#artwork-filter").selectOption("published");
    await page.locator("#artwork-list .artwork-select").check();
    assert.equal(await publish.isEnabled(),false);
    assert.equal(await unpublish.isEnabled(),true);
    page.once("dialog",dialog=>{
      assert.match(dialog.message(),/Remove 1 selected drawings from the public gallery/);
      dialog.dismiss();
    });
    await unpublish.click();
    assert.deepEqual(posts,[],"dismissed bulk unpublish made no API changes");
    console.log("PASS: safe publish/unpublish bulk selection and filtered status");
  } finally {await context.close();}
}
async function verifyRequestIsolation(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion:"reduce"});
  const posts=[];
  await simulateBackend(page,posts);
  const fixture=id=>({id,public_ref:"CI-"+id,status:"submitted",
    customer_name:"Synthetic customer "+id,brief:"Synthetic brief "+id});
  const response=id=>({ok:true,admin_writes_enabled:true,customer_messaging_enabled:true,
    quote_sending_enabled:false,order_creation_enabled:false,
    detail:{request:fixture(id),addons:[],messages:[],quotes:[],references:[],orders:[]}});
  const reply=(route,data,status=200)=>route.fulfill({status,contentType:"application/json",
    body:JSON.stringify(data)});
  let slow=null;
  await page.route("**/api/admin/requests.php*",route=>reply(route,
    {ok:true,requests:[fixture(41),fixture(42),fixture(43)]}));
  await page.route("**/api/admin/request.php?*",async route=>{
    const id=Number(new URL(route.request().url()).searchParams.get("id"));
    if(id===43)return reply(route,{ok:false},503);
    if(id===41&&slow){
      const hold=slow;
      await hold.promise;
      await reply(route,response(id));
      hold.finished=true;
      return;
    }
    return reply(route,response(id));
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    const buttons=page.locator("#request-list .request-open");
    await buttons.nth(0).click();
    await page.waitForFunction(()=>document.querySelector("#request-note-save")?.disabled===false);
    await page.locator("#request-note-body").fill("Do not leak private note A");
    await page.locator("#request-message-body").fill("Do not leak customer message A");
    await page.locator("#quote-scope").fill("Do not leak quote A");
    await page.locator(".quote-line-description").first().fill("Private quote item A");
    await buttons.nth(1).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-title")?.textContent.includes("#42"));
    for(const selector of ["#request-note-body","#request-message-body","#quote-scope",".quote-line-description"]){
      assert.equal(await page.locator(selector).first().inputValue(),"","previous request draft cleared: "+selector);
    }
    await buttons.nth(2).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("HTTP 503"));
    assert.equal(await page.locator("#request-customer").innerText(),"—");
    assert.equal(await page.locator("#request-note-save").isEnabled(),false);
    let release;slow={promise:new Promise(resolve=>{release=resolve}),finished:false};
    await buttons.nth(0).click();
    await buttons.nth(1).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-title")?.textContent.includes("#42"));
    release();
    for(let i=0;i<100&&!slow.finished;i++)await new Promise(resolve=>setTimeout(resolve,10));
    assert(slow.finished,"delayed response delivered");
    await page.waitForTimeout(100);
    assert.match(await page.locator("#request-detail-title").innerText(),/#42/);
    await page.locator("#request-note-body").fill("Draft B");
    await page.locator("#request-detail-close").click();
    assert.equal(await page.locator("#request-detail").isVisible(),false);
    await buttons.nth(1).click();
    await page.waitForFunction(()=>document.querySelector("#request-note-save")?.disabled===false);
    assert.equal(await page.locator("#request-note-body").inputValue(),"");
    assert.deepEqual(posts,[]);
    console.log("PASS: artist request draft isolation, stale responses and failed detail loading");
  }finally{await page.close();}
}
async function verifyRequestList(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const queries=[];
  await page.route("**/api/admin/requests.php*",route=>{
    const url=new URL(route.request().url());const q=url.searchParams.get("q");
    const n=Number(url.searchParams.get("page"));const filter=url.searchParams.get("filter");
    queries.push({q,n,filter});
    const row={id:n,public_ref:"CI-"+n,customer_name:q||"CI Customer",status:"submitted"};
    return route.fulfill({status:200,contentType:"application/json",
      body:JSON.stringify({ok:true,requests:[row],page:n,has_more:n===1})});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .record").first().waitFor();
    await page.locator("#request-list-query").fill("Synthetic name");
    await page.locator("#request-list-filter").selectOption("review");
    await page.locator("#request-list-filters button[type=submit]").click();
    await page.waitForFunction(()=>document.querySelector("#request-list")?.textContent.includes("Synthetic name"));
    assert.deepEqual(queries.at(-1),{q:"Synthetic name",n:1,filter:"review"});
    await page.locator("#request-list-next").click();
    await page.waitForFunction(()=>document.querySelector("#request-list-status")?.textContent.includes("Page 2"));
    assert.deepEqual(queries.at(-1),{q:"Synthetic name",n:2,filter:"review"});
    assert.equal(await page.locator("#request-list-next").isEnabled(),false);
    await page.locator("#request-list-previous").click();
    await page.waitForFunction(()=>document.querySelector("#request-list-status")?.textContent.includes("Page 1"));
    assert.equal(await page.locator("#request-list-previous").isEnabled(),false);
    const overflow=await horizontalOverflow(page);
    assert(overflow.measured<=overflow.available+3,JSON.stringify(overflow));
    assert.deepEqual(posts,[]);
    console.log("PASS: artist request search, filter and pagination at 320px");
  }finally{await page.close();}
}

async function verifyFreshAdminSignIn(browser) {
  for(const expire of [false,true]){
    const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion:"reduce"});
    const posts=[];await simulateBackend(page,posts);
    let expireNow=false;
    let logoutCount=0;
    await page.route("https://challenges.cloudflare.com/turnstile/**",route=>route.fulfill({
      status:200,contentType:"application/javascript",
      body:"window.turnstile={render:function(sel){document.querySelector(sel).textContent='Synthetic CAPTCHA ready';return 1;},reset:function(){},getResponse:function(){return '';}};"
    }));
    await page.route("**/api/admin/overview.php*",route=>route.fulfill({
      status:expireNow?401:200,contentType:"application/json",
      body:JSON.stringify(expireNow?{ok:false}:mockAdmin["overview.php"])
    }));
    await page.route("**/api/admin/logout.php*",route=>{
      logoutCount++;
      assert.equal(route.request().method(),"POST");
      return route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({ok:true,status:"signed_out"})});
    });
    try{
      await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
      await page.locator("#workspace").waitFor({state:"visible"});
      await page.locator("#launch-checks .launch-check").first().waitFor();
      assert.match(await page.locator("#welcome-admin").innerText(),/Synthetic QA Artist/);
      // Sentinel marks the previous document; it cannot survive fresh navigation.
      await page.evaluate(()=>{document.body.dataset.privateSessionSentinel="old";});
      if(expire){
        expireNow=true;
        await page.locator("#launch-refresh").click();
      }else{
        await page.locator("#logout-button").click();
      }
      await page.locator("#sign-in").waitFor({state:"visible",timeout:15000});
      await page.waitForFunction(()=>document.querySelector("#login-button")?.disabled===false);
      assert.equal(await page.locator("#workspace").isVisible(),false);
      assert.equal(await page.locator("body").getAttribute("data-private-session-sentinel"),null);
      assert(!await page.locator("#welcome-admin").textContent().then(s=>s.includes("Synthetic QA Artist")));
      assert.match(await page.locator("#login-message").innerText(),expire?/session has expired/:/Signed out/);
      assert.equal(new URL(page.url()).searchParams.has("session"),false);
      assert.equal(logoutCount,expire?0:1);
      assert.deepEqual(posts,[]);
      console.log("PASS: fresh artist sign-in after "+(expire?"expiry":"logout")+", private DOM discarded and CAPTCHA initialized");
    }finally{await page.close();}
  }
}

async function verifyClosedDesk(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await page.route("**/api/admin/status.php*",route=>route.fulfill({
    status:200, contentType:"application/json",
    body:JSON.stringify({
      ok:true,admin_schema_ready:true,
      admin_available:false,turnstile_site_key:null
    })
  }));
  try {
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#closed-status").waitFor();
    assert.equal(await page.locator("#closed").isVisible(),true);
    assert.equal(await page.locator("#workspace").isVisible(),false);
    assert.equal(await page.locator("#sign-in").isVisible(),false);
    console.log("PASS: closed admin workspace remains inaccessible");
  } finally {await page.close();}
}
(async()=>{
  const browser=await browserType.launch({headless:true});
  try {
    for(const width of [320,390,768,1440]) await verifyArtistDesk(browser,width);
    await verifyClosedDesk(browser);
    await verifyBulkArtworkChoices(browser);
    await verifyRequestIsolation(browser);
    await verifyRequestList(browser);
    await verifyFreshAdminSignIn(browser);
    console.log("ADMIN_BROWSER_SMOKE=PASS");
  } finally {await browser.close();}
})().catch(error=>{
  console.error(error.stack||String(error));
  process.exitCode=1;
});
