"use strict";
const { chromium } = require("playwright");
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
  "artworks.php": {ok:true,artworks:[],write_enabled:false},
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
        await page.locator("#artwork-library-status").waitFor();
        assert.equal(await page.locator("#artwork-upload").isEnabled(),false,
          "uploads disabled");
        assert.equal(await page.locator("#artwork-publish-selected").isEnabled(),false,
          "publish disabled");
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
  const browser=await chromium.launch({headless:true});
  try {
    for(const width of [320,390,768,1440]) await verifyArtistDesk(browser,width);
    await verifyClosedDesk(browser);
    console.log("ADMIN_BROWSER_SMOKE=PASS");
  } finally {await browser.close();}
})().catch(error=>{
  console.error(error.stack||String(error));
  process.exitCode=1;
});
