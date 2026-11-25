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

async function mockPublicBackend(page) {
  const responses = [
    ["**/api/site-settings.php*", {ok:true,presentation:"preview",published:false,version:0,settings:{}}],
    ["**/api/portfolio.php*", { ok: true, works: [] }],
    ["**/api/request-status.php*", { ok: true, requests_open: false }],
    ["**/api/catalog.php*", { ok: true, tiers: [], addons: [] }],
    ["**/api/auth/status.php*", {
      ok: true, auth_available: false, registration_available: false,
      turnstile_site_key: null
    }],
    ["**/api/auth/me.php*", { ok: true, authenticated: false }]
  ];
  for (const [pattern, value] of responses) {
    await page.route(pattern, route => route.fulfill({
      status: 200, contentType: "application/json", body: JSON.stringify(value)
    }));
  }
  // CI checks local layouts; third-party font availability is not required.
  await page.route(/^https:\/\/fonts\.(?:googleapis|gstatic)\.com\//,
    route => route.abort());
}
async function overflowData(page) {
  return page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const full = document.documentElement.scrollWidth;
    const right = [...document.querySelectorAll("body *")].filter(el => {
      if (getComputedStyle(el).position === "fixed") return false;
      const rect = el.getBoundingClientRect();
      return rect.width > 0 && rect.right > width + 2;
    }).slice(0, 12).map(el => ({
      selector: el.tagName.toLowerCase() +
        (el.id ? "#" + el.id : "") +
        (typeof el.className === "string"
          ? "." + el.className.trim().split(/\s+/).slice(0, 2).join(".")
          : ""),
      right: Math.round(el.getBoundingClientRect().right)
    }));
    return { width, full, right };
  });
}
async function checkPage(browser, site, viewport) {
  const name = site + "-" + viewport.width;
  const context = await browser.newContext({
    viewport, deviceScaleFactor: 1,
    reducedMotion: "reduce", colorScheme: "dark"
  });
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", err => errors.push(err.message));
  try {
    await context.addInitScript(pref => {
      if (!localStorage.getItem("dmo-preview-settings")) {
        localStorage.setItem("dmo-preview-settings", JSON.stringify(pref));
      }
    }, {
      site, design: "noir", mode: "dark",
      layout: site === "cinema" ? "rows" : site === "archive" ? "mosaic" : "masonry",
      logo: "original"
    });
    await mockPublicBackend(page);
    const navigation = await page.goto(base + "/index.html", {
      waitUntil: "domcontentloaded", timeout: 20000
    });
    assert.equal(navigation.status(), 200, "homepage HTTP");
    await page.waitForFunction(()=>document.documentElement.dataset.presentation==="preview");
    await page.locator("#portfolio-gallery .art-tile").first().waitFor({
      state: "visible", timeout: 12000
    });
    assert.equal(await page.locator("html").getAttribute("data-site"), site,
      name + " theme restored");
    assert.equal(await page.locator("#portfolio-gallery .art-tile").count(),
      site === "cinema" ? 24 : viewport.width <= 620 ? 8 : 12,
      name + " initial gallery count");
    assert.equal(await page.locator("#gallery-demo-notice").isVisible(), true,
      name + " sample artwork is identified");
    const mailto = page.locator('#contact a[href^="mailto:support@drawmeone.com"]').first();
    assert.equal(await mailto.count(), 1, name + " studio contact link exists");
    assert.equal(await mailto.isVisible(), true, name + " studio contact link visible");
    assert.equal(await page.locator('#contact a[href$="commission.html"]').count(), 1,
      name + " commissions information link exists");
    const overflow = await overflowData(page);
    assert(overflow.full <= overflow.width + 3,
      name + " horizontal overflow " + JSON.stringify(overflow));
    assert.deepEqual(errors, [], name + " page JavaScript exceptions");

    if (viewport.width >= 1200 && site === "studio") {
      // Clicking all four design choices must not recreate/destroy the gallery.
      for (const next of ["archive", "journal", "cinema", "studio"]) {
        await page.locator('[data-site-choice="' + next + '"]').click();
        assert.equal(await page.locator("html").getAttribute("data-site"), next);
        assert.equal(await page.locator("#portfolio-gallery").count(), 1);
        assert.equal(await page.locator('#contact a[href^="mailto:"]').count(), 1);
      }
      await page.locator('[data-design-choice="ember"]').click();
      await page.locator('[data-mode-choice="light"]').click();
      assert.equal(await page.locator("html").getAttribute("data-design"), "ember");
      assert.equal(await page.locator("html").getAttribute("data-mode"), "light");
      await page.locator('[data-logo-choice="01-minimal-pencil"]').click();
      assert.equal(await page.locator("html").getAttribute("data-logo"),
        "01-minimal-pencil");
      await page.reload({ waitUntil: "domcontentloaded" });
      await page.waitForFunction(()=>document.documentElement.dataset.presentation==="preview");
      await page.locator("#portfolio-gallery .art-tile").first().waitFor();
      assert.equal(await page.locator("html").getAttribute("data-site"), "studio");
      assert.equal(await page.locator("html").getAttribute("data-design"), "ember");
      assert.equal(await page.locator("html").getAttribute("data-mode"), "light");
      assert.equal(await page.locator("html").getAttribute("data-logo"),
        "01-minimal-pencil");
      console.log("PASS: layout switching and persisted design/mode/logo");

      const gallery = page.locator("#portfolio-gallery .art-tile");
      await gallery.first().click();
      assert.equal(await page.locator("#portfolio-lightbox").evaluate(
        element => element.open), true);
      assert.equal(await page.locator("#lightbox-detail-link").isVisible(), false,
        "demo samples must not expose artwork detail pages");
      await page.keyboard.press("ArrowRight");
      assert.match(await page.locator("#lightbox-counter").innerText(), /^2 \/ 24$/);
      await page.keyboard.press("Escape");
      assert.equal(await page.locator("#portfolio-lightbox").evaluate(
        element => element.open), false);
      console.log("PASS: keyboard-enabled image viewer");

      await page.locator("#gallery-more-button").click();
      assert.equal(await page.locator("#portfolio-gallery .art-tile").count(), 24,
        "load more reveals all demo images");
      console.log("PASS: gallery load more");
    }
    if (site === "studio" || viewport.width === 390) {
      await page.screenshot({
        path: path.join(output, name + ".png"),
        animations: "disabled", fullPage: false
      });
    }
    console.log("PASS: " + name + " gallery/contact/layout/no-overflow");
  } catch (error) {
    console.error("FAIL: " + name + ": " + error.stack);
    try { await page.screenshot({ path: path.join(output, name + "-failure.png") }); }
    catch (_) {}
    throw error;
  } finally {
    await context.close();
  }
}
// Deliberately synthetic published entries and images. Never contacts production.
async function checkPublishedPortfolio(browser) {
  for (const legacyUnavailable of [false, true]) {
    const context = await browser.newContext({
      viewport: {width: 1440, height: 900}, reducedMotion: "reduce"
    });
    const page = await context.newPage();
    const artwork = id => ({
      src: "api/artwork.php?id="+id+"&v=display",
      thumb: "api/artwork.php?id="+id+"&v=thumb",
      alt: "Synthetic CI drawing "+id, width: 120, height: 180
    });
    const sample = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="180"><rect width="120" height="180" fill="#aaa"/></svg>';
    const reply = (route,data) => route.fulfill({
      status:200,contentType:"application/json",body:JSON.stringify(data)
    });
    try {
      await context.addInitScript(() => localStorage.setItem("dmo-preview-settings",
        JSON.stringify({site:"studio",design:"noir",mode:"dark",layout:"masonry",logo:"original"})));
      await mockPublicBackend(page);
      await page.route("**/api/portfolio.php*", route => reply(route,{ok:true,works:[
        artwork(101),artwork(102),artwork(103),artwork(104),
        {...artwork(105),thumb:artwork(999).thumb},
        {...artwork(106),src:"https://example.invalid/not-art.webp"}
      ]}));
      await page.route("**/data/portfolio.json", route => legacyUnavailable
        ? route.fulfill({status:503,body:"Synthetic legacy manifest outage"})
        : reply(route,{works:[{...artwork(101),alt:"Stale duplicate label"}]}));
      await page.route("**/api/artwork.php?*", route => {
        const url=new URL(route.request().url());
        const broken=["103","104"].includes(url.searchParams.get("id"))
          && url.searchParams.get("v")==="display";
        return broken ? route.fulfill({status:404,body:"Synthetic missing display"})
          : route.fulfill({status:200,contentType:"image/svg+xml",body:sample});
      });
      await page.goto(base+"/index.html",{waitUntil:"domcontentloaded"});
      const tiles=page.locator("#portfolio-gallery .art-tile");
      await tiles.first().waitFor({state:"visible"});
      assert.equal(await tiles.count(),4,"invalid entries and duplicates excluded");
      assert.equal(await page.locator("#gallery-demo-notice").isVisible(),false);
      assert.equal(await page.locator("html").getAttribute("data-has-artwork"),"true");
      assert.equal(await page.locator("#portfolio-error").isVisible(),false);
      assert.equal(await tiles.first().locator("img").getAttribute("alt"),"Synthetic CI drawing 101");
      assert.match(await tiles.first().locator("img").getAttribute("src"),/id=101&v=thumb$/);
      await page.locator("[data-site-choice=archive]").click();
      await page.waitForFunction(()=>[...document.querySelectorAll("[data-showcase-image]")]
        .every(img=>img.src.includes("/api/artwork.php?")));
      assert.equal(await page.locator("#portfolio-gallery .art-tile").count(),4);
      await page.locator("[data-site-choice=studio]").click();
      await tiles.first().click();
      const image=page.locator("#lightbox-image"),link=page.locator("#lightbox-detail-link");
      assert.match(await image.getAttribute("src"),/id=101&v=display$/);
      assert.equal(await link.getAttribute("href"),"./artwork.php?id=101");
      await page.keyboard.press("ArrowRight");
      assert.equal(await link.getAttribute("href"),"./artwork.php?id=102");
      // Two consecutive missing full-size images must both fall back.
      for (const id of [103,104]) {
        await page.keyboard.press("ArrowRight");
        await page.waitForFunction(()=>document.querySelector("#lightbox-image")
          ?.getAttribute("src")==="./assets/demo/placeholder.svg");
        assert.equal(await link.getAttribute("href"),"./artwork.php?id="+id);
      }
      await page.keyboard.press("ArrowRight");
      assert.match(await image.getAttribute("src"),/id=101&v=display$/);
      await page.keyboard.press("Escape");
      assert.equal(await page.locator("#portfolio-lightbox").evaluate(el=>el.open),false);
      console.log("PASS: published portfolio / invalid manifests / consecutive missing images / legacy outage="+legacyUnavailable);
    } finally {await context.close();}
  }
}

