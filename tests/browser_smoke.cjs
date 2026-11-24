"use strict";
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const base = "http://127.0.0.1:18868";
const output = path.join(process.cwd(), "test-results");
fs.mkdirSync(output, { recursive: true });

async function mockPublicBackend(page) {
  const responses = [
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

(async () => {
  const browser = await chromium.launch({ headless: true });
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
    await checkClosedWorkflows(browser);
    console.log("BROWSER_SMOKE=PASS");
  } finally { await browser.close(); }
})().catch(error => {
  console.error(error.stack || String(error));
  process.exitCode = 1;
});
