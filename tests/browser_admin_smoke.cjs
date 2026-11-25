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
    configuration_review:{mode:"review_only",authorizes_launch:false,issue_count:1,enabled_gate_count:2,known_gate_count:36,issues:["dependency:DMO_REQUESTS_ENABLED:DMO_AUTH_ENABLED"]},
    retention:{mode:"review_only",counts:{expired_sessions:3,privacy_open_30d:1,closed_no_order_365d:2}},
    integrity:{checks:{order_quote_mismatch:0,paid_without_evidence:1},issue_count:1,scope:"database_only"},
    launch_evidence:{generated_at:"2026-11-25T00:00:00+00:00",scope:"configuration_only",authorizes_launch:false,published_artwork_count:1,published_package_count:0,intake_open:false,stages:[{key:"prepare",label:"1. Preparation",configuration_ready:false,configuration_pending:["Approved policies"],external_evidence_required:["Independent recovery test"]}]},
    readiness: [
      {key:"mail",label:"Transactional email",status:"ready",detail:"CI mock only"},
      {key:"accounts",label:"Customer accounts",status:"blocked",detail:"Closed"},
      {key:"payments",label:"Verified payments",status:"planned",detail:"Not launched"}
    ]
  },
  "requests.php": {ok:true,readonly:true,requests:[]},
  "messages.php": {ok:true,readonly:true,messages:[]},
  "customers.php":{ok:true,readonly:true,customers:[{id:44,display_name:"Synthetic Customer",email:"ci@example.invalid",account_status:"active",email_verified:true,request_count:1,last_request_at:"2026-11-25"}],next_before:null},
  "finance.php":{ok:true,readonly:true,scope:"local_records_only",as_of:"2026-11-25",summary:[{currency:"CAD",record_state:"reconciled",record_count:1,amount_minor:3000}],records:[{id:88,order_id:87,request_id:86,public_ref:"CI-86",method:"bank_transfer",currency:"CAD",amount_minor:3000,status:"verified",record_state:"reconciled",created_at:"2026-11-25",verified_at:"2026-11-25"}],next_before:null},
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
const tabs = ["overview","requests","messages","finance","customers","artwork","packages","settings"];
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
    const evidence=page.locator(".integrity-details").filter({hasText:"Controlled launch stages and evidence"});
    await evidence.locator("summary").click();
    assert.match(await page.locator("#launch-evidence-status").innerText(),/no launch authorization/);
    assert.match(await page.locator("#launch-evidence-stages").innerText(),/Independent recovery test/);
    assert.equal(await page.locator("#launch-evidence-export").isEnabled(),true);
    await evidence.locator("summary").click();
    const config=page.locator(".integrity-details").filter({hasText:"Private configuration review (read-only)"});
    await config.locator("summary").click();
    assert.match(await page.locator("#configuration-status").innerText(),/1 warning/);
    assert.match(await page.locator("#configuration-issues").innerText(),/DMO REQUESTS ENABLED/);
    await config.locator("summary").click();
    const retention=page.locator(".integrity-details").filter({hasText:"Retention review (read-only)"});
    await retention.locator("summary").click();
    assert.match(await page.locator("#retention-status").innerText(),/Read-only inventory/);
    assert.match(await page.locator("#retention-checks").innerText(),/Expired sessions/);
    assert.match(await page.locator("#retention-checks").innerText(),/Open at least 30 days/);
    await retention.locator("summary").click();
    await page.locator(".integrity-details").filter({hasText:"Order and payment consistency details"}).locator("summary").click();
    assert.match(await page.locator("#integrity-status").innerText(),/1 failed checks/);
    assert.equal(await page.locator("#integrity-checks .launch-check").count(),2);
    assert.match(await page.locator("#integrity-checks").innerText(),/Paid orders without eligible evidence/);
    const checkOverflow=await horizontalOverflow(page);
    assert(checkOverflow.measured<=checkOverflow.available+3,JSON.stringify(checkOverflow));
    await page.locator(".integrity-details").filter({hasText:"Order and payment consistency details"}).locator("summary").click();
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
      } else if(name==="customers"){
        await page.locator("#customer-directory-list article").first().waitFor();
        assert.match(await page.locator("#customer-directory-list").innerText(),/Synthetic Customer/);
        await page.getByRole("button",{name:"View commission history"}).click();
        await page.locator("#request-list .empty-state").waitFor();
        assert.match(await page.locator("#request-customer-label").innerText(),/customer #44/);
        assert.equal(await page.locator("#request-detail").isVisible(),false);
        await page.locator("#request-customer-clear").click();
        assert.equal(await page.locator("#request-customer-scope").isVisible(),false);
      } else if(name==="finance"){
        await page.locator("#finance-records article").first().waitFor();
        assert.match(await page.locator("#finance-summary").innerText(),/CAD 30.00/);
        assert.equal(await page.locator("#finance-export").isEnabled(),true);
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
        await page.locator("#artwork-search").fill("#4");
        assert.equal(await page.locator("#artwork-list .artwork-library-card").count(),1);
        assert.match(await page.locator("#artwork-list").innerText(),/Drawing #4/);
        assert.match(await page.locator("#artwork-list").innerText(),/620 × 420 px/);
        assert.match(await page.getByRole("link",{name:"Inspect private full image"}).getAttribute("href"),/artwork-preview\.php\?id=4&v=display/);
        await page.locator("#artwork-search").fill("");
        await page.locator("#artwork-sort-mode").selectOption("oldest");
        assert.equal(await page.locator("#artwork-page").innerText(),"Page 1 of 1");
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
    await page.route("**/api/admin/artworks.php*",route=>{
      if(route.request().method()==="GET")return route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({...mockAdmin["artworks.php"],write_enabled:true})});
      const payload=route.request().postDataJSON();
      assert.equal(payload.action,"batch_visibility");assert.equal(payload.visibility,"draft");assert.equal(payload.confirm,true);assert.equal(payload.ids.length,1);
      return route.fulfill({status:409,contentType:"application/json",body:JSON.stringify({ok:false,error:"artwork_batch_changed"})});
    });
    page.once("dialog",dialog=>dialog.accept());
    await unpublish.click();
    await page.waitForFunction(()=>document.querySelector("#artwork-library-status")?.textContent.includes("Batch not confirmed"));
    assert.equal(await unpublish.isEnabled(),false);
    console.log("PASS: safe publish/unpublish bulk selection, atomic API and stale batch lockout");
  } finally {await context.close();}
}
async function verifyArtworkDrafts(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await simulateBackend(page,[]);
  let writes=0,sort=0,alt="";
  await page.route("**/api/admin/artworks.php*",route=>{
    const reply=(data,status=200)=>route.fulfill({status,contentType:"application/json",body:JSON.stringify(data)});
    if(route.request().method()==="GET")return reply({...mockAdmin["artworks.php"],write_enabled:true,artworks:mockAdmin["artworks.php"].artworks.map(w=>w.id===4?{...w,sort_order:sort,alt}:w)});
    writes++;
    const payload=route.request().postDataJSON();
    if(writes===1)return reply({ok:false,error:"ci_save_rejected"},422);
    if(payload.action==="set_order")sort=payload.sort_order;
    if(payload.action==="set_alt")alt=payload.alt;
    return reply({ok:true,id:4});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="artwork"]').click();
    const order=page.getByLabel("Sort order for drawing 4",{exact:true});
    const desc=page.getByLabel("Accessibility description for drawing 4",{exact:true});
    const card=page.locator(".artwork-library-card").filter({has:order});
    await order.fill("19");
    await card.locator("summary").click();await desc.fill("Synthetic drawing description");
    await page.locator("#artwork-filter").selectOption("published");
    await page.locator("#artwork-filter").selectOption("all");
    assert.equal(await order.inputValue(),"19");
    assert.equal(await desc.inputValue(),"Synthetic drawing description");
    await page.locator('[data-view="overview"]').click();
    await page.locator('[data-view="artwork"]').click();
    await card.getByRole("button",{name:"Set order",exact:true}).click();
    await page.waitForFunction(()=>document.querySelector("#artwork-library-status")?.textContent.includes("Change not saved"));
    assert.equal(await order.inputValue(),"19");
    assert.equal(await desc.inputValue(),"Synthetic drawing description");
    await card.getByRole("button",{name:"Set order",exact:true}).click();
    await page.waitForFunction(()=>document.querySelector("#artwork-library-status")?.textContent.includes("updated"));
    assert.equal(sort,19);
    assert.equal(await desc.inputValue(),"Synthetic drawing description");
    assert.equal(await page.locator("#artwork-unsaved").isVisible(),true);
    page.once("dialog",d=>d.accept());
    await page.locator("#artwork-discard-edits").click();
    assert.equal(await desc.inputValue(),"");
    assert.equal(await page.locator("#artwork-unsaved").isVisible(),false);
    assert.equal(writes,2);
    console.log("PASS: artwork drafts survive filtering, tab changes and rejected saves");
  }finally{await page.close();}
}
async function verifyGalleryMoves(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await simulateBackend(page,[]);
  let rows=[{id:91,visibility:"published",featured:false,sort_order:0,alt:""},{id:92,visibility:"published",featured:false,sort_order:5,alt:""}],payload=null;
  await page.route("**/api/admin/artworks.php*",route=>{
    const answer=data=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
    if(route.request().method()==="GET")return answer({ok:true,write_enabled:true,artworks:rows});
    payload=route.request().postDataJSON();if(payload.action==="batch_featured"){rows=rows.map(w=>({...w,featured:payload.featured}));return answer({ok:true,updated:payload.ids.length});}
    rows=rows.map(w=>({...w,sort_order:w.id===92?0:5}));return answer({ok:true,updated:2});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});await page.locator('[data-view="artwork"]').click();
    await page.locator("#artwork-sort-mode").selectOption("gallery");
    const card=page.locator(".artwork-library-card").filter({has:page.getByLabel("Sort order for drawing 92",{exact:true})});
    page.once("dialog",d=>d.accept());await card.getByRole("button",{name:"Move earlier",exact:true}).click();
    await page.waitForFunction(()=>document.querySelector("#artwork-library-status")?.textContent.includes("moved earlier"));
    assert.deepEqual(payload,{action:"move_published",id:92,neighbor_id:91,direction:"earlier",expected_order:5,expected_neighbor_order:0,expected_featured:false});
    assert.match(await page.locator(".artwork-library-card").first().innerText(),/Drawing #92/i);
    await page.locator("#artwork-select-all").check();page.once("dialog",d=>d.accept());await page.locator("#artwork-feature-selected").click();
    await page.waitForFunction(()=>document.querySelector("#artwork-library-status")?.textContent.includes("Featured 2 drawings"));
    assert.equal(payload.action,"batch_featured");assert.equal(payload.featured,true);assert.deepEqual([...payload.ids].sort(),[91,92]);
    console.log("PASS: artist moves and bulk-features published drawings");
  }finally{await page.close();}
}
async function verifyInterruptedArtworkUpload(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await simulateBackend(page,[]);
  let calls=0;const uploaded=[],keys=[];
  await page.route("**/api/admin/artworks.php*",route=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({...mockAdmin["artworks.php"],write_enabled:true})}));
  await page.route("**/api/admin/artwork-upload.php*",route=>{
    calls++;const body=route.request().postData()||"";
    keys.push(body.match(/name="operation_key"\r?\n\r?\n([0-9a-f-]{36})/)?.[1]);
    uploaded.push(body.includes("first.png")?"first":body.includes("second.png")?"second":body.includes("third.png")?"third":"unknown");
    if(calls===2)return route.fulfill({status:502,contentType:"text/plain",body:"Gateway interrupted"});
    if(calls===3)return route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({ok:true,artwork_id:102,visibility:"draft",already_saved:true})});
    return route.fulfill({status:201,contentType:"application/json",body:JSON.stringify({ok:true,artwork_id:100+calls})});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="artwork"]').click();
    await page.locator("#artwork-upload").waitFor();
    await page.waitForFunction(()=>document.querySelector("#artwork-upload")?.disabled===false);
    await page.locator("#artwork-files").setInputFiles(["first.png","second.png","third.png"].map(name=>({name,mimeType:"image/png",buffer:Buffer.from("synthetic CI upload")})));
    await page.locator("#artwork-upload").click();
    await page.waitForFunction(()=>document.querySelector("#artwork-upload-message")?.textContent.includes("1 unconfirmed · 2 remaining"));
    assert.deepEqual(uploaded,["first","second"]);
    await page.locator("#artwork-upload-resume").click();
    await page.waitForFunction(()=>document.querySelector("#artwork-upload-message")?.textContent.includes("0 unconfirmed · 0 remaining"));
    assert.deepEqual(uploaded,["first","second","second","third"],"ambiguous upload is retried with its original operation key");
    assert.match(keys[1]||"",/^[0-9a-f-]{36}$/);assert.equal(keys[1],keys[2]);assert.notEqual(keys[0],keys[1]);
    assert.equal(await page.locator("#artwork-upload-resume").isVisible(),false);
    console.log("PASS: interrupted bulk artwork upload recovers original operation and untouched files");
  }finally{await page.close();}
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
    assert.match(await page.locator("#request-drafts-warning").innerText(),/private note, customer message, quote/);
    page.once("dialog",dialog=>{assert.match(dialog.message(),/Discard the unsaved/);dialog.dismiss();});
    await buttons.nth(1).click();
    assert.equal(await page.locator("#request-note-body").inputValue(),"Do not leak private note A");
    page.once("dialog",dialog=>dialog.accept());
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
    page.once("dialog",dialog=>dialog.dismiss());
    await page.locator("#request-detail-close").click();
    assert.equal(await page.locator("#request-detail").isVisible(),true);
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#request-detail-close").click();
    assert.equal(await page.locator("#request-detail").isVisible(),false);
    await buttons.nth(1).click();
    await page.waitForFunction(()=>document.querySelector("#request-note-save")?.disabled===false);
    assert.equal(await page.locator("#request-note-body").inputValue(),"");
    assert.deepEqual(posts,[]);
    let saved=0;const quoteKeys=[];
    await page.route("**/api/admin/request-actions.php*",route=>{
      const body=route.request().postDataJSON();assert.equal(body.action,"create_quote_draft");assert.equal(body.request_id,42);
      quoteKeys.push(body.operation_key);
      saved++;return saved===1?route.abort("failed"):reply(route,{ok:true,version:1,status:"draft",already_saved:true});
    });
    await page.locator("#request-note-body").fill("Keep this separate note");
    await page.locator("#quote-scope").fill("Synthetic quote scope for QA");
    await page.locator(".quote-line-description").first().fill("Synthetic line");
    await page.locator(".quote-line-price").first().fill("20.00");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#quote-save").click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Retry the unchanged quote"));
    assert.equal(await page.locator("#quote-scope").inputValue(),"Synthetic quote scope for QA");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#quote-save").click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Existing quote version 1 recovered"));
    assert.equal(saved,2);assert.equal(quoteKeys[0],quoteKeys[1]);assert.match(quoteKeys[0],/^[0-9a-f-]{36}$/);
    assert.equal(await page.locator("#quote-scope").inputValue(),"");
    assert.equal(await page.locator("#request-note-body").inputValue(),"Keep this separate note");
    console.log("PASS: artist request draft isolation, discard protection, quote reset and stale responses");
  }finally{await page.close();}
}
async function verifyRequestList(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const queries=[];
  await page.route("**/api/admin/requests.php*",route=>{
    const url=new URL(route.request().url());const q=url.searchParams.get("q");
    const n=Number(url.searchParams.get("page"));const filter=url.searchParams.get("filter");
    queries.push({q,n,filter,...(url.searchParams.has('from_date')?{from:url.searchParams.get('from_date')}:{}),...(url.searchParams.has('to_date')?{to:url.searchParams.get('to_date')}:{}),...(url.searchParams.has("scope")?{scope:url.searchParams.get("scope")}:{}),...(url.searchParams.has("sort")?{sort:url.searchParams.get("sort")}:{})});
    if(url.searchParams.get("format")==="calendar")return route.fulfill({status:200,contentType:"text/calendar",body:"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"});
    const row={id:n,public_ref:"CI-"+n,customer_name:q||"CI Customer",status:"submitted",order_deadline:"2027-01-10"};
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
    await page.locator("#request-list-filter").selectOption("paid_to_start");
    await page.locator("#request-list-filters button[type=submit]").click();
    await page.waitForFunction(()=>document.querySelector("#request-list")?.textContent.includes("Synthetic name"));
    assert.deepEqual(queries.at(-1),{q:"Synthetic name",n:1,filter:"paid_to_start"});
    await page.locator("#request-list-filter").selectOption("scheduled");
    await page.locator("#request-schedule-from").fill("2027-01-01");await page.locator("#request-schedule-to").fill("2027-01-31");
    await page.locator("#request-list-filters button[type=submit]").click();
    await page.locator(".request-agenda-date").waitFor();
    assert.deepEqual(queries.at(-1),{q:"Synthetic name",n:1,filter:"scheduled",from:"2027-01-01",to:"2027-01-31"});
    assert.equal(await page.locator(".request-agenda-date").innerText(),"2027-01-10");
    page.once("dialog",d=>d.accept());const download=page.waitForEvent("download");await page.locator("#request-agenda-export").click();
    assert.equal((await download).suggestedFilename(),"drawmeone-delivery-agenda.ics");
    await page.locator("#request-schedule-from").fill("2027-01-02");assert.equal(await page.locator("#request-agenda-export").isEnabled(),false);
    await page.locator("#request-list-filter").selectOption("all");await page.locator("#request-list-scope").selectOption("brief");await page.locator("#request-list-sort").selectOption("oldest");
    await page.locator("#request-list-filters button[type=submit]").click();await page.waitForFunction(()=>document.querySelector("#request-list")?.textContent.includes("Synthetic name"));
    assert.deepEqual(queries.at(-1),{q:"Synthetic name",n:1,filter:"all",scope:"brief",sort:"oldest"});
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

async function verifyReviewAndInbox(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const fixture={id:51,public_ref:"CI-51",status:"submitted",customer_name:"Synthetic review customer"};
  let state="submitted";
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[{...fixture,status:state}]}));
  await page.route("**/api/admin/request.php?*",route=>{
    const full=new URL(route.request().url()).searchParams.get("thread")==="full";
    return answer(route,{ok:true,admin_writes_enabled:true,detail:{
      request:{...fixture,status:state},review_choices:state==="submitted"?["clarification_needed","rejected"]:["submitted","rejected"],
      messages_have_older:!full,messages:[{body:full?"Older private note":"Recent message",is_internal:full,sender_role:"admin"}],quotes:[],orders:[],references:[],addons:[]
    }});
  });
  await page.route("**/api/admin/request-actions.php*",route=>{
    const data=route.request().postDataJSON();
    assert.deepEqual(data,{action:"set_review_status",request_id:51,expected_status:"submitted",status:"clarification_needed"});
    state=data.status;return answer(route,{ok:true,status:state});
  });
  const queries=[];
  await page.route("**/api/admin/messages.php*",route=>{
    const url=new URL(route.request().url()),filter=url.searchParams.get("filter"),n=Number(url.searchParams.get("page"));
    queries.push({filter,n});
    return answer(route,{ok:true,has_more:n===1,messages:[{request_id:51,request_ref:"CI-51",sender_role:filter==="customer"?"customer":"admin",is_internal:filter==="internal"?1:0,body_excerpt:"Synthetic message page "+n}]});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .request-open").first().click();
    await page.locator("#request-review-actions").waitFor({state:"visible"});
    await page.locator("#request-thread-full").click();
    await page.waitForFunction(()=>document.querySelector("#request-thread")?.textContent.includes("Older private note"));
    assert.equal(await page.locator("#request-thread-full").isVisible(),false);
    await page.locator("#request-review-status").selectOption("clarification_needed");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#request-review-save").click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Review status updated"));
    assert.match(await page.locator("#request-detail-title").innerText(),/clarification_needed/);
    await page.locator('[data-view="messages"]').click();
    await page.locator("#message-list .record").first().waitFor();
    await page.locator("#message-list-filter").selectOption("internal");
    await page.waitForFunction(()=>document.querySelector("#message-list")?.textContent.includes("INTERNAL NOTE"));
    await page.locator("#message-list-next").click();
    await page.waitForFunction(()=>document.querySelector("#message-list-status")?.textContent.includes("Page 2"));
    assert.deepEqual(queries.at(-1),{filter:"internal",n:2});
    assert.equal(await page.locator("#message-list-next").isEnabled(),false);
    const overflow=await horizontalOverflow(page);
    assert(overflow.measured<=overflow.available+3,JSON.stringify(overflow));
    assert.deepEqual(posts,[]);
    await page.locator("#message-list-filter").selectOption("reply_due");
    await page.waitForFunction(()=>document.querySelector("#message-list-status")?.textContent.includes("awaiting reply"));
    assert.deepEqual(queries.at(-1),{filter:"reply_due",n:1});
    console.log("PASS: review transition, full thread and filtered message inbox at 320px");
  }finally{await page.close();}
}

async function verifyOrderPlanning(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const order={id:71,quote_id:8,status:"awaiting_arrangement",payment_status:"unverified",total_minor:3000,currency:"CAD",deadline_editable:true,agreed_deadline:null};
  const fixture={id:61,public_ref:"CI-61",status:"submitted",workflow_stage:"awaiting_arrangement",customer_name:"Synthetic order customer"};
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  let filter="";
  await page.route("**/api/admin/requests.php*",route=>{
    filter=new URL(route.request().url()).searchParams.get("filter");
    return answer(route,{ok:true,requests:[{...fixture,order_deadline:order.agreed_deadline}]});
  });
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,detail:{
    request:fixture,messages:[],quotes:[],orders:[order],references:[],addons:[]
  }}));
  await page.route("**/api/admin/request-actions.php*",route=>{
    const body=route.request().postDataJSON();
    assert.deepEqual(body,{action:"set_order_deadline",request_id:61,order_id:71,expected_deadline:null,agreed_deadline:"2027-01-15",confirm_agreement:true});
    order.agreed_deadline=body.agreed_deadline;
    return answer(route,{ok:true,payment_status:"unverified",agreed_deadline:order.agreed_deadline});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-request-queue="orders"]').click();
    await page.locator("#request-list .request-open").first().click();
    assert.equal(filter,"orders");
    await page.locator(".order-date-form input[type=date]").fill("2027-01-15");
    await page.locator(".order-date-form input[type=checkbox]").check();
    await page.locator(".order-date-form button").click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Payment remains unverified"));
    assert.match(await page.locator("#request-orders").innerText(),/2027-01-15/);
    assert.match(await page.locator("#request-orders").innerText(),/unverified/);
    const overflow=await horizontalOverflow(page);
    assert(overflow.measured<=overflow.available+3,JSON.stringify(overflow));
    assert.deepEqual(posts,[]);
    console.log("PASS: order queue and agreed delivery date at 320px");
  }finally{await page.close();}
}