async function checkClosedWorkflows(browser) {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 }, reducedMotion: "reduce"
  });
  const page = await context.newPage();
  await mockPublicBackend(page);
  try {
    await page.goto(base + "/commission.html", {waitUntil: "domcontentloaded"});
    await page.locator("#request-status").waitFor();
    assert.equal(await page.locator("#request-closed").isVisible(), true,
      "commission closed when server says closed");
    assert.equal(await page.locator("#customer-workspace").isVisible(), false);
    assert.equal(await page.locator('#request-closed a[href^="mailto:"]').count(), 1);
    assert.equal(await page.locator("#request-create-panel").isVisible(), false);
    await page.goto(base + "/account.html", {waitUntil: "domcontentloaded"});
    await page.locator("#service-state").waitFor();
    assert.equal(await page.locator("#unavailable").isVisible(), true);
    assert.equal(await page.locator("#auth-workspace").isVisible(), false);
    await page.goto(base + "/password-reset.html", {waitUntil: "domcontentloaded"});
    await page.locator("#reset-state").waitFor();
    assert.equal(await page.locator("#reset-unavailable").isVisible(), true);
    assert.equal(await page.locator("#reset-workspace").isVisible(), false);
    console.log("PASS: closed commission/account/password recovery gates");
  } finally { await context.close(); }
  const noJs = await browser.newContext({
    viewport: { width: 390, height: 844 }, javaScriptEnabled: false
  });
  try {
    const staticPage = await noJs.newPage();
    await staticPage.goto(base + "/commission.html", {waitUntil: "domcontentloaded"});
    assert.equal(await staticPage.locator("#request-closed").isVisible(), true);
    assert.equal(await staticPage.locator('#request-closed a[href^="mailto:"]').count(), 1);
    console.log("PASS: no-JavaScript commission contact fallback");
  } finally { await noJs.close(); }
}

