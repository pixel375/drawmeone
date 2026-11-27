"use strict";
const assert=require("node:assert/strict"),fs=require("node:fs"),{chromium}=require("playwright");
(async()=>{const browser=await chromium.launch();try{
 const page=await browser.newPage({viewport:{width:390,height:850}});
 // No credentials, no login submission, no cookies from a real administrator.
 page.on("response",r=>{const u=new URL(r.url());if(u.hostname==="drawmeone.com"&&["/api/admin/status.php","/api/admin/me.php","/studio-admin/panel.js"].includes(u.pathname))console.log("LIVE_HTTP "+u.pathname+" "+r.status());});
 page.on("requestfailed",r=>{const u=new URL(r.url());if(["drawmeone.com","challenges.cloudflare.com"].includes(u.hostname))console.log("LIVE_REQUEST_FAILED "+u.hostname+" "+r.failure()?.errorText);});
 page.on("pageerror",()=>console.log("LIVE_PAGE_ERROR"));
 fs.mkdirSync("test-results",{recursive:true});
 try{
  await page.goto("https://drawmeone.com/studio-admin/index.php",{waitUntil:"domcontentloaded",timeout:30000});
  await page.locator("#login-form").waitFor({state:"visible",timeout:20000});
  assert.equal(await page.locator("#workspace").isHidden(),true);
  // An automated browser need not solve a real challenge. Require the actual widget iframe to mount.
  await page.locator('#captcha iframe').first().waitFor({state:"attached",timeout:35000});
  assert.equal(await page.locator("#login-retry").isHidden(),true);
  console.log("V2_LIVE_SIGNED_OUT_WIDGET_MOUNTED");
 }finally{await page.screenshot({path:"test-results/live-signin.png",fullPage:true}).catch(()=>{});}
}finally{await browser.close();}})().catch(e=>{console.error(e.message);process.exitCode=1;});