async function verifyQuoteFollowup(browser) {
  const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const row={id:92,status:"submitted",public_ref:"CI-92",workflow_stage:"quote_expired"};
  const quote={id:93,version_number:1,status:"sent",is_expired:true,currency:"CAD",total_minor:1500,scope_description:"Synthetic portrait for review",revision_allowance:1,lines:[{description:"Portrait",amount_minor:1500}],sent_at:"2026-11-01",validity_ends_at:"2026-11-08"};
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[row]}));
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,admin_writes_enabled:true,quote_sending_enabled:true,detail:{request:row,quotes:[quote],orders:[],messages:[],references:[],addons:[]}}));
  await page.route("**/api/admin/request-actions.php*",route=>{
    assert.deepEqual(route.request().postDataJSON(),{action:"withdraw_quote",request_id:92,quote_id:93,confirm_withdrawal:true});
    quote.status="superseded";row.workflow_stage="quote_inactive";
    return answer(route,{ok:true,status:"superseded"});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-request-queue="quote_followup"]').click();
    await page.locator("#request-list .request-open").first().click();
    await page.locator("#request-quotes button[data-quote-withdraw]").waitFor();
    assert.match(await page.locator("#request-quotes").innerText(),/Expired/);
    await page.getByRole("button",{name:"Use as starting point for new version"}).click();
    assert.equal(await page.locator("#quote-scope").inputValue(),quote.scope_description);
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#request-quotes button[data-quote-withdraw]").click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Offer withdrawn"));
    assert.equal(await page.locator("#request-quotes button[data-quote-withdraw]").count(),0);
    assert.match(await page.locator("#request-quotes").innerText(),/No longer available/);
    assert.deepEqual(posts,[]);
    console.log("PASS: expired quote follow-up, copy and explicit withdrawal");
  }finally{await page.close();}
}