async function checkHomepageIntake(browser) {
  const context=await browser.newContext({viewport:{width:1440,height:900},reducedMotion:"reduce"});
  const page=await context.newPage();
  let reply={ok:true,requests_open:true,intake_reason:"open"},status=200;
  try {
    await mockPublicBackend(page);
    await page.route("**/api/request-status.php*",route=>route.fulfill({status,contentType:"application/json",body:JSON.stringify(reply)}));
    await page.goto(base+"/index.html",{waitUntil:"domcontentloaded"});
    for(const site of ["studio","archive","journal","cinema"]){
      await page.locator('[data-site-choice="'+site+'"]').click();
      await page.waitForFunction(()=>document.querySelector("#contact [data-intake-cta]")?.textContent==="Start a commission request");
      assert.equal(await page.locator("#contact [data-intake-label]").textContent(),"Commission requests open");
      assert.match(await page.locator("#contact [data-intake-description]").textContent(),/not a confirmed order/);
      assert.equal(await page.locator('#contact a[href$="commission.html"]').count(),1);
    }
    for(const [payload,http,label] of [
      [{ok:true,requests_open:false,intake_reason:"paused"},200,"Requests temporarily paused"],
      [{ok:true,requests_open:false,intake_reason:"at_capacity"},200,"Currently at capacity"],
      [{ok:true,requests_open:true,intake_reason:"paused"},200,"Requests not open"],
      [{ok:false},503,"Requests not open"]
    ]){
      reply=payload;status=http;
      await page.reload({waitUntil:"domcontentloaded"});
      await page.waitForFunction(label=>document.querySelector("#contact [data-intake-label]")?.textContent===label,label);
      for(const site of ["studio","archive","journal","cinema"]){
        await page.locator('[data-site-choice="'+site+'"]').click();
        assert.equal(await page.locator("#contact [data-intake-label]").textContent(),label);
        assert.equal(await page.locator("#contact [data-intake-cta]").textContent(),"Commission information");
      }
    }
    console.log("PASS: all four designs reflect verified intake, pause, capacity and closed fallback");
  }finally{await context.close();}
}

