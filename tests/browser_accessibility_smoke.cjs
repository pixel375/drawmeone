"use strict";
const { chromium }=require("playwright");
const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");

const base="http://127.0.0.1:18868";
const out=path.join(process.cwd(),"test-results");
fs.mkdirSync(out,{recursive:true});
const axePath=require.resolve("axe-core/axe.min.js");
const axeSource=fs.readFileSync(axePath,"utf8");
const tags=["wcag2a","wcag2aa","wcag21a","wcag21aa"];
const failures=[], allViolations=[];
const preset={
  studio:"studio",atelier:"archive",edition:"journal",signature:"cinema"
};
async function mocks(page) {
  for(const [url,value] of [
    ["**/api/site-settings.php*",{ok:true,presentation:"preview",published:false,version:0,settings:{}}],
    ["**/api/portfolio.php*",{ok:true,works:[]}],
    ["**/api/request-status.php*",{ok:true,requests_open:false}],
    ["**/api/catalog.php*",{ok:true,tiers:[],addons:[]}],
    ["**/api/auth/status.php*",{ok:true,auth_available:false,registration_available:false,turnstile_site_key:null}],
    ["**/api/auth/me.php*",{ok:true,authenticated:false}]
  ])await page.route(url,route=>route.fulfill({
    status:200,contentType:"application/json",body:JSON.stringify(value)
  }));
  await page.route(/^https:\/\/fonts\.(?:googleapis|gstatic)\.com\//,
    route=>route.abort());
}
async function checkAxe(page,label) {
  // Respect the Artist Desk's strict script-src 'self' policy. Playwright's
  // inline addScriptTag(path) is correctly blocked by that CSP, so serve
  // axe from an intercepted local same-origin URL only inside CI.
  if(!(await page.evaluate(()=>!!window.axe))){
    await page.route("**/__dmo_ci_axe.min.js",route=>route.fulfill({
      status:200,contentType:"application/javascript",body:axeSource
    }));
    await page.addScriptTag({url:base+"/__dmo_ci_axe.min.js"});
  }
  const result=await page.evaluate(async tags=>window.axe.run(document,{
    runOnly:{type:"tag",values:tags},
    rules:{"region":{enabled:false}}
  }),tags);
  const violations=result.violations.map(v=>({
    rule:v.id,impact:v.impact,description:v.help,
    nodes:v.nodes.map(n=>({
      target:n.target,summary:n.failureSummary?.slice(0,240)
    })).slice(0,12)
  }));
  allViolations.push({label,violations});
  for(const v of violations) {
    const readable=v.nodes.slice(0,4).map(n=>n.target.join(" ")).join(", ");
    console.log("ACCESSIBILITY "+label+" "+v.impact+" "+v.rule+": "+readable);
    if(["critical","serious"].includes(v.impact))failures.push(label+" "+v.rule+" "+readable);
  }
  console.log("PASS: "+label+" axe audit completed; "+violations.length+" rule categories");
}
async function home(browser,site,width,mode="dark") {
  const context=await browser.newContext({
    viewport:{width,height:900},colorScheme:mode,reducedMotion:"reduce"
  });
  const page=await context.newPage();
  try {
    await page.addInitScript(pref=>{
      localStorage.setItem("dmo-preview-settings",JSON.stringify(pref));
    },{site,design:"noir",mode,layout:"masonry",logo:"original"});
    await mocks(page);
    await page.goto(base+"/index.html",{waitUntil:"domcontentloaded"});
    await page.waitForFunction(()=>document.documentElement.dataset.presentation==="preview");
    await page.locator("#portfolio-gallery .art-tile").first().waitFor({state:"visible",timeout:15000});
    await checkAxe(page,"home-"+site+"-"+width+"-"+mode);
    if(site==="studio" && width===1440 && mode==="dark") {
      const first=page.locator("#portfolio-gallery .art-tile").first();
      await first.click();
      assert.equal(await page.locator("#portfolio-lightbox").evaluate(el=>el.open),true);
      await checkAxe(page,"gallery-open-modal-desktop");
      assert.equal(await page.locator(".lightbox-close").evaluate(el=>el===document.activeElement),true,
        "gallery puts keyboard focus on accessible close button");
      await page.keyboard.press("Escape");
      assert.equal(await first.evaluate(el=>el===document.activeElement),true,
        "closing gallery returns keyboard focus to the originating drawing");
      console.log("PASS: gallery modal accessibility and focus restoration");
    }
    if(width===390) {
      await page.keyboard.press("Tab");
      const focused=await page.evaluate(()=>({
        tag:document.activeElement?.tagName,
        text:document.activeElement?.textContent?.trim().slice(0,60)
      }));
      assert(focused.tag==="A"&&/Skip to portfolio/i.test(focused.text),
        "homepage keyboard focus starts at skip link");
      await page.keyboard.press("Enter");
      await page.waitForFunction(()=>location.hash==="#portfolio"&&document.activeElement?.id==="portfolio");
      assert.equal(new URL(page.url()).hash,"#portfolio","skip link navigates to gallery");
      assert.equal(await page.locator("#portfolio").evaluate(el=>el===document.activeElement),true,"skip link focuses visible gallery section");
      console.log("PASS: homepage keyboard skip link");
    }
  } finally{await context.close();}
}
async function closedPage(browser,url,width) {
  const context=await browser.newContext({
    viewport:{width,height:900},reducedMotion:"reduce"
  });
  const page=await context.newPage();
  try {
    await mocks(page);
    await page.goto(base+url,{waitUntil:"domcontentloaded"});
    // Wait for the appropriate gated state, not the transient startup view.
    if(url.includes("commission"))await page.waitForFunction(()=>
      document.querySelector("#request-status")?.textContent?.includes("closed"));
    else if(url.includes("account"))await page.waitForFunction(()=>
      document.querySelector("#service-state")?.textContent?.includes("not open"));
    else await page.waitForFunction(()=>
      document.querySelector("#reset-state")?.textContent?.includes("closed"));
    await checkAxe(page,url+"-"+width);
    if(width===390&&(url.includes("account")||url.includes("password-reset"))){
      await page.keyboard.press("Tab");
      const label=await page.evaluate(()=>document.activeElement?.textContent?.trim());
      assert.equal(label,"Skip to main content","keyboard skip link is first accessible control");
      await page.keyboard.press("Enter");
      await page.waitForFunction(()=>location.hash==="#main");
      assert.equal(new URL(page.url()).hash,"#main","skip link reaches main landmark");
      console.log("PASS: "+url+" skip-to-main keyboard navigation");
    }
  } finally{await context.close();}
}
const syntheticAdmin={
  "status.php":{ok:true,admin_schema_ready:true,admin_available:true,
    turnstile_site_key:"ci-only-unusable-site-key"},
  "me.php":{ok:true,authenticated:true,
    admin:{display_name:"Synthetic QA Artist",email:"admin@example.invalid"},
    csrf_token:"synthetic-browser-only"},
  "overview.php":{ok:true,readonly:true,
    counts:{requests_for_review:0,active_orders:0,payments_to_verify:0,messages_last_seven_days:0},
    readiness:[
      {key:"mail",label:"Transactional email",status:"ready",detail:"Synthetic QA"},
      {key:"artwork",label:"Published portfolio",status:"blocked",detail:"No real works"},
      {key:"accounts",label:"Customer accounts",status:"blocked",detail:"Closed"},
      {key:"payments",label:"Payment processing",status:"planned",detail:"Unavailable"}
    ]},
  "requests.php":{ok:true,readonly:true,requests:[]},
  "messages.php":{ok:true,readonly:true,messages:[]},
  "finance.php":{ok:true,readonly:true,scope:"local_records_only",as_of:"2026-11-25",summary:[{currency:"CAD",record_state:"reconciled",record_count:1,amount_minor:3000}],records:[{id:88,order_id:87,request_id:86,public_ref:"CI-86",method:"bank_transfer",currency:"CAD",amount_minor:3000,status:"verified",record_state:"reconciled",created_at:"2026-11-25",verified_at:"2026-11-25"}],next_before:null},
  "artworks.php":{ok:true,write_enabled:false,artworks:[
    {id:4,visibility:"draft",featured:false,sort_order:0,width:640,height:480,
      created_at:"2026-11-24"}]},
  "packages.php":{ok:true,write_enabled:false,publish_enabled:false,
    catalog:{tiers:[],addons:[]}},
  "design.php":{ok:true,write_enabled:false,
    draft:{exists:false,version:0},published:{exists:false}},
  "email.php":{ok:true,mail_ready:false,transport:"none",
    smtp_settings_present:false,customer_login_enabled:false,
    customer_registration_enabled:false}
};
async function authenticatedAdmin(browser,width) {
  const context=await browser.newContext({
    viewport:{width,height:920},reducedMotion:"reduce"
  });
  const page=await context.newPage();
  let writes=0;
  try {
    await page.route("**/api/admin/**",route=>{
      if(route.request().method()!=="GET"){
        writes++;
        return route.fulfill({status:503,contentType:"application/json",
          body:'{"ok":false,"error":"ci_mutations_forbidden"}'});
      }
      const name=new URL(route.request().url()).pathname.split("/").pop();
      const value=syntheticAdmin[name]||{ok:false,error:"ci_unmocked"};
      return route.fulfill({status:syntheticAdmin[name]?200:404,
        contentType:"application/json",body:JSON.stringify(value)});
    });
    await page.goto(base+"/studio-admin/index.php",{waitUntil:"domcontentloaded"});
    await page.locator("#workspace").waitFor({state:"visible",timeout:15000});
    await page.locator("#launch-checks .launch-check").first().waitFor();
    if(width===320){
      await page.keyboard.press("Tab");
      assert.equal(await page.evaluate(()=>document.activeElement?.classList.contains("skip")),
        true,"artist keyboard starts at Skip to dashboard");
      await page.keyboard.press("Enter");
      await page.waitForFunction(()=>document.activeElement?.id==="main");
      assert.equal(await page.evaluate(()=>document.activeElement?.id),"main",
        "Artist Desk skip link moves keyboard focus into main");
      console.log("PASS: authenticated Artist Desk skip-to-main keyboard focus");
    }
    for(const name of ["overview","requests","messages","finance","artwork","packages","settings"]){
      await page.locator('[data-view="'+name+'"]').click();
      await page.locator('[data-panel="'+name+'"]').waitFor({state:"visible"});
      if(name==="finance") await page.locator("#finance-records article").first().waitFor();
      if(name==="artwork") await page.locator(".artwork-library-card").first()
        .waitFor({state:"visible"});
      await checkAxe(page,"authenticated-artist-"+name+"-"+width);
      if(width===320){
        const dims=await page.evaluate(()=>({
          viewport:document.documentElement.clientWidth,
          scroll:document.documentElement.scrollWidth
        }));
        assert(dims.scroll<=dims.viewport+3,
          "artist "+name+" fails 320px reflow "+JSON.stringify(dims));
      }
    }
    assert.equal(writes,0,"artist accessibility tests never submit writes");
    console.log("PASS: all six synthetic authenticated Artist Desk accessibility panels "+width+"px");
  }finally{await context.close();}
}
async function authenticatedCustomer(browser,width) {
  const context=await browser.newContext({
    viewport:{width,height:900},reducedMotion:"reduce"
  });
  const page=await context.newPage();
  let writes=0;
  const ref="DMO-"+"a".repeat(20);
  try {
    await page.route("**/api/**",route=>{
      const req=route.request(),url=new URL(req.url()),name=url.pathname;
      if(req.method()!=="GET"){
        writes++;
        return route.fulfill({status:503,contentType:"application/json",
          body:'{"ok":false,"error":"ci_mutations_forbidden"}'});
      }
      let value;
      if(name==="/api/request-status.php")
        value={ok:true,requests_open:false,text_only:true,policy_version:null};
      if(name==="/api/catalog.php")
        value={ok:true,requests_open:false,tiers:[],addons:[]};
      if(name==="/api/auth/status.php")
        value={ok:true,auth_available:true,registration_available:false};
      if(name==="/api/auth/me.php")
        value={ok:true,authenticated:true,
          user:{display_name:"Synthetic Customer",email:"customer@example.invalid",role:"customer"},
          csrf_token:"ci-synthetic-only"};
      if(name==="/api/requests.php")
        value={ok:true,requests:[{public_ref:ref,subject_type:"portrait",
          tier_name:"Portrait",status:"submitted",submitted_at:"2026-11-23"}]};
      if(name==="/api/request.php")
        value={ok:true,request:{public_ref:ref,subject_type:"portrait",
          brief:"Synthetic customer artwork brief for isolated accessibility testing.",
          status:"submitted",tier_name:"Portrait",policy_version:"ci-v1",
          preferred_contact:"onsite",preferred_deadline:null},
          addons:[],references:[],can_manage_references:false,
          can_upload_references:false,can_reply:false,
          messages:[{sender_name:"Synthetic Artist",body:"Synthetic private reply.",
            created_at:"2026-11-23"}],
          quotes:[{id:19,version_number:1,status:"sent",
            scope_description:"Original portrait with a plain background",
            total_minor:12500,currency:"CAD",revision_allowance:1,
            validity_ends_at:"2026-12-03 20:00:00",is_expired:false,
            can_respond:true,lines:[{description:"Portrait",amount_minor:12500}]}],
          orders:[{id:9,status:"awaiting_arrangement",
            payment_status:"unverified",total_minor:12500,currency:"CAD",
            created_at:"2026-11-23"}],
          payments_enabled:false};
      return route.fulfill({status:value?200:404,
        contentType:"application/json",
        body:JSON.stringify(value||{ok:false,error:"ci_unmocked"})});
    });
    await page.goto(base+"/commission.html",{waitUntil:"domcontentloaded"});
    await page.locator("#my-request-list .request-list-item").first()
      .waitFor({state:"visible",timeout:15000});
    if(width===320){
      await page.keyboard.press("Tab");
      assert.equal(await page.evaluate(()=>document.activeElement?.classList.contains("skip")),
        true,"customer keyboard starts at Skip to content");
      await page.keyboard.press("Enter");
      await page.waitForFunction(()=>document.activeElement?.id==="main");
      assert.equal(await page.evaluate(()=>document.activeElement?.id),"main",
        "customer skip link moves keyboard focus into main");
      console.log("PASS: authenticated customer skip-to-main keyboard focus");
    }
    await checkAxe(page,"authenticated-customer-history-"+width);
    await page.locator("#my-request-list .request-list-item button").first().click();
    await page.locator("#detail-content").waitFor({state:"visible"});
    await checkAxe(page,"authenticated-customer-quote-"+width);
    if(width===320){
      const dims=await page.evaluate(()=>({
        viewport:document.documentElement.clientWidth,
        scroll:document.documentElement.scrollWidth
      }));
      assert(dims.scroll<=dims.viewport+3,
        "customer quote fails 320px reflow "+JSON.stringify(dims));
    }
    assert.equal(writes,0,"customer accessibility tests never submit writes");
    console.log("PASS: synthetic customer request and quote accessibility "+width+"px");
  }finally{await context.close();}
}

(async()=>{
  const browser=await chromium.launch({headless:true});
  try {
    for(const site of Object.values(preset)) {
      await home(browser,site,390);
      await home(browser,site,1440);
    }
    for(const site of Object.values(preset)) {
      await home(browser,site,1440,"light");
    }
    for(const site of Object.values(preset))await home(browser,site,390,"light");
    for(const file of ["/commission.html","/account.html","/password-reset.html"]){
      await closedPage(browser,file,390);
      await closedPage(browser,file,1440);
    }
    for(const width of [320,1440]){
      await authenticatedAdmin(browser,width);
      await authenticatedCustomer(browser,width);
    }
    fs.writeFileSync(path.join(out,"accessibility-report.json"),
      JSON.stringify({tested:new Date().toISOString(),scans:allViolations},null,2));
    if(failures.length)throw new Error("Serious/critical axe violations:\n"+failures.join("\n"));
    console.log("ACCESSIBILITY_BROWSER_SMOKE=PASS");
  }finally{await browser.close();}
})().catch(err=>{console.error(err.stack||String(err));process.exitCode=1;});