async function verifyCustomerNotice(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const row={id:94,status:"submitted",public_ref:"CI-94"};
  const notice={kind:"quote",event_id:95,status:"not_sent",can_send:true};
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[row]}));
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,notifications_enabled:true,notification_options:[notice],detail:{request:row,messages:[],quotes:[],orders:[],references:[],addons:[]}}));
  await page.route("**/api/admin/request-actions.php*",route=>{
    assert.deepEqual(route.request().postDataJSON(),{action:"send_notification",request_id:94,kind:"quote",event_id:95,confirm_send:true});
    notice.status="accepted";notice.can_send=false;
    return answer(route,{ok:true,transport_accepted:true,delivery_confirmed:false});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .request-open").first().click();
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#request-notifications button").click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Inbox delivery has not been confirmed"));
    assert.equal(await page.locator("#request-notifications button").count(),0);
    assert.match(await page.locator("#request-notifications").innerText(),/inbox delivery not confirmed/);
    assert.deepEqual(posts,[]);
    console.log("PASS: explicit notice action and no duplicate button");
  }finally{await page.close();}
}

async function verifyDeliveryDraftManagement(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const row={id:96,status:"submitted",public_ref:"CI-96"};
  const order={id:97,status:"awaiting_arrangement",payment_status:"unverified",total_minor:3000,currency:"CAD"};
  let drafts=[];
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[row]}));
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,delivery_staging_enabled:true,delivery_drafts:drafts,detail:{request:row,orders:[order],messages:[],quotes:[],references:[],addons:[],activity:{events:[{label:"Unpaid order confirmed",created_at:"2026-11-25 12:00:00"}],has_older:true}}}));
  const stageKeys=[];
  await page.route("**/api/admin/delivery-draft.php",route=>{
    assert.equal(route.request().method(),"POST");
    assert.match(route.request().headers()["content-type"],/multipart\/form-data/);
    stageKeys.push((route.request().postData()||"").match(/name="operation_key"\r?\n\r?\n([0-9a-f-]{36})/)?.[1]);
    drafts=[{id:98,order_id:97,width:320,height:240,bytes:500,created_at:"2026-11-25"}];
    if(stageKeys.length===1)return route.abort("failed");
    return answer(route,{ok:true,draft_id:98,already_saved:true,released:false});
  });
  await page.route("**/api/admin/request-actions.php*",route=>{
    assert.deepEqual(route.request().postDataJSON(),{action:"remove_delivery_draft",request_id:96,draft_id:98,confirm_remove:true});
    drafts=[];return answer(route,{ok:true,draft_removed:true,storage_cleanup_complete:true});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .request-open").first().click();
    await page.locator("#request-activity-panel summary").click();
    await page.waitForFunction(()=>document.querySelector("#request-activity-list")?.textContent.includes("Unpaid order confirmed"));
    assert.match(await page.locator("#request-activity-list").innerText(),/Unpaid order confirmed/i);
    assert.equal(await page.locator("#request-activity-limit").isVisible(),true);
    await page.locator(".delivery-draft-form input").setInputFiles({name:"fixture.png",mimeType:"image/png",buffer:Buffer.from("synthetic image fixture")});
    await page.getByRole("button",{name:"Stage privately",exact:true}).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Draft upload not confirmed"));
    assert.match(await page.locator("#request-drafts-warning").innerText(),/delivery artwork selection/);
    await page.locator("#request-list .request-open").first().click();
    await page.getByRole("button",{name:"Retry staging",exact:true}).waitFor();
    assert.match(await page.locator(".delivery-draft-form").innerText(),/fixture.png/);
    await page.getByRole("button",{name:"Retry staging",exact:true}).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Existing artwork #98 recovered"));
    assert.match(stageKeys[0]||"",/^[0-9a-f-]{36}$/);assert.equal(stageKeys[0],stageKeys[1]);
    assert.equal(await page.locator("#request-drafts-warning").isVisible(),false);
    assert.match(await page.getByRole("link",{name:"Open private preview"}).getAttribute("href"),/id=98&view=preview$/);
    page.once("dialog",dialog=>dialog.accept());
    await page.getByRole("button",{name:"Remove draft",exact:true}).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("Private draft removed"));
    assert.equal(await page.getByRole("link",{name:"Open private preview"}).count(),0);
    assert.match(await page.locator("#request-orders").innerText(),/unverified/);
    assert.deepEqual(posts,[]);
    console.log("PASS: private delivery staging, preview and removal UI");
  }finally{await page.close();}
}

