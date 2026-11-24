"use strict";
const { chromium }=require("playwright");
const assert=require("node:assert/strict");
const fs=require("node:fs");
const path=require("node:path");

const base="http://127.0.0.1:18868";
const out=path.join(process.cwd(),"test-results");
fs.mkdirSync(out,{recursive:true});
const axePath=require.resolve("axe-core/axe.min.js");
const tags=["wcag2a","wcag2aa","wcag21a","wcag21aa"];
const failures=[], allViolations=[];
const preset={
  studio:"studio",atelier:"archive",edition:"journal",signature:"cinema"
};
async function mocks(page) {
  for(const [url,value] of [
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
  await page.addScriptTag({path:axePath});
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
    if(site==="studio"&&width===390) {
      await page.keyboard.press("Tab");
      const focused=await page.evaluate(()=>({
        tag:document.activeElement?.tagName,
        text:document.activeElement?.textContent?.trim().slice(0,60)
      }));
      assert(focused.tag==="A"&&/Skip to portfolio/i.test(focused.text),
        "homepage keyboard focus starts at skip link");
      await page.keyboard.press("Enter");
      const hash=new URL(page.url()).hash;
      assert.equal(hash,"#portfolio","skip link navigates to gallery");
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
      assert.equal(new URL(page.url()).hash,"#main","skip link reaches main landmark");
      console.log("PASS: "+url+" skip-to-main keyboard navigation");
    }
  } finally{await context.close();}
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
    await home(browser,"studio",390,"light");
    for(const file of ["/commission.html","/account.html","/password-reset.html"]){
      await closedPage(browser,file,390);
      await closedPage(browser,file,1440);
    }
    fs.writeFileSync(path.join(out,"accessibility-report.json"),
      JSON.stringify({tested:new Date().toISOString(),scans:allViolations},null,2));
    if(failures.length)throw new Error("Serious/critical axe violations:\n"+failures.join("\n"));
    console.log("ACCESSIBILITY_BROWSER_SMOKE=PASS");
  }finally{await browser.close();}
})().catch(err=>{console.error(err.stack||String(err));process.exitCode=1;});