async function checkPublishedPresentation(browser){
  const context=await browser.newContext({viewport:{width:390,height:844},reducedMotion:"reduce"});
  const page=await context.newPage();
  const settings={site_style:"studio",color_theme:"ember",gallery_layout:"rows",default_mode:"light",logo:"01-minimal-pencil"};
  let payload={ok:true,presentation:"published",published:true,version:3,settings},http=200;
  try{
    await context.addInitScript(()=>{localStorage.setItem("dmo-preview-settings",JSON.stringify({site:"cinema",design:"aurora",layout:"mosaic",mode:"dark",logo:"original"}));});
    await mockPublicBackend(page);
    await page.route("**/api/site-settings.php*",route=>route.fulfill({status:http,contentType:"application/json",body:JSON.stringify(payload)}));
    await page.goto(base+"/index.html",{waitUntil:"domcontentloaded"});
    await page.waitForFunction(()=>document.documentElement.dataset.presentation==="public");
    const state=()=>page.locator("html").evaluate(el=>[el.dataset.site,el.dataset.design,el.dataset.layout,el.dataset.mode,el.dataset.logo]);
    assert.deepEqual(await state(),["studio","ember","rows","light","01-minimal-pencil"]);
    assert.equal(await page.locator("#design-lab").isVisible(),false);
    await page.locator('[data-site-choice="cinema"]').evaluate(el=>el.click());
    assert.equal((await state())[0],"studio","hidden preview control cannot change published style");
    await page.locator("#header-mode").click();
    assert.equal((await state())[3],"dark");
    await page.reload({waitUntil:"domcontentloaded"});
    await page.waitForFunction(()=>document.documentElement.dataset.presentation==="public");
    assert.deepEqual(await state(),["studio","ember","rows","dark","01-minimal-pencil"]);
    for(const [body,code] of [
      [{...payload,settings:{...settings,site_style:"cinema"}},200],
      [{...payload,settings:{...settings,unexpected:"value"}},200],
      [{...payload,published:false},200],
      [{ok:false},503]
    ]){
      payload=body;http=code;
      await page.reload({waitUntil:"domcontentloaded"});
      await page.waitForFunction(()=>document.documentElement.dataset.presentation==="public");
      assert.deepEqual(await state(),["studio","noir","masonry","dark","original"]);
      assert.equal(await page.locator("#design-lab").isVisible(),false);
    }
    console.log("PASS: published design, separate visitor mode, stale preview isolation and safe fallback");
  }finally{await context.close();}
}

(async () => {
  const browser = await browserType.launch({ headless: true });
  try {
    for (const site of ["studio","archive","journal","cinema"]) {
      for (const viewport of [
        { width: 1440, height: 900 },
        { width: 768, height: 1024 },
        { width: 390, height: 844 }
      ]) {
        await checkPage(browser, site, viewport);
      }
    }
    await checkPublishedPortfolio(browser);
    await checkClosedWorkflows(browser);
    await checkHomepageIntake(browser);
    await checkPublishedPresentation(browser);
    console.log("BROWSER_SMOKE=PASS");
  } finally { await browser.close(); }
})().catch(error => {
  console.error(error.stack || String(error));
  process.exitCode = 1;
});