async function verifyFinancialException(browser) {
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const request={id:106,status:"completed",public_ref:"CI-106",workflow_stage:"completed"};
  const order={id:107,status:"completed",payment_status:"paid",total_minor:3000,currency:"CAD"};
  const records=[{id:108,order_id:107,method:"bank_transfer",currency:"CAD",amount_minor:3000,status:"verified"}];
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[request]}));
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,payment_exceptions_enabled:true,payment_records:records,detail:{request,orders:[order],quotes:[],messages:[],references:[],addons:[]}}));
  await page.route("**/api/admin/request-actions.php*",route=>{
    const body=route.request().postDataJSON();
    assert.equal(body.action,"set_payment_exception");assert.equal(body.order_id,107);assert.equal(body.request_id,106);assert.equal(body.confirm_external_check,true);
    assert.equal(body.expected_state,order.payment_status);
    order.payment_status=body.decision==="hold"?"on_hold":body.decision==="restore"?"paid":"refunded";
    return answer(route,{ok:true,payment_status:order.payment_status,money_moved:false});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .request-open").first().click();
    const form=page.locator("#request-payment-records form");
    await form.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await form.getByRole("button",{name:"Record financial exception"}).click();
    await page.waitForFunction(()=>document.querySelector("#request-payment-records")?.textContent.includes("Payment on hold"));
    await form.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await form.getByRole("button",{name:"Record financial exception"}).click();
    await page.waitForFunction(()=>document.querySelector("#request-detail-status")?.textContent.includes("recorded: paid"));
    await form.locator("select").first().selectOption("refund");
    await form.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await form.getByRole("button",{name:"Record financial exception"}).click();
    await page.waitForFunction(()=>document.querySelector("#request-payment-records")?.textContent.includes("Full external refund recorded"));
    assert.equal(await form.count(),0);
    assert.deepEqual(posts,[]);
    console.log("PASS: financial hold, restore and full external refund UI");
  }finally{await page.close();}
}

async function verifyIntakeControl(browser){
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const intake={control_enabled:true,version:3,paused:false,capacity:4,active_requests:2,requests_open:true,reason:"open"};
  await page.route("**/api/admin/overview.php*",route=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({...mockAdmin["overview.php"],intake})}));
  await page.route("**/api/admin/intake.php",route=>{
    const body=route.request().postDataJSON();
    assert.deepEqual(body,{expected_version:3,paused:true,capacity:6,confirm_change:true});
    Object.assign(intake,{version:4,paused:true,capacity:6,requests_open:false,reason:"paused"});
    return route.fulfill({status:200,contentType:"application/json",body:JSON.stringify({ok:true,intake})});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#intake-form").waitFor({state:"visible"});
    assert.match(await page.locator("#intake-status").innerText(),/2 \/ 4 active/);
    await page.locator("#intake-mode").selectOption("paused");
    await page.locator("#intake-capacity").fill("6");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#intake-save").click();
    await page.waitForFunction(()=>document.querySelector("#intake-status")?.textContent.includes("Paused by artist"));
    assert.match(await page.locator("#intake-status").innerText(),/2 \/ 6 active/);
    assert.deepEqual(posts,[]);
    console.log("PASS: artist intake control and mobile capacity form");
  }finally{await page.close();}
}

async function verifyPrivacyQueue(browser){
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const item={case_id:12,version:12,customer_id:4,display_name:"CI Customer",email:"ci@example.invalid",kind:"deletion",status:"submitted",note:"Synthetic review.",reply:""};
  let open=true;
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/privacy.php*",route=>{
    if(route.request().method()==="GET"){const closed=new URL(route.request().url()).searchParams.get("view")==="closed";return answer(route,{ok:true,cases:closed?(!open?[{...item,status:"resolved"}]:[]):(open?[item]:[]),next_before:null});}
    const body=route.request().postDataJSON();assert.equal(body.customer_id,4);assert.equal(body.expected_version,item.version);assert.equal(body.confirm_record,true);
    if(body.status==="in_review")Object.assign(item,{version:13,status:"in_review",reply:body.reply});
    else {assert.equal(body.status,"resolved");open=false;}
    return answer(route,{ok:true,case:item,data_deleted:false,data_changed:false});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator("#privacy-queue-refresh").click();
    await page.locator("#privacy-queue form").waitFor();
    const form=page.locator("#privacy-queue form");
    await form.locator("textarea").fill("Review started.");
    await form.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await form.getByRole("button",{name:"Save privacy decision"}).click();
    await page.waitForFunction(()=>document.querySelector("#privacy-queue")?.textContent.includes("in review"));
    await form.locator("select").selectOption("resolved");
    await form.locator("textarea").fill("Synthetic manual response completed.");
    await form.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await form.getByRole("button",{name:"Save privacy decision"}).click();
    await page.waitForFunction(()=>document.querySelector("#privacy-queue-status")?.textContent.includes("0 open"));
    assert.equal(await form.count(),0);
    assert.deepEqual(posts,[]);
    const result=await horizontalOverflow(page);assert(result.measured<=result.available+3,JSON.stringify(result));
    await page.locator("#privacy-queue-view").selectOption("closed");
    await page.waitForFunction(()=>document.querySelector("#privacy-queue-status")?.textContent.includes("1 closed"));
    assert.equal(await form.count(),0);
    assert.match(await page.locator("#privacy-queue").innerText(),/resolved/);
    console.log("PASS: artist privacy queue review and non-destructive outcome");
  }finally{await page.close();}
}

async function verifyCheckoutLinks(browser){
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const request={id:116,status:"submitted",public_ref:"CI-116",workflow_stage:"awaiting_arrangement"};
  const order={id:117,status:"awaiting_arrangement",payment_status:"unverified",total_minor:3000,currency:"CAD"};
  const checkout={order_id:117,latest:null,can_issue:true,can_revoke:false,provider_host:"checkout.example.invalid"};
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[request]}));
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,checkout_links:[checkout],detail:{request,orders:[order],quotes:[],messages:[],references:[],addons:[]}}));
  await page.route("**/api/admin/request-actions.php*",route=>{
    const body=route.request().postDataJSON();assert.equal(body.order_id,117);assert.equal(body.request_id,116);
    if(body.action==="issue_checkout_link"){
      assert.equal(body.url,"https://checkout.example.invalid/invoice/ci-ui");assert.equal(body.confirm_invoice,true);assert.equal(body.amount_minor,3000);
      Object.assign(checkout,{latest:{id:118,status:"active",fresh:true,provider_host:"checkout.example.invalid"},can_issue:false,can_revoke:true});
    }else{
      assert.equal(body.action,"revoke_checkout_link");assert.equal(body.expected_link_id,118);assert.equal(body.confirm_external_closed,true);
      checkout.latest.status="revoked";checkout.can_issue=true;checkout.can_revoke=false;
    }
    return answer(route,{ok:true,status:checkout.latest.status,money_moved:false});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .request-open").first().click();
    const section=page.locator(".checkout-admin");
    await section.locator('input[type=url]').fill("https://checkout.example.invalid/invoice/ci-ui");
    await section.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await section.getByRole("button",{name:"Publish invoice link"}).click();
    await section.getByRole("button",{name:"Remove closed invoice link"}).waitFor();
    await section.getByRole("checkbox").check();
    page.once("dialog",dialog=>dialog.accept());
    await section.getByRole("button",{name:"Remove closed invoice link"}).click();
    await section.getByRole("button",{name:"Publish invoice link"}).waitFor();
    assert.match(await section.innerText(),/revoked/);
    assert.deepEqual(posts,[]);
    console.log("PASS: hosted invoice issue and external closure confirmation UI");
  }finally{await page.close();}
}

async function verifyUnpaidCancellation(browser){
  const page=await browser.newPage({viewport:{width:320,height:844},reducedMotion:"reduce"});
  const posts=[];await simulateBackend(page,posts);
  const request={id:126,status:"submitted",public_ref:"CI-126",workflow_stage:"awaiting_arrangement"};
  const order={id:127,status:"awaiting_arrangement",payment_status:"unverified",total_minor:3000,currency:"CAD",can_cancel_unpaid:true};
  const answer=(route,data)=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
  await page.route("**/api/admin/requests.php*",route=>answer(route,{ok:true,requests:[request]}));
  await page.route("**/api/admin/request.php?*",route=>answer(route,{ok:true,detail:{request,orders:[order],quotes:[],messages:[],references:[],addons:[]}}));
  await page.route("**/api/admin/request-actions.php*",route=>{
    assert.deepEqual(route.request().postDataJSON(),{action:"cancel_unpaid_order",order_id:127,reason:"customer_requested",confirm_no_funds:true,confirm_agreement:true,request_id:126});
    Object.assign(order,{status:"cancelled",can_cancel_unpaid:false});Object.assign(request,{status:"cancelled",workflow_stage:"cancelled"});
    return answer(route,{ok:true,status:"cancelled",money_moved:false});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="requests"]').click();
    await page.locator("#request-list .request-open").first().click();
    const section=page.locator("#request-payment-records");
    await section.getByRole("checkbox").nth(0).check();
    await section.getByRole("checkbox").nth(1).check();
    page.once("dialog",dialog=>dialog.accept());
    await section.getByRole("button",{name:"Cancel unpaid order"}).click();
    await page.waitForFunction(()=>document.querySelector("#request-payment-records")?.textContent.includes("Cancelled unpaid order"));
    assert.equal(await section.getByRole("button",{name:"Cancel unpaid order"}).count(),0);
    assert.deepEqual(posts,[]);
    console.log("PASS: artist cancels only eligible unpaid order");
  }finally{await page.close();}
}
async function verifyDesignHistory(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  const posts=[];
  const settings={site_style:"studio",color_theme:"ember",gallery_layout:"rows",default_mode:"light",logo:"original"};
  let version=2,restored=false;
  try{
    await simulateBackend(page,posts);
    await page.route("**/api/admin/design.php*",async route=>{
      const respond=(status,data)=>route.fulfill({status,contentType:"application/json",body:JSON.stringify(data)});
      if(route.request().method()==="GET")return respond(200,{ok:true,write_enabled:true,presentation:"published",
        draft:{exists:true,version,settings:{...settings,color_theme:restored?"ember":"canvas"}},
        published:{exists:true,version:2,settings:{...settings,color_theme:"canvas"}},
        history:[{id:17,version:1,settings}]});
      const body=route.request().postDataJSON();
      assert.deepEqual(body,{action:"restore_draft",expected_version:2,snapshot_id:17});
      restored=true;version=3;
      return respond(200,{ok:true,draft_version:3});
    });
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="settings"]').click();
    await page.locator("#design-history").locator('option[value="17"]').waitFor({state:"attached"});
    await page.locator("#design-color").selectOption("aurora");
    assert.equal(await page.locator("#design-publish").isEnabled(),false);
    assert.equal(await page.locator("#design-unsaved").isVisible(),true);
    await page.locator('[data-view="overview"]').click();
    await page.locator('[data-view="settings"]').click();
    assert.equal(await page.locator("#design-color").inputValue(),"aurora");
    page.once("dialog",dialog=>dialog.dismiss());
    await page.locator("#design-reload").click();
    assert.equal(await page.locator("#design-color").inputValue(),"aurora");
    await page.locator("#design-history").selectOption("17");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#design-restore").click();
    await page.waitForFunction(()=>document.querySelector("#design-state")?.textContent.includes("Draft v3"));
    assert.equal(await page.locator("#design-color").inputValue(),"ember");
    await page.waitForFunction(()=>document.querySelector("#design-unsaved").hidden);
    assert.equal(await page.locator("#design-publish").isEnabled(),true);
    assert.match(await page.locator("#design-state").textContent(),/Published v2/);
    assert.deepEqual(posts,[]);
    console.log("PASS: artist restores historical appearance into draft without publishing");
  }finally{await page.close();}
}
async function verifyPackageCompatibility(browser){
  const page=await browser.newPage({viewport:{width:390,height:844}}),posts=[];
  let mode="all",allowed=[],revision="a".repeat(64);
  const tier={id:10,slug:"ci-tier",name:"CI Tier",description:"Synthetic tier",currency:"CAD",estimate_min_minor:1000,estimate_max_minor:2000,sort_order:0,is_active:false,features:[],revision:"b".repeat(64)};
  const extras=[{id:11,slug:"extra-a",name:"Extra A",is_active:true},{id:12,slug:"extra-b",name:"Extra B",is_active:false}];
  try{
    await simulateBackend(page,posts);
    await page.route("**/api/admin/packages.php*",async route=>{
      const respond=(status,data)=>route.fulfill({status,contentType:"application/json",body:JSON.stringify(data)});
      if(route.request().method()==="GET")return respond(200,{ok:true,write_enabled:true,publish_enabled:false,catalog:{tiers:[{...tier,compatibility:{mode,allowed_addon_ids:allowed,editable:true,revision}}],addons:extras}});
      assert.deepEqual(route.request().postDataJSON(),{action:"set_compatibility",kind:"tier",id:10,mode:"selected",allowed_addon_ids:[11],expected_revision:tier.revision,expected_compatibility_revision:revision});
      mode="selected";allowed=[11];revision="c".repeat(64);
      return respond(200,{ok:true,id:10});
    });
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="packages"]').click();
    await page.locator("#package-tier-list .package-list-item").first().click();
    await page.locator("#package-name").fill("Unsaved name");
    assert.equal(await page.locator("#package-unsaved").isVisible(),true);
    assert.equal(await page.locator("#package-compatibility-mode").isEnabled(),false);
    await page.locator('[data-view="overview"]').click();
    await page.locator('[data-view="packages"]').click();
    assert.equal(await page.locator("#package-name").inputValue(),"Unsaved name");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#package-revert").click();
    assert.equal(await page.locator("#package-name").inputValue(),"CI Tier");
    await page.locator("#package-compatibility-mode").selectOption("selected");
    await page.locator('#package-compatibility-options input[value="11"]').check();
    assert.equal(await page.locator("#package-save").isEnabled(),false);
    assert.equal(await page.locator("#package-visibility").isEnabled(),false);
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#package-compatibility-save").click();
    await page.waitForFunction(()=>document.querySelector("#package-message")?.textContent==="Extra availability saved.");
    assert.equal(await page.locator("#package-compatibility-mode").inputValue(),"selected");
    assert.equal(await page.locator('#package-compatibility-options input[value="11"]').isChecked(),true);
    assert.equal(await page.locator('#package-compatibility-options input[value="12"]').isChecked(),false);
    assert.equal(await page.locator("#package-visibility").isEnabled(),false);
    assert.deepEqual(posts,[]);
    console.log("PASS: artist configures extra compatibility without publishing");
  }finally{await page.close();}
}
async function verifyAdminSecurity(browser){
  const page=await browser.newPage({viewport:{width:320,height:844}});
  const posts=[];await simulateBackend(page,posts);
  let sessions=2,changes=0;
  await page.route("**/api/admin/security.php*",route=>{
    const answer=data=>route.fulfill({status:200,contentType:"application/json",body:JSON.stringify(data)});
    if(route.request().method()==="GET")return answer({ok:true,active_sessions:sessions,activity:[{action:"admin.login",created_at:"2026-11-25 12:00:00"}]});
    assert.deepEqual(route.request().postDataJSON(),{action:"revoke_other_sessions",current_password:"synthetic-password"});
    changes++;sessions=1;return answer({ok:true,sessions_revoked:1,requires_sign_in:false});
  });
  try{
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible"});
    await page.locator('[data-view="settings"]').click();
    await page.locator("#admin-security > summary").click();
    await page.waitForFunction(()=>document.querySelector("#admin-security-status")?.textContent.includes("2 active"));
    await page.locator("#admin-security-current").fill("synthetic-password");
    page.once("dialog",dialog=>dialog.accept());
    await page.locator("#admin-security-submit").click();
    await page.waitForFunction(()=>document.querySelector("#admin-security-status")?.textContent.includes("1 active"));
    assert.equal(await page.locator("#admin-security-current").inputValue(),"");
    assert.equal(changes,1);assert.deepEqual(posts,[]);
    const dims=await horizontalOverflow(page);assert(dims.measured<=dims.available+3,JSON.stringify(dims));
    console.log("PASS: admin other-device revocation and cleared credentials");
  }finally{await page.close();}
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
    await verifyArtworkDrafts(browser);
    await verifyInterruptedArtworkUpload(browser);
    await verifyGalleryMoves(browser);
    await verifyRequestIsolation(browser);
    await verifyRequestList(browser);
    await verifyFreshAdminSignIn(browser);
    await verifyReviewAndInbox(browser);
    await verifyOrderPlanning(browser);
    await verifyQuoteFollowup(browser);
    await verifyCustomerNotice(browser);
    await verifyDeliveryDraftManagement(browser);
    await verifyFinancialException(browser);
    await verifyIntakeControl(browser);
    await verifyPrivacyQueue(browser);
    await verifyCheckoutLinks(browser);
    await verifyUnpaidCancellation(browser);
    await verifyDesignHistory(browser);
    await verifyPackageCompatibility(browser);
    await verifyAdminSecurity(browser);
    console.log("ADMIN_BROWSER_SMOKE=PASS");
  } finally {await browser.close();}
})().catch(error=>{
  console.error(error.stack||String(error));
  process.exitCode=1;
});
