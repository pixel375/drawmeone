"use strict";
(() => {
  const $ = id => document.getElementById(id);
  const api = "../api/admin/";
  let csrf = "";
  let widget = null;
  let siteKey = "";

  function show(section) {
    for (const id of ["closed", "sign-in", "workspace"]) {
      $(id).hidden = id !== section;
    }
  }

  async function get(path) {
    const response = await fetch(api + path, {
      cache: "no-store", credentials: "same-origin",
    });
    const data = await response.json();
    return { response, data };
  }

  async function post(path, payload, token) {
    const headers = { "Content-Type": "application/json" };
    if (token) headers["X-DMO-CSRF"] = token;
    const response = await fetch(api + path, {
      method: "POST", credentials: "same-origin", cache: "no-store",
      headers, body: JSON.stringify(payload),
    });
    return { response, data: await response.json() };
  }

  function showError(message) {
    const box = $("work-error");
    box.hidden = false;
    box.textContent = message;
  }
  function clearError() {
    $("work-error").hidden = true;
    $("work-error").textContent = "";
  }

  function empty(container, message) {
    container.replaceChildren();
    const p = document.createElement("p");
    p.className = "empty-state";
    p.textContent = message;
    container.appendChild(p);
  }

  function record(container, label, title, detail, meta) {
    const item = document.createElement("article");
    item.className = "record";
    const body = document.createElement("div");
    const eyebrow = document.createElement("div");
    eyebrow.className = "record-label";
    eyebrow.textContent = String(label || "");
    const name = document.createElement("strong");
    name.textContent = String(title || "");
    const description = document.createElement("p");
    description.textContent = String(detail || "");
    body.append(eyebrow, name, description);
    const stamp = document.createElement("span");
    stamp.className = "record-meta";
    stamp.textContent = String(meta || "");
    item.append(body, stamp);
    container.appendChild(item);
  }

  let intakeVersion=null,intakeBusy=false,intakeSaved=null,intakeDraft=null,intakeEpoch=0;
  function intakeFields(){return {paused:$("intake-mode").value==="paused",capacity:$("intake-capacity").value};}
  function intakeSame(a,b){return a&&b&&a.paused===b.paused&&String(a.capacity)===String(b.capacity);}
  function syncIntakeDraft(){
    const conflict=!!intakeDraft&&intakeVersion!==null&&intakeDraft.version!==intakeVersion;
    $("intake-draft-status").textContent=intakeDraft?(conflict?"Saved settings changed. Discard the older draft before editing the current settings.":intakeVersion===null?"Unsent settings retained. Refresh readiness before saving.":"Unsent settings retained in this page."):"";
    $("intake-discard").hidden=!intakeDraft;
    $("intake-discard").disabled=intakeBusy||intakeVersion===null;
    $("intake-mode").disabled=$("intake-capacity").disabled=intakeBusy||intakeVersion===null||conflict;
    $("intake-save").disabled=intakeBusy||intakeVersion===null||conflict;
  }
  function captureIntakeDraft(){
    if(intakeBusy||intakeVersion===null||!intakeSaved||$("intake-mode").disabled)return;
    const next=intakeFields();
    intakeDraft=intakeSame(next,intakeSaved)?null:{...next,version:intakeVersion};
    syncIntakeDraft();
  }
  for(const id of ["intake-mode","intake-capacity"])$(id).addEventListener("input",captureIntakeDraft);
  $("intake-discard").addEventListener("click",()=>{
    if(intakeBusy||intakeVersion===null||!intakeSaved||!intakeDraft)return;
    if(!window.confirm("Discard unsent intake settings? Saved availability will not change."))return;
    intakeDraft=null;$("intake-mode").value=intakeSaved.paused?"paused":"open";$("intake-capacity").value=String(intakeSaved.capacity);syncIntakeDraft();
  });
  function renderIntake(data){
    const form=$("intake-form"),notice=$("intake-status");
    const configured=data?.control_enabled===true&&Number.isSafeInteger(data.version)&&data.version>=0&&typeof data.paused==="boolean"&&Number.isSafeInteger(data.capacity)&&data.capacity>=1&&data.capacity<=100;
    intakeVersion=configured?data.version:null;intakeSaved=configured?{paused:data.paused,capacity:data.capacity}:null;
    form.hidden=!configured&&!intakeDraft;
    if(!configured){notice.textContent=data?.requests_open?"Open under existing launch configuration. Artist capacity control is not enabled.":"New commissions are closed or intake control is unavailable.";syncIntakeDraft();return;}
    if(intakeSame(intakeDraft,intakeSaved))intakeDraft=null;
    const shown=intakeDraft||intakeSaved;
    $("intake-mode").value=shown.paused?"paused":"open";
    $("intake-capacity").value=String(shown.capacity);
    const label={open:"Accepting new requests",paused:"Paused by artist",at_capacity:"At capacity",not_available:"Commercial launch gates remain closed"};
    notice.textContent=(label[data.reason]||"Unavailable")+" · "+data.active_requests+" / "+data.capacity+" active requests.";
    syncIntakeDraft();
  }
  $("intake-form").addEventListener("submit",async event=>{
    event.preventDefault();
    if(intakeBusy||intakeVersion===null||!csrf||$("intake-save").disabled||!$("intake-form").reportValidity())return;
    const paused=$("intake-mode").value==="paused",capacity=Number($("intake-capacity").value);
    if(!Number.isSafeInteger(capacity)||capacity<1||capacity>100)return;
    if(!window.confirm("Save these intake settings? Existing orders and commercial launch gates remain unchanged."))return;
    const expected=intakeVersion;
    intakeDraft={paused,capacity:String(capacity),version:expected};intakeBusy=true;++intakeEpoch;syncIntakeDraft();
    try{
      const {response,data}=await post("intake.php",{expected_version:expected,paused,capacity,confirm_change:true},csrf);
      if(leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){
        $("intake-status").textContent=response.status===409?"Settings changed elsewhere. Refresh readiness before retrying.":"Settings not confirmed. Refresh readiness before retrying.";
        intakeVersion=null;return;
      }
      intakeDraft=null;renderIntake(data.intake);
    }catch(_){intakeVersion=null;$("intake-status").textContent="Outcome uncertain. Refresh readiness before retrying.";}
    finally{intakeBusy=false;++intakeEpoch;syncIntakeDraft();}
  });
  let privacyCursor=null,privacyQueueBusy=false,privacyQueueGeneration=0;
  const privacyDrafts=new Map();
  function syncPrivacyDrafts(){
    $("privacy-unsaved").hidden=privacyDrafts.size===0;
    $("privacy-unsaved-count").textContent=privacyDrafts.size+" unsent privacy decision(s) retained in this page. Review the current case and confirm again before saving.";
    $("privacy-discard-drafts").disabled=privacyQueueBusy;
  }
  $("privacy-discard-drafts").addEventListener("click",()=>{
    if(privacyQueueBusy||!privacyDrafts.size||!window.confirm("Discard all unsent privacy replies and outcome selections? No recorded cases change."))return;
    privacyDrafts.clear();syncPrivacyDrafts();loadPrivacyQueue(false);
  });
  function clearPrivacyQueue(){
    ++privacyQueueGeneration;privacyCursor=null;$("privacy-queue").replaceChildren();$("privacy-queue-status").textContent="";$("privacy-queue-next").hidden=true;
  }
  function privacyQueueCard(item){
    const card=document.createElement("article");card.className="notification-card";card.dataset.privacyVersion=String(item.version);
    card.append(textNode("strong","Case #"+item.case_id+" · "+item.kind+" · "+item.status.replaceAll("_"," ")),
      textNode("p",item.display_name+" · "+item.email,"small"),textNode("p",item.note||"No additional details.","small"));
    if(item.reply)card.append(textNode("p","Last reply: "+item.reply,"small"));
    if(item.submitted_at)card.append(textNode("p","Submitted: "+item.submitted_at+" UTC"+(Number.isSafeInteger(item.age_days)?" · "+item.age_days+" full day(s) open":""),"small"));
    if(item.updated_at)card.append(textNode("p","Updated: "+item.updated_at,"small"));
    if(!["submitted","in_review"].includes(item.status))return card;
    const form=document.createElement("form");form.className="intake-form";
    const decision=textNode("label","Record outcome");const select=document.createElement("select");
    if(item.status==="submitted")select.append(new Option("Start review","in_review"));
    select.append(new Option("Record completed response","resolved"),new Option("Record declined request","declined"));decision.append(select);
    const replyLabel=textNode("label","Customer-visible reply");const reply=document.createElement("textarea");reply.maxLength=1000;reply.rows=3;replyLabel.append(reply);
    const ack=document.createElement("label");ack.className="order-date-ack";const check=document.createElement("input");check.type="checkbox";check.required=true;
    ack.append(check,textNode("span","I confirm this records only the actual review or outcome, not an automatic deletion or correction."));
    const submit=textNode("button","Save privacy decision","quiet");submit.type="submit";form.append(decision,replyLabel,ack,submit);
    const key=String(item.case_id),initial=select.value;
    const draft=privacyDrafts.get(key);
    if(draft&&draft.version===item.version){select.value=draft.status;reply.value=draft.reply;}
    else if(draft){
      // Never submit a reply drafted against an older case version.
      card.append(textNode("p","This case changed. An older unsent reply is retained below for review only; discard it before drafting a new decision.","small"),textNode("p",draft.reply||"(No reply text)","small"));
      for(const field of form.querySelectorAll("select,textarea,input,button"))field.disabled=true;
    }
    function remember(){
      if(select.value===initial&&!reply.value.trim())privacyDrafts.delete(key);
      else privacyDrafts.set(key,{version:item.version,status:select.value,reply:reply.value});
      syncPrivacyDrafts();
    }
    select.addEventListener("change",remember);reply.addEventListener("input",remember);
    form.addEventListener("submit",async event=>{
      event.preventDefault();if(privacyQueueBusy||!csrf||!check.checked)return;
      if(select.value!=="in_review"&&reply.value.trim().length<10){$("privacy-queue-status").textContent="Provide a clear explanation of the outcome.";return;}
      if(!window.confirm("Record this customer-visible privacy decision? No data is modified."))return;
      remember();privacyQueueBusy=true;syncPrivacyDrafts();const gen=privacyQueueGeneration;
      $("privacy-queue-refresh").disabled=$("privacy-queue-next").disabled=$("privacy-queue-view").disabled=true;
      for(const field of form.querySelectorAll("input,select,textarea,button"))field.disabled=true;
      try{
        const {response,data}=await post("privacy.php",{customer_id:item.customer_id,expected_version:item.version,status:select.value,reply:reply.value.trim(),confirm_record:true},csrf);
        if(gen!==privacyQueueGeneration||leavingSession)return;
        if(response.status===401)return sessionExpired();
        if(!response.ok||!data.ok){clearPrivacyQueue();$("privacy-queue-status").textContent="Decision not confirmed. Refresh the queue before retrying.";return;}
        privacyDrafts.delete(key);syncPrivacyDrafts();privacyQueueBusy=false;await loadPrivacyQueue(false);
      }catch(_){if(gen===privacyQueueGeneration){clearPrivacyQueue();$("privacy-queue-status").textContent="Outcome uncertain. Refresh the queue before retrying.";}}
      finally{privacyQueueBusy=false;syncPrivacyDrafts();$("privacy-queue-refresh").disabled=$("privacy-queue-next").disabled=$("privacy-queue-view").disabled=false;check.checked=false;for(const field of form.querySelectorAll("input,select,textarea,button"))field.disabled=false;}
    });
    card.append(form);return card;
  }
  async function loadPrivacyQueue(older=false){
    if(privacyQueueBusy||!csrf||(older&&privacyCursor===null))return;
    if(!older)clearPrivacyQueue();
    const gen=++privacyQueueGeneration;privacyQueueBusy=true;syncPrivacyDrafts();
    $("privacy-queue-refresh").disabled=$("privacy-queue-next").disabled=$("privacy-queue-view").disabled=true;
    try{
      const view=$("privacy-queue-view").value;
      const {response,data}=await get("privacy.php?view="+encodeURIComponent(view)+(older?"&before="+encodeURIComponent(privacyCursor):""));
      if(gen!==privacyQueueGeneration||leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||!Array.isArray(data.cases))throw Error("privacy_queue");
      if(!older)$("privacy-queue").replaceChildren();
      for(const item of data.cases){
        if([...$("privacy-queue").children].some(card=>card.dataset.privacyVersion===String(item.version)))continue;
        $("privacy-queue").append(privacyQueueCard(item));
      }
      const previous=privacyCursor;
      privacyCursor=Number.isSafeInteger(data.next_before)&&data.next_before>0&&(!older||data.next_before<previous)?data.next_before:null;
      $("privacy-queue-next").hidden=privacyCursor===null;
      $("privacy-queue-status").textContent=$("privacy-queue").children.length+" "+({open:"open",open_7d:"open (7+ days)",open_30d:"open (30+ days)",closed:"closed"}[view]||"")+" request(s) loaded."+(Number.isSafeInteger(data.matching_count)?" "+data.matching_count+" matching overall.":"");
    }catch(_){if(gen===privacyQueueGeneration){
      if(!older)clearPrivacyQueue();
      $("privacy-queue-status").textContent=older?"Older cases unavailable. Loaded cases and unsent drafts retained; retry.":"Privacy queue unavailable. Unsent drafts retained; refresh to retry.";
    }}
    finally{privacyQueueBusy=false;syncPrivacyDrafts();$("privacy-queue-refresh").disabled=$("privacy-queue-next").disabled=$("privacy-queue-view").disabled=false;}
  }
  $("privacy-queue-view").addEventListener("change",()=>{if(!privacyQueueBusy){clearPrivacyQueue();loadPrivacyQueue(false);}});
  $("privacy-queue-refresh").addEventListener("click",()=>loadPrivacyQueue(false));
  $("privacy-queue-next").addEventListener("click",()=>loadPrivacyQueue(true));
  function openPrivacyFollowup(view){
    if(privacyQueueBusy||leavingSession)return;
    $("privacy-queue-view").value=view;loadPrivacyQueue(false);
    $("privacy-queue-title").focus();$("privacy-queue-title").scrollIntoView({block:"start"});
  }
  document.querySelectorAll("[data-privacy-shortcut]").forEach(button=>button.addEventListener("click",()=>openPrivacyFollowup(button.dataset.privacyShortcut)));
  let overviewGeneration=0,launchEvidence=null;
  function renderLaunchEvidence(report){
    launchEvidence=null;
    const list=$("launch-evidence-stages"),status=$("launch-evidence-status"),button=$("launch-evidence-export");
    list.replaceChildren();button.disabled=true;
    if(report?.scope!=="configuration_only"||report.authorizes_launch!==false||!Array.isArray(report.stages)){
      status.textContent="Configuration report unavailable.";return;
    }
    const clean={generated_at:String(report.generated_at||""),scope:"configuration_only",authorizes_launch:false,
      published_artwork_count:Number.isSafeInteger(report.published_artwork_count)?report.published_artwork_count:null,
      published_package_count:Number.isSafeInteger(report.published_package_count)?report.published_package_count:null,
      intake_open:report.intake_open===true,stages:[]};
    for(const stage of report.stages){
      if(typeof stage.label!=="string"||!Array.isArray(stage.configuration_pending)||!Array.isArray(stage.external_evidence_required))continue;
      const row={key:String(stage.key||""),label:stage.label,configuration_ready:stage.configuration_ready===true,
        configuration_pending:stage.configuration_pending.filter(x=>typeof x==="string"),
        external_evidence_required:stage.external_evidence_required.filter(x=>typeof x==="string")};
      clean.stages.push(row);
      const card=document.createElement("article");card.className="launch-check";
      card.append(textNode("strong",row.label),textNode("p",row.configuration_ready?"Configuration present; external evidence still required":"Configuration pending: "+row.configuration_pending.join(", "),"small"));
      const evidence=document.createElement("ul");
      for(const item of row.external_evidence_required)evidence.append(textNode("li",item,"small"));
      card.append(evidence);list.append(card);
    }
    if(!clean.stages.length){status.textContent="Configuration report unavailable.";return;}
    launchEvidence=clean;
    status.textContent=clean.stages.filter(stage=>stage.configuration_ready).length+" of "+clean.stages.length+" stages configured · "+clean.generated_at+" · configuration only, no launch authorization";
    button.disabled=false;
  }
  $("launch-evidence-export").addEventListener("click",()=>{
    if(!launchEvidence||leavingSession)return;
    const url=URL.createObjectURL(new Blob([JSON.stringify(launchEvidence,null,2)+"\n"],{type:"application/json"}));
    const link=document.createElement("a");link.href=url;link.download="drawmeone-launch-configuration.json";document.body.append(link);link.click();link.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  function renderConfigurationReview(report){
    const list=$("configuration-issues"),status=$("configuration-status");list.replaceChildren();
    if(report?.mode!=="review_only"||report.authorizes_launch!==false||!Array.isArray(report.issues)){status.textContent="Configuration review unavailable.";return;}
    const codes=report.issues.filter(x=>typeof x==="string"&&/^[A-Za-z0-9_:]+$/.test(x)&&x.length<=180).slice(0,200);
    status.textContent=report.issue_count+" warning(s) · "+report.enabled_gate_count+" of "+report.known_gate_count+" switches enabled · no launch authorization.";
    for(const code of codes)list.append(textNode("li",code.replaceAll("_"," ").replaceAll(":"," · "),"small"));
    if(!codes.length)list.append(textNode("li","No warnings reported by this review.","small"));
  }
  function applyOverviewFilter(){
    const hide=$("overview-hide-empty").checked;let hidden=0;
    for(const group of document.querySelectorAll(".overview-group")){
      const cards=[...group.querySelectorAll(".stats article")];
      for(const card of cards){card.hidden=hide&&card.dataset.count==="0";if(card.hidden)hidden++;}
      group.querySelector(".overview-empty").hidden=cards.some(card=>!card.hidden);
    }
    $("overview-counter-status").textContent=hide?hidden+" empty counters hidden. Unknown counts remain visible.":"All counters shown. Counts can overlap.";
  }
  $("overview-hide-empty").addEventListener("change",applyOverviewFilter);
  function resetOverviewReadiness(message){
    renderLaunchEvidence(null);renderConfigurationReview(null);
    for(const name of ["launch","integrity","retention"]){
      $(name+"-checks").replaceChildren();
      $(name+"-status").textContent=message;
    }
    if(!intakeBusy){
      intakeVersion=null;$("intake-form").hidden=!intakeDraft;syncIntakeDraft();
      $("intake-status").textContent="Current intake state not verified. Refresh readiness.";
    }
  }
  async function overview() {
    const generation=++overviewGeneration,intakeAtStart=intakeEpoch;
    resetOverviewReadiness("Refreshing current configuration…");
    for(const card of document.querySelectorAll(".overview-group .stats article")){
      card.dataset.count="unknown";card.querySelector("strong").textContent="—";
    }
    applyOverviewFilter();
    $("overview-counter-status").textContent="Refreshing live counts…";
    let response,data;
    try{({response,data}=await get("overview.php"));}
    catch(_){if(generation===overviewGeneration&&!leavingSession){resetOverviewReadiness("Live readiness unavailable. Refresh to retry.");$("overview-counter-status").textContent="Live counts unavailable. Refresh readiness to retry.";showError("Unable to load live studio counts.");}return;}
    if(generation!==overviewGeneration||leavingSession)return;
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) {
      resetOverviewReadiness("Live readiness unavailable. Refresh to retry.");
      $("overview-counter-status").textContent="Live counts unavailable. Refresh readiness to retry.";
      return showError("Unable to load live studio counts.");
    }
    if(!intakeBusy&&intakeEpoch===intakeAtStart)renderIntake(data.intake);
    renderLaunchEvidence(data.launch_evidence);renderConfigurationReview(data.configuration_review);
    const counters = {
      "stat-requests": data.counts?.requests_for_review,
      "stat-orders": data.counts?.active_orders,
      "stat-due": data.counts?.orders_due_soon,
      "stat-quote-followup": data.counts?.quotes_to_follow_up,
      "stat-payments": data.counts?.payments_to_verify,
      "stat-checkout": data.counts?.checkout_followup,
      "stat-holds": data.counts?.payment_holds,
      "stat-privacy": data.counts?.privacy_open,
      "stat-messages": data.counts?.messages_last_seven_days,
      "stat-reply-due":data.counts?.reply_due,
      "stat-accepted":data.counts?.accepted,
      "stat-quote-expiring":data.counts?.quote_expiring,
      "stat-awaiting-evidence":data.counts?.awaiting_evidence,
      "stat-overdue":data.counts?.overdue,
      "stat-upcoming":data.counts?.upcoming,
      "stat-revision-due":data.counts?.revision_due,
      "stat-proof-waiting":data.counts?.proof_waiting,
      "stat-needs-schedule":data.counts?.needs_schedule,
      "stat-paid-to-start":data.counts?.paid_to_start,
      "stat-ready-to-deliver":data.counts?.ready_to_deliver,
    };
    for (const [name, value] of Object.entries(counters)) {
      const valid=Number.isSafeInteger(value)&&value>=0;
      $(name).textContent=valid?value.toLocaleString():"—";
      $(name).closest("article").dataset.count=valid?String(value):"unknown";
    }
    applyOverviewFilter();
    const retention=$("retention-checks");retention.replaceChildren();
    const review=data.retention;
    const retentionGroups=[
      ["Authentication records",{"expired_sessions":"Expired sessions (all)","expired_tokens":"Expired or used verification/recovery tokens (all)","expired_rate_limits":"Expired rate-limit counters (all)","sessions_expired_30d":"Sessions expired at least 30 days ago","tokens_expired_30d":"Tokens expired or used at least 30 days ago","rate_limits_expired_7d":"Rate-limit counters expired at least 7 days ago"}],
      ["Customer and business records",{"closed_no_order_365d":"Closed requests without an order, last updated 365+ days ago","closed_with_order_365d":"Closed requests with an order, last updated 365+ days ago","closed_reference_files_365d":"Older references on older closed requests","closed_unreleased_drafts_365d":"Older unreleased drafts on older closed requests","released_delivery_records":"Released deliverable records (preserve)","open_payment_holds":"Open payment holds (preserve)"}],
      ["Privacy request follow-up",{"privacy_open_7d":"Open at least 7 days","privacy_open_30d":"Open at least 30 days"}]
    ];
    if(review?.mode==="review_only"&&review.counts&&typeof review.counts==="object"){
      $("retention-status").textContent="Read-only inventory. Counts may overlap; do not add them together.";
      for(const [heading,labels] of retentionGroups){
        const card=document.createElement("article");card.className="launch-check retention-group";card.append(textNode("strong",heading));
        for(const [key,label] of Object.entries(labels)){
          const value=review.counts[key];if(!Number.isSafeInteger(value)||value<0)continue;
          const row=document.createElement("div");row.className="retention-row";row.append(textNode("span",label),textNode("strong",value.toLocaleString()));card.append(row);
          if((key==="privacy_open_7d"||key==="privacy_open_30d")&&value>0){
            const shortcut=textNode("button","Review cases","quiet");shortcut.type="button";
            shortcut.setAttribute("aria-label","Review privacy cases open at least "+(key==="privacy_open_7d"?"7":"30")+" days");
            shortcut.addEventListener("click",()=>openPrivacyFollowup(key==="privacy_open_7d"?"open_7d":"open_30d"));card.append(shortcut);
          }
        }
        retention.append(card);
      }
    }else $("retention-status").textContent="Retention inventory unavailable.";
    const integrity=$("integrity-checks");integrity.replaceChildren();
    const checksMap=data.integrity?.checks;
    const labels={order_quote_mismatch:"Order / accepted quote mismatch",paid_without_evidence:"Paid orders without eligible evidence",paid_order_exception_conflict:"Paid flag conflicts with payment hold or refund",duplicate_verified_payment:"Multiple verified payments per order",multiple_orders:"Multiple orders per request",draft_order_mismatch:"Draft attached to unrelated request",delivery_missing_draft:"Missing or inconsistent delivery record",delivery_state_mismatch:"Released artwork with incomplete order",completed_without_delivery:"Completed order without deliverable",review_draft_mismatch:"Pending or approved proof without matching draft",review_pending_multiple:"Multiple pending proofs",review_allowance_exceeded:"Included revision allowance exceeded",delivery_approval_mismatch:"Released drawing differs from approved proof",checkout_amount_mismatch:"Invoice amount differs from accepted quote",checkout_multiple_unclosed:"Multiple unclosed invoices",checkout_invalid_storage:"Invalid invoice storage state",checkout_active_after_evidence:"Active invoice after payment evidence"};
    if(checksMap&&typeof checksMap==="object"&&Object.keys(checksMap).length){
      $("integrity-status").textContent=data.integrity.issue_count===0?"No database inconsistencies detected.":String(data.integrity.issue_count)+" failed checks require review.";
      for(const [key,value] of Object.entries(checksMap)){
        if(!Number.isSafeInteger(value)||value<0)continue;
        const card=document.createElement("article");card.className="launch-check";
        card.append(textNode("strong",labels[key]||key.replaceAll("_"," ")),textNode("p",value===0?"No issues":value+" affected record(s)","small"));
        integrity.append(card);
      }
    }else $("integrity-status").textContent="Database consistency results unavailable.";
    const list = $("launch-checks");
    list.replaceChildren();
    const checks = Array.isArray(data.readiness) ? data.readiness : [];
    const totals = {ready:0,blocked:0,planned:0};
    for (const check of checks) {
      const status = ["ready","blocked","planned"].includes(check.status)
        ? check.status : "blocked";
      totals[status]++;
      const card = document.createElement("article");
      card.className = "launch-check";
      const top = document.createElement("div");
      top.className = "launch-check-head";
      const name = document.createElement("strong");
      name.textContent = String(check.label || "Requirement");
      const chip = document.createElement("span");
      chip.className = "launch-check-status";
      chip.dataset.state = status;
      chip.textContent = status === "ready" ? "Configured" :
        status === "planned" ? "Planned" : "Needs attention";
      top.append(name,chip);
      const detail = document.createElement("p");
      detail.className = "small";
      detail.textContent = String(check.detail || "");
      card.append(top,detail);
      list.append(card);
    }
    $("launch-status").textContent = checks.length
      ? totals.ready + " of " + checks.length +
        " checks configured · " + totals.blocked + " need attention · " +
        totals.planned + " pending implementation"
      : "No readiness information is currently available.";
  }

  $("launch-refresh").addEventListener("click", () => {
    $("launch-status").textContent = "Refreshing live launch requirements…";
    overview().catch(() => {
      $("launch-status").textContent = "Unable to refresh. Please try again.";
    });
  });

  let requestCustomerId=null;
  function setRequestCustomer(id=null){
    if(requestBusy&&!leavingSession)return false;
    if((id!==requestCustomerId||id!==null)&&!closeRequestDetail())return false;
    requestCustomerId=id;$("request-customer-scope").hidden=id===null;$("request-customer-label").textContent=id===null?"":"Commission history for customer #"+id;return true;
  }
  $("request-customer-clear").addEventListener("click",()=>{if(!setRequestCustomer())return;requestListPage=1;requests();});
  let requestListPage = 1;
  let requestListQuery = "";
  let requestListFilter = "all";
  let requestListScope="identity",requestListSort="queue";
  function resetRequestSearchOptions(){requestListScope="identity";requestListSort="queue";$("request-list-scope").value="identity";$("request-list-sort").value="queue";}
  function requestOptionsApplied(){return $("request-list-query").value.trim()===requestListQuery&&$("request-list-filter").value===requestListFilter&&$("request-list-scope").value===requestListScope&&$("request-list-sort").value===requestListSort&&$("request-schedule-from").value===requestScheduleFrom&&$("request-schedule-to").value===requestScheduleTo;}
  let requestListGeneration = 0;
  let requestScheduleFrom="",requestScheduleTo="";
  function isScheduleFilter(value){return ["scheduled","requested_dates"].includes(value);}
  let agendaExportBusy=false;
  function syncAgendaExport(){
    const button=$("request-agenda-export");
    button.hidden=requestListFilter!=="scheduled";
    button.disabled=agendaExportBusy||requestListFilter!=="scheduled"||!requestScheduleFrom||!requestScheduleTo||requestScheduleFrom>requestScheduleTo||!requestOptionsApplied();
  }
  function syncScheduleFilter(reset=false){
    const visible=isScheduleFilter($("request-list-filter").value);
    $("request-schedule-range").hidden=!visible;
    if(reset||!visible){$("request-schedule-from").value="";$("request-schedule-to").value="";if(reset)requestScheduleFrom=requestScheduleTo="";}
    $("request-list-sort").disabled=visible;
    syncAgendaExport();
  }
  $("request-list-filter").addEventListener("change",()=>syncScheduleFilter());
  function pendingRequestFilters(){
    syncAgendaExport();
    if(!requestOptionsApplied()){
      ++requestListGeneration;$("request-list").replaceChildren();
      $("request-list-previous").disabled=$("request-list-next").disabled=true;
      $("request-list-status").textContent="Filters changed. Apply filters to load matching requests.";
    }
  }
  $("request-list-filters").addEventListener("input",pendingRequestFilters);
  $("request-list-filters").addEventListener("change",pendingRequestFilters);

  function workflowLabel(stage) {
    const labels={submitted:"Awaiting review",clarification_needed:"Clarification needed",
      quote_available:"Awaiting quote decision",quote_accepted:"Quote accepted",quote_expired:"Quote expired",
      quote_declined:"Quote declined",quote_inactive:"Quote no longer available",payment_on_hold:"Payment under review",payment_refunded:"External refund recorded",awaiting_arrangement:"Awaiting payment arrangements",
      in_progress:"Artwork in progress",queued:"Queued",drawing:"Drawing in progress",
      awaiting_feedback:"Awaiting feedback",revision:"Revisions in progress",
      ready_for_delivery:"Ready for delivery",completed:"Completed",rejected:"Declined",cancelled:"Cancelled"};
    return labels[stage] || String(stage||"Under review").replaceAll("_"," ");
  }

  async function requests() {
    if(!requestOptionsApplied()){pendingRequestFilters();return;}
    const generation = ++requestListGeneration;
    const list = $("request-list");
    const status = $("request-list-status");
    const previous = $("request-list-previous");
    const next = $("request-list-next");
    previous.disabled = next.disabled = true;
    status.textContent = "Loading requests…";
    syncAgendaExport();
    list.replaceChildren();
    let response, data;
    try {
      const query = new URLSearchParams({page:String(requestListPage),
        q:requestListQuery,filter:requestListFilter});
      if(requestCustomerId!==null)query.set("customer_id",String(requestCustomerId));
      if(requestListScope!=="identity")query.set("scope",requestListScope);
      if(requestListSort!=="queue")query.set("sort",requestListSort);
      if(isScheduleFilter(requestListFilter)){
        if(requestScheduleFrom)query.set("from_date",requestScheduleFrom);
        if(requestScheduleTo)query.set("to_date",requestScheduleTo);
      }
      ({response,data} = await get("requests.php?" + query));
    } catch (_) {
      if (generation === requestListGeneration){status.textContent = "Unable to load requests. Try Refresh.";previous.disabled=requestListPage===1;}
      return;
    }
    if (generation !== requestListGeneration) return;
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok || !Array.isArray(data.requests)) {
      status.textContent = "Unable to load requests. Try Refresh.";
      previous.disabled = requestListPage === 1;
      return;
    }
    previous.disabled = requestListPage === 1;
    next.disabled = data.has_more !== true;
    status.textContent = "Page " + requestListPage + " · " + data.requests.length + " request(s)"+(Number.isSafeInteger(data.matching_count)?" · "+data.matching_count+" matching overall":"");
    if(data.requests.length===0&&requestListPage>1)status.textContent+=" · Return to an earlier page.";
    if (data.requests.length === 0) {
      empty(list, requestListQuery || requestListFilter !== "all"
        ? "No requests match your search." : "No commission requests on this page.");
      return;
    }
    let lastDate=null;
    for (const row of data.requests) {
      if(isScheduleFilter(requestListFilter)){
        const date=requestListFilter==="requested_dates"?String(row.preferred_deadline||"").slice(0,10):row.order_deadline;
        if(date!==lastDate){list.append(textNode("h3",date||"Date unavailable","request-agenda-date"));lastDate=date;}
      }
      record(list,
        (row.public_ref || "REQUEST") + " · " + workflowLabel(row.workflow_stage || row.status),
        row.customer_name || "Customer",
        [row.tier_name || "Custom", row.subject_type || "", row.order_deadline ? "Agreed date: " + row.order_deadline : row.preferred_deadline ? "Requested date (not agreed): "+String(row.preferred_deadline).slice(0,10) : "", row.quote_expires_at ? (row.workflow_stage==="quote_expired"?"Offer expired: ":"Offer expires: ")+row.quote_expires_at+" UTC" : "", row.brief_excerpt || ""]
          .filter(Boolean).join(" · "),
        row.submitted_at || ""
      );
      const open = document.createElement("button");
      open.type = "button";
      open.className = "quiet request-open";
      open.textContent = "View brief & drafts";
      open.addEventListener("click", () => openRequest(row.id,true).catch(() => {
        showError("Unable to open commission request.");
      }));
      list.lastElementChild?.appendChild(open);
    }
  }

  document.querySelectorAll("[data-request-queue]").forEach(button=>button.addEventListener("click",()=>{
    requestListPage=1;
    requestListQuery="";
    if(!setRequestCustomer())return;
    requestListFilter=button.dataset.requestQueue;
    $("request-list-query").value="";
    $("request-list-filter").value=requestListFilter;
    resetRequestSearchOptions();syncScheduleFilter(true);
    selectPanel("requests");
  }));
  $("request-list-filters").addEventListener("submit", event => {
    event.preventDefault();
    const nextFilter=$("request-list-filter").value;
    const from=isScheduleFilter(nextFilter)?$("request-schedule-from").value:"",to=isScheduleFilter(nextFilter)?$("request-schedule-to").value:"";
    if(from&&to&&from>to){pendingRequestFilters();$("request-list-status").textContent="From date must not be after through date.";return;}
    requestListPage=1;requestListQuery=$("request-list-query").value.trim();requestListScope=$("request-list-scope").value;requestListSort=$("request-list-sort").value;
    requestListFilter=nextFilter;requestScheduleFrom=from;requestScheduleTo=to;
    requests();
  });
  $("request-list-previous").addEventListener("click", () => {
    if (requestListPage > 1) { requestListPage--; requests(); }
  });
  $("request-list-next").addEventListener("click", () => {
    if($("request-list-next").disabled||!requestOptionsApplied())return;
    requestListPage++; requests();
  });
  $("request-list-refresh").addEventListener("click", () => requests());
  $("request-agenda-export").addEventListener("click",async()=>{
    if($("request-agenda-export").disabled||leavingSession)return;
    if(!window.confirm("Download agreed dates and commission references for the applied search and date range? This file can contain private business information. Import only into a trusted calendar; future changes are not synchronized."))return;
    const query=new URLSearchParams({filter:"scheduled",format:"calendar",q:requestListQuery,from_date:requestScheduleFrom,to_date:requestScheduleTo});
    if(requestCustomerId!==null)query.set("customer_id",String(requestCustomerId));
    if(requestListScope!=="identity")query.set("scope",requestListScope);
    agendaExportBusy=true;syncAgendaExport();
    try{
      const response=await fetch("../api/admin/requests.php?"+query,{cache:"no-store",credentials:"same-origin"});
      if(response.status===401)return sessionExpired();
      if(!response.ok||!response.headers.get("content-type")?.includes("text/calendar"))throw Error("Calendar unavailable");
      const file=await response.blob();if(leavingSession)return;
      const url=URL.createObjectURL(file),link=document.createElement("a");link.href=url;link.download="drawmeone-delivery-agenda.ics";document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
      $("request-list-status").textContent="Calendar file prepared for the applied date range. Not synchronized.";
    }catch(_){$("request-list-status").textContent="Calendar export unavailable. Apply a date range of at most one year, with no more than 500 orders.";}finally{agendaExportBusy=false;syncAgendaExport();}
  });

  let messagePage=1, messageGeneration=0,messageQuery="";
  function messageSearchApplied(){return $("message-list-query").value.trim()===messageQuery;}
  function invalidateMessageSearch(){
    if(messageSearchApplied())return;
    ++messageGeneration;$("message-list").replaceChildren();
    $("message-list-previous").disabled=$("message-list-next").disabled=true;
    $("message-list-status").textContent="Search changed. Apply search to load matching messages.";
  }
  async function messages() {
    if(!messageSearchApplied()){invalidateMessageSearch();return;}
    const generation=++messageGeneration;
    const list=$("message-list"), status=$("message-list-status");
    const previous=$("message-list-previous"),next=$("message-list-next");
    previous.disabled=next.disabled=true;
    status.textContent="Loading messages…";
    list.replaceChildren();
    try {
      const query=new URLSearchParams({page:String(messagePage),filter:$("message-list-filter").value,q:messageQuery});
      const {response,data}=await get("messages.php?"+query);
      if(generation!==messageGeneration)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||!Array.isArray(data.messages))throw Error("unavailable");
      previous.disabled=messagePage===1;
      next.disabled=data.has_more!==true;
      status.textContent="Page "+messagePage+" · "+data.messages.length+($("message-list-filter").value==="reply_due"?" conversation(s) awaiting reply":" message(s)")+(Number.isSafeInteger(data.matching_count)?" · "+data.matching_count+" matching overall":"");
      if(!data.messages.length){empty(list,"No messages match this view.");return;}
    for (const row of data.messages) {
      record(list,
        (row.request_ref || "MESSAGE") +
          (Number(row.is_internal) === 1 ? " · INTERNAL NOTE" : row.sender_role === "customer" ? " · CUSTOMER" : " · ARTIST REPLY"),
        row.sender_name || "Sender",
        row.body_excerpt || "",
        row.created_at || ""
      );
      if (Number.isSafeInteger(Number(row.request_id)) && Number(row.request_id) > 0) {
        const open = document.createElement("button");
        open.className = "quiet request-open";
        open.type = "button";
        open.textContent = "View request & thread";
        open.addEventListener("click", () => {
          selectPanel("requests");
          openRequest(row.request_id,true).catch(() => showError("Request unavailable."));
        });
        list.lastElementChild?.appendChild(open);
      }
    }
    }catch(_){
      if(generation===messageGeneration){
        status.textContent="Unable to load messages. Try Refresh.";
        previous.disabled=messagePage===1;
      }
    }
  }
  $("message-list-filter").addEventListener("change",()=>{messagePage=1;messages();});
  $("message-list-query").addEventListener("input",invalidateMessageSearch);
  function applyMessageSearch(){messageQuery=$("message-list-query").value.trim();messagePage=1;messages();}
  $("message-list-search").addEventListener("click",applyMessageSearch);
  $("message-list-query").addEventListener("keydown",event=>{if(event.key==="Enter"){event.preventDefault();applyMessageSearch();}});
  $("message-list-clear").addEventListener("click",()=>{$("message-list-query").value="";applyMessageSearch();});
  $("message-list-refresh").addEventListener("click",()=>messages());
  $("recent-messages-shortcut").addEventListener("click",()=>{
    $("message-list-filter").value="recent";$("message-list-query").value="";messageQuery="";messagePage=1;selectPanel("messages");
    $("message-list-filter").focus();
  });
  $("message-list-previous").addEventListener("click",()=>{if(messagePage>1){messagePage--;messages();}});
  $("message-list-next").addEventListener("click",()=>{messagePage++;messages();});

  // Admin-only commission review. All user-controlled content is inserted with
  // textContent, never innerHTML. No public messages or quote sending here.
  let selectedRequestId = null;
  let requestLoadGeneration = 0;
  let requestWriteEnabled = false;
  let requestMessagingEnabled = false;
  let quoteDeliveryEnabled = false;
  let orderCreationEnabled = false;
  let requestBusy = false;
  let requestFullThread = false;
  let reviewStatus = null;
  let quoteDraftAllowed = false;
  const pendingAdminMessages=new Map();
  // Never restore financial acknowledgements or stale eligibility after a
  // server refresh. Detect edited transient fields before manual navigation.
  const transientRequestBaseline=new Map();
  const transientRequestSelector="#request-review-status,#request-orders .order-date-form input,#request-payment-records form input,#request-payment-records form select";
  const transientFieldValue=field=>field.type==="checkbox"?field.checked:field.value;
  function captureTransientRequestFields(){
    transientRequestBaseline.clear();
    for(const field of document.querySelectorAll(transientRequestSelector)){
      transientRequestBaseline.set(field,transientFieldValue(field));
    }
  }
  function hasTransientRequestEdits(){
    return [...transientRequestBaseline].some(([field,initial])=>field.isConnected&&transientFieldValue(field)!==initial);
  }
  function confirmTransientRefresh(){
    return !hasTransientRequestEdits()||window.confirm("Refreshing will discard unsaved order, payment or review fields and reset their acknowledgements. Notes, messages, quote drafts and retained artwork remain in this request. Refresh anyway?");
  }
  function confirmChangedAdminOperation(action,id,body){
    const prior=pendingAdminMessages.get(action);
    return !prior||prior.id!==id||prior.body===body||
      window.confirm("The previous save may already have succeeded. Changed content creates a separate record. Continue, or cancel and restore the original content before retrying?");
  }
  function adminMessageKey(action,id,body){
    const prior=pendingAdminMessages.get(action);
    if(prior&&prior.id===id&&prior.body===body)return prior.key;
    const key=crypto.randomUUID();pendingAdminMessages.set(action,{id,body,key});return key;
  }

  function requestText(id, value) {
    $(id).textContent = value == null || value === "" ? "—" : String(value);
  }

  function formatQuoteMoney(minor, currency) {
    if (!Number.isSafeInteger(Number(minor)) || Number(minor) < 0) return "—";
    const code = currency === "USD" ? "USD" : "CAD";
    return new Intl.NumberFormat("en-CA", {
      style:"currency", currency:code,
    }).format(Number(minor) / 100);
  }

  function textNode(tag, value, className = "") {
    const node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = String(value == null ? "" : value);
    return node;
  }

  function renderArtistRequestSummary(data){
    const item=data.detail||{},info=item.request||{};
    const quotes=Array.isArray(item.quotes)?item.quotes:[],orders=Array.isArray(item.orders)?item.orders:[],order=orders[0];
    const accepted=quotes.find(q=>q.status==="accepted"),sent=quotes.find(q=>q.status==="sent"&&!q.is_expired),draft=quotes.find(q=>q.status==="draft");
    const review=(Array.isArray(data.artwork_reviews)?data.artwork_reviews:[]).find(r=>r.order_id===order?.id)?.latest;
    const closed=["cancelled","canceled","rejected","declined","completed","closed","archived","refunded"].includes(info.status)||["completed","cancelled"].includes(order?.status);
    const held=order?.payment_status==="on_hold",refunded=order?.payment_status==="refunded";
    const payment=({paid:"Verified",unverified:"Not verified",on_hold:"Under review",refunded:"External refund recorded"})[order?.payment_status]||"Not verified";
    const milestones=$("request-milestones"),links=$("request-section-links");
    milestones.replaceChildren();links.replaceChildren();
    const entries=[["Request",workflowLabel(info.status)],["Quote",accepted?"Accepted":sent?"Awaiting decision":draft?"Private draft":quotes.length?"Historical":"Not created"],
      ["Order",order?workflowLabel(order.status):"Not confirmed"],["Payment",order?payment:"No order"],
      ["Proof",held||refunded?"Access paused":review?({pending:"Awaiting customer",revision_requested:"Revision requested",approved:"Approved"}[review.status]||"Recorded"):"Not offered"],
      ["Agreed date",order?.agreed_deadline||"Not agreed"]];
    for(const [label,value] of entries){const group=document.createElement("div");group.append(textNode("dt",label),textNode("dd",value));milestones.append(group);}
    let next="Review the brief and conversation before preparing a quote.",first="request-brief-section";
    if(refunded||held){next=refunded?"An external refund is recorded. Review the financial history.":"Payment is held; artwork access must remain paused until resolved.";first="request-payment-section";}
    else if(closed){next="This commission is closed or completed. Review its recorded history.";first="request-activity-panel";}
    else if(order&&order.payment_status!=="paid"){next="Review payment arrangements and verification. Do not start production on an unverified payment.";first="request-payment-section";}
    else if(order&&review?.status==="pending"){next="Awaiting the customer's proof decision.";first="request-artwork-section";}
    else if(order&&review?.status==="revision_requested"){next="Review the requested changes before staging a revised proof.";first="request-artwork-section";}
    else if(order){next=order.status==="ready_for_delivery"?"Review final release eligibility and the exact approved artwork.":"Review current production and private artwork staging.";first="request-artwork-section";}
    else if(accepted){next="The customer accepted a quote. Review the separate order confirmation.";first="request-order-section";}
    else if(sent){next="A quote is awaiting the customer's decision.";first="request-quote-section";}
    else if(draft){next="Review the private quote draft before any separate release.";first="request-quote-section";}
    else if(info.status==="clarification_needed"){next="Review correspondence for outstanding clarification.";first="request-conversation-section";}
    $("request-next-step").textContent=next;
    const sections=[["Brief","request-brief-section"],["References","request-reference-section"],["Review decision","request-review-actions"],["Conversation","request-conversation-section"],["Quotes","request-quote-section"],["Order","request-order-section"],["Payments","request-payment-section"],["Artwork / proofs","request-artwork-section"],["Email notices","request-notice-section"],["Activity","request-activity-panel"]];
    const jump=(label,id)=>{const target=$(id);if(!target||target.hidden)return;const button=textNode("button",label,"quiet");button.type="button";button.addEventListener("click",()=>{if(target.tagName==="DETAILS")target.open=true;target.focus({preventScroll:true});target.scrollIntoView({behavior:"instant",block:"start"});});links.append(button);};
    const primary=sections.find(([,id])=>id===first);
    if(primary)jump("Go to "+primary[0].toLowerCase(),primary[1]);
    for(const [label,id] of sections)if(id!==first)jump(label,id);
    $("request-at-a-glance").hidden=false;
  }

  function renderThread(records) {
    const root = $("request-thread");
    root.replaceChildren();
    if (!Array.isArray(records) || records.length === 0) {
      root.append(textNode("p", "No notes or messages in this request yet.", "small"));
      return;
    }
    for (const record of records) {
      const entry = document.createElement("article");
      entry.className = "request-thread-item";
      const mark = record.is_internal
        ? "PRIVATE ARTIST NOTE" :
        (record.sender_role === "admin" ? "ARTIST REPLY" : "CUSTOMER MESSAGE");
      entry.append(
        textNode("small", mark + " · " + (record.sender_name || "User") +
          " · " + (record.created_at || "")),
        textNode("p", record.body || "")
      );
      root.appendChild(entry);
    }
  }

  function renderRequestReferences(records) {
    const root = $("request-references");
    root.replaceChildren();
    if (!Array.isArray(records) || !records.length) {
      root.append(textNode("p", "No reference images attached.", "small"));
      return;
    }
    let number=0;
    for (const item of records) {
      if (!Number.isSafeInteger(item.id) || item.id < 1) continue;
      number++;
      const card=document.createElement("div");card.className="private-reference-card";
      const frame = document.createElement("a");
      frame.href = "../api/reference.php?id=" + item.id + "&variant=display";
      frame.target = "_blank";
      frame.rel = "noopener noreferrer";
      frame.setAttribute("aria-label", "View customer reference "+number+" privately (opens in a new tab)");
      const img = document.createElement("img");
      img.alt = "Private customer reference "+number;
      img.loading = "lazy";img.decoding="async";
      img.addEventListener("error",()=>frame.replaceChildren(textNode("span","Preview unavailable · open full image","small")),{once:true});
      frame.append(img);
      img.src = "../api/reference.php?id=" + item.id + "&variant=thumb";
      const dimensions=Number.isSafeInteger(item.width)&&item.width>0&&Number.isSafeInteger(item.height)&&item.height>0?" · "+item.width+" × "+item.height+" px":"";
      card.append(frame,textNode("small","Reference "+number+dimensions));
      root.append(card);
    }
  }

  let activityBefore=null,activityBusy=false;
  function syncActivityControls(){
    $("request-activity-older").hidden=!activityBefore;$("request-activity-older").disabled=activityBusy;
  }
  function renderRequestActivity(activity,append=false){
    const root=$("request-activity-list");if(!append)root.replaceChildren();
    activityBefore=activity?.has_older===true&&typeof activity.next_before==="string"&&/^[1-9][0-9]{0,17}$/.test(activity.next_before)?activity.next_before:null;
    $("request-activity-limit").hidden=activity?.has_older!==true;syncActivityControls();
    if(!Array.isArray(activity?.events)||!activity.events.length){
      if(!append)root.append(textNode("li","No recorded workflow events.","small"));return;
    }
    for(const event of activity.events){
      if(typeof event.label!=="string"||typeof event.created_at!=="string")continue;
      const entry=document.createElement("li");
      entry.append(textNode("strong",event.label),textNode("small",event.created_at+" UTC"));
      root.append(entry);
    }
  }
  $("request-activity-older").addEventListener("click",async()=>{
    if(activityBusy||!activityBefore||selectedRequestId===null||leavingSession)return;
    const generation=requestLoadGeneration,id=selectedRequestId,cursor=activityBefore;
    activityBusy=true;syncActivityControls();$("request-activity-feedback").textContent="Loading older events…";
    try{
      const {response,data}=await get("request.php?id="+id+"&activity_before="+encodeURIComponent(cursor));
      if(generation!==requestLoadGeneration||id!==selectedRequestId||leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||!Array.isArray(data.activity?.events)||data.activity.next_before===cursor)throw Error("Activity unavailable");
      renderRequestActivity(data.activity,true);$("request-activity-feedback").textContent="Older events loaded.";
    }catch(_){if(generation===requestLoadGeneration)$("request-activity-feedback").textContent="Could not load older activity. Try again.";}
    finally{if(generation===requestLoadGeneration){activityBusy=false;syncActivityControls();}}
  });
  function renderQuotes(records) {
    const root = $("request-quotes");
    root.replaceChildren();
    if (!Array.isArray(records) || records.length === 0) {
      root.append(textNode("p", "No quotes prepared yet.", "small"));
      return;
    }
    const newestVersion = Math.max(0, ...records.map(q => Number(q.version_number) || 0));
    const alreadyAccepted = records.some(q => q.status === "accepted");
    for (const quote of records) {
      const card = document.createElement("article");
      card.className = "request-quote-card";
      card.append(
        textNode("strong", "Version " + quote.version_number +
          " · " + (quote.status === "sent" && quote.is_expired ? "Expired" : quote.status === "superseded" ? "No longer available" : quote.status || "draft") +
          " · " + formatQuoteMoney(quote.total_minor,quote.currency)),
        textNode("p", quote.scope_description || ""),
        textNode("small", "Revisions included: " + quote.revision_allowance +
          " · Created: " + (quote.created_at || ""))
      );
      if(quote.sent_at)card.append(textNode("small","Released: "+quote.sent_at+" UTC · Valid until: "+(quote.validity_ends_at||"Not specified")+" UTC"));
      if (quoteDraftAllowed && !alreadyAccepted && ["draft","sent","declined","superseded"].includes(quote.status)) {
        const copy = document.createElement("button");
        copy.type = "button";
        copy.className = "quiet request-open";
        copy.textContent = "Use as starting point for new version";
        copy.addEventListener("click", () => {
          if (!quoteDraftAllowed || requestBusy) return;
          if(quoteHasDraft()&&!window.confirm("Replace the unsaved quote with this saved version?"))return;
          $("quote-currency").value = quote.currency === "USD" ? "USD" : "CAD";
          $("quote-scope").value = quote.scope_description || "";
          $("quote-revisions").value = String(quote.revision_allowance);
          $("quote-lines").replaceChildren();
          for (const line of quote.lines || []) {
            addQuoteLine(line.description || "", moneyText(line.amount_minor));
          }
          if (!quote.lines?.length) addQuoteLine();
          updateQuotePreview();
        });
        card.append(copy);
      }
      if (quote.status === "draft" && quoteDeliveryEnabled &&
          quote.version_number === newestVersion && !alreadyAccepted) {
        const actions = document.createElement("div");
        actions.className = "quote-publish-actions";
        const label = textNode("label", "Customer review window");
        const days = document.createElement("select");
        days.setAttribute("aria-label", "Quote validity in days");
        for (const n of [3,7,14,30]) days.append(new Option(n + " days", String(n)));
        days.value = "7";
        const publish = document.createElement("button");
        publish.type = "button";
        publish.className = "quiet";
        publish.textContent = "Release to customer's account";
        publish.addEventListener("click", () => releaseQuote(quote.id, Number(days.value)));
        label.append(days);
        actions.append(label,publish);
        card.append(actions);
      }
      if(quote.status==="sent" && quoteDeliveryEnabled && !alreadyAccepted){
        const withdraw=textNode("button","Withdraw offer","quiet");
        withdraw.type="button";
        withdraw.dataset.quoteWithdraw="true";
        withdraw.addEventListener("click",()=>withdrawQuote(Number(quote.id)));
        card.append(withdraw);
      }
      if (Array.isArray(quote.lines)) {
        const list = document.createElement("ul");
        for (const line of quote.lines) {
          list.append(textNode("li", (line.description || "") + " — " +
            formatQuoteMoney(line.amount_minor,quote.currency)));
        }
        card.append(list);
      }
      root.append(card);
    }
  }


  async function withdrawQuote(quoteId) {
    if(!quoteDeliveryEnabled||requestBusy||selectedRequestId===null||!Number.isSafeInteger(quoteId))return;
    if(!window.confirm("Withdraw this offer? The customer will no longer be able to accept it. Its history is preserved."))return;
    const id=selectedRequestId;
    requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{action:"withdraw_quote",request_id:id,quote_id:quoteId,confirm_withdrawal:true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){
        requestText("request-detail-status","Offer not withdrawn. Refresh before retrying.");
        return;
      }
      await openRequest(id);
      await requests();
      requestText("request-detail-status","Offer withdrawn; history retained. No payment or email sent.");
    }catch(_){requestText("request-detail-status","Withdrawal not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }

  async function releaseQuote(quoteId, validityDays) {
    const requestId = selectedRequestId;
    if (!quoteDeliveryEnabled || requestBusy || requestId === null ||
        !Number.isSafeInteger(Number(quoteId)) || Number(quoteId) < 1 ||
        !Number.isInteger(validityDays) || validityDays < 1 || validityDays > 30) return;
    if (!window.confirm("Release this exact draft quote to the customer's private account for " +
        validityDays + " days? This does NOT send email or collect payment.")) return;
    requestBusy = true;
    updateRequestButtons();
    requestText("request-detail-status", "Publishing reviewed quote privately…");
    try {
      const {response, data} = await post("quote-send.php", {
        request_id:requestId, quote_id:Number(quoteId), validity_days:validityDays,
      }, csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status",
          "Quote not released (" + (data.error || response.status) + ").");
        return;
      }
      await openRequest(requestId);
      requestText("request-detail-status",
        "Quote v" + data.version + " is visible in the customer's private account. No email or payment.");
    } catch (_) {
      requestText("request-detail-status",
        "Could not verify quote release. Refresh the request before trying again.");
    } finally {
      requestBusy = false;
      updateRequestButtons();
    }
  }


  function renderAdminOrders(orders, quotes) {
    const root = $("request-orders");
    root.replaceChildren();
    const entries = Array.isArray(orders) ? orders : [];
    if (entries.length) {
      for (const order of entries) {
        const card = document.createElement("article");
        card.className = "request-order-card";
        card.append(
          textNode("strong", "Order #" + order.id + " · " + workflowLabel(order.status)),
          textNode("p", "Accepted quote #" + order.quote_id +
            " · " + formatQuoteMoney(order.total_minor, order.currency)),
          textNode("small", "Payment: " + (order.payment_status || "unverified") +
            " · Created: " + (order.created_at || ""))
        );
        card.append(textNode("p","Agreed delivery date: "+(order.agreed_deadline||"Not agreed yet"),"small"));
        if(order.deadline_editable===true){
          const form=document.createElement("form");
          form.className="order-date-form";
          const label=textNode("label","Agreed delivery date (optional)");
          const input=document.createElement("input");
          input.type="date";
          input.value=order.agreed_deadline||"";
          input.setAttribute("aria-label","Agreed delivery date for order "+order.id);
          label.append(input);
          const acknowledgement=document.createElement("label");
          acknowledgement.className="order-date-ack";
          const check=document.createElement("input");
          check.type="checkbox";check.required=true;
          acknowledgement.append(check,textNode("span","The customer and I agreed to this date or to remove it."));
          const save=textNode("button","Save agreed date","quiet");
          save.type="submit";
          save.disabled=requestBusy;
          form.append(label,acknowledgement,save);
          form.addEventListener("submit",event=>{
            event.preventDefault();
            if(!check.checked||requestBusy)return;
            saveOrderDate(order,input.value||null);
          });
          card.append(form);
        }
        root.append(card);
      }
      return;
    }
    const accepted = (Array.isArray(quotes) ? quotes : [])
      .find(q => q.status === "accepted" && Number.isSafeInteger(Number(q.id)));
    if (!accepted) {
      root.append(textNode("p",
        "No confirmed order. The customer must first accept a reviewed quote.", "small"));
      return;
    }
    root.append(textNode("p",
      "Accepted quote #" + accepted.id + " · " +
      formatQuoteMoney(accepted.total_minor, accepted.currency), "small"));
    if (!orderCreationEnabled) {
      root.append(textNode("p",
        "Unpaid order confirmation is disabled until business terms are approved.", "small"));
      return;
    }
    const button = document.createElement("button");
    button.type = "button";
    button.className = "quiet";
    button.textContent = "Confirm order (payment unverified)";
    button.disabled = requestBusy;
    button.addEventListener("click", () => confirmUnpaidOrder(Number(accepted.id)));
    root.append(button);
  }

  async function saveOrderDate(order,deadline) {
    if(requestBusy||selectedRequestId===null||order.deadline_editable!==true)return;
    const id=selectedRequestId;
    requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{
        action:"set_order_deadline",request_id:id,order_id:Number(order.id),
        expected_deadline:order.agreed_deadline||null,agreed_deadline:deadline,confirm_agreement:true
      },csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){
        requestText("request-detail-status","Agreed date not saved ("+(data.error||response.status)+"). Refresh before retrying.");
        return;
      }
      await openRequest(id);
      await requests();
      requestText("request-detail-status","Agreed delivery date saved. Payment remains unverified.");
    }catch(_){requestText("request-detail-status","Date save not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }

  async function confirmUnpaidOrder(quoteId) {
    const requestId = selectedRequestId;
    if (!orderCreationEnabled || requestBusy || requestId === null ||
        !Number.isSafeInteger(quoteId) || quoteId < 1) return;
    if (!window.confirm(
      "Confirm this accepted quote as an order awaiting payment arrangements? " +
      "No payment will be processed or marked as received."
    )) return;
    requestBusy = true;
    updateRequestButtons();
    requestText("request-detail-status", "Recording unverified order…");
    try {
      const {response, data} = await post("order-create.php", {
        request_id:requestId,accepted_quote_id:quoteId,confirm_unpaid:true,
      },csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status",
          "Order not confirmed (" + (data.error || response.status) + ").");
        return;
      }
      await openRequest(requestId);
      requestText("request-detail-status",
        "Order #" + data.order_id +
        " confirmed as awaiting arrangements. Payment remains unverified.");
    } catch (_) {
      requestText("request-detail-status",
        "Connection interrupted. Refresh the request before attempting confirmation again.");
    } finally {
      requestBusy = false;
      updateRequestButtons();
    }
  }

  function renderPaymentRecords(orders,records,enabled,exceptionsEnabled=false,checkoutLinks=[]) {
    const root=$("request-payment-records");root.replaceChildren();
    if(!enabled)root.append(textNode("p","Manual reconciliation is disabled until approved.","small"));
    for(const order of orders||[]){
      const rows=(records||[]).filter(p=>p.order_id===order.id);
      const card=document.createElement("article");card.className="notification-card";
      card.append(textNode("strong","Order #"+order.id+" · "+formatQuoteMoney(order.total_minor,order.currency)));
      for(const p of rows)card.append(textNode("p","Payment #"+p.id+" · "+p.method+" · "+formatQuoteMoney(p.amount_minor,p.currency)+" · "+p.status,"small"));
      const checkout=checkoutLinks.find(x=>x.order_id===order.id);
      if(checkout&&(checkout.latest||checkout.can_issue)){
        const section=document.createElement("section");section.className="checkout-admin";
        section.append(textNode("strong","External hosted invoice"));
        const latest=checkout.latest;
        if(latest) section.append(textNode("p","Invoice #"+latest.id+" · "+latest.status+(latest.status==="active"&&!latest.fresh?" (local access expired)":"")+" · "+latest.provider_host,"small"));
        if(checkout.can_issue){
          const form=document.createElement("form");form.className="payment-evidence-form";
          const label=textNode("label","Order-specific hosted invoice URL ("+checkout.provider_host+")");
          const url=document.createElement("input");url.type="url";url.required=true;url.maxLength=1000;url.autocomplete="off";label.append(url);
          const ack=document.createElement("label");ack.className="order-date-ack";const check=document.createElement("input");check.type="checkbox";check.required=true;
          ack.append(check,textNode("span","I checked the provider account: this single-order invoice is payable only once, belongs to this customer, and charges exactly "+formatQuoteMoney(order.total_minor,order.currency)+". No subscription, tip or adjustable amount."));
          const submit=textNode("button","Publish invoice link","quiet");submit.type="submit";
          form.append(label,ack,submit);
          form.addEventListener("submit",event=>{
            event.preventDefault();if(requestBusy||!check.checked)return;
            reconcilePayment({action:"issue_checkout_link",order_id:order.id,expected_link_id:latest?.id??null,url:url.value.trim(),currency:order.currency,amount_minor:order.total_minor,confirm_invoice:true});
          });section.append(form);
        }
        if(checkout.can_revoke){
          const form=document.createElement("form");form.className="payment-evidence-form";
          const ack=document.createElement("label");ack.className="order-date-ack";const check=document.createElement("input");check.type="checkbox";check.required=true;
          ack.append(check,textNode("span","I checked the provider: this invoice is closed, cancelled or fully settled and cannot collect another payment."));
          const submit=textNode("button","Remove closed invoice link","quiet");submit.type="submit";form.append(ack,submit);
          form.addEventListener("submit",event=>{event.preventDefault();if(requestBusy||!check.checked)return;
            reconcilePayment({action:"revoke_checkout_link",order_id:order.id,expected_link_id:latest.id,confirm_external_closed:true});});
          section.append(form);
        }
        section.append(textNode("p","The local link expires after seven days or is hidden when evidence is recorded. This does not cancel the external invoice. Verify actual receipt separately.","small"));
        card.append(section);
      }
      if(order.can_cancel_unpaid===true){
        const form=document.createElement("form");form.className="payment-evidence-form";
        const label=textNode("label","Cancellation reason");const select=document.createElement("select");
        for(const [value,name] of [["customer_requested","Customer requested"],["mutual_agreement","Mutual agreement"],["artist_unavailable","Artist unavailable"]])select.append(new Option(name,value));
        label.append(select);
        const funds=document.createElement("label");funds.className="order-date-ack";const noFunds=document.createElement("input");noFunds.type="checkbox";noFunds.required=true;
        funds.append(noFunds,textNode("span","I checked all external payment channels: no money was received or remains pending, and all invoices are closed."));
        const agreed=document.createElement("label");agreed.className="order-date-ack";const agreement=document.createElement("input");agreement.type="checkbox";agreement.required=true;
        agreed.append(agreement,textNode("span","Cancellation is permitted by the agreed terms and has been communicated to the customer."));
        const submit=textNode("button","Cancel unpaid order","quiet");submit.type="submit";form.append(label,funds,agreed,submit);
        form.addEventListener("submit",event=>{event.preventDefault();if(requestBusy||!noFunds.checked||!agreement.checked)return;
          reconcilePayment({action:"cancel_unpaid_order",order_id:order.id,reason:select.value,confirm_no_funds:true,confirm_agreement:true});});
        card.append(form);
      }
      if(order.status==="cancelled")card.append(textNode("p","Cancelled unpaid order. Agreement history retained; no refund was issued.","small"));
      const payment=order.payment_status;
      if(payment==="on_hold")card.append(textNode("p","Payment on hold. Production and downloads are blocked.","small"));
      if(payment==="refunded")card.append(textNode("p","Full external refund recorded. No refund was sent by this website.","small"));
      if(exceptionsEnabled&&["paid","on_hold"].includes(payment)){
        const form=document.createElement("form");form.className="payment-evidence-form";
        const decisionLabel=textNode("label","Financial exception");const choice=document.createElement("select");
        const actions=payment==="paid"?[["hold","Place payment hold"],["refund","Record completed external refund"]]:[["restore","Restore settled payment"],["refund","Record completed external refund"]];
        for(const [value,label] of actions)choice.append(new Option(label,value));
        decisionLabel.append(choice);
        const reasonLabel=textNode("label","Reason for hold");const reasonSelect=document.createElement("select");
        for(const [value,label] of [["dispute","Payment dispute"],["reversal","Payment reversal"],["reconciliation_error","Reconciliation error"]])reasonSelect.append(new Option(label,value));
        reasonLabel.append(reasonSelect);
        const ack=document.createElement("label");ack.className="order-date-ack";
        const check=document.createElement("input");check.type="checkbox";check.required=true;
        const description=textNode("span","");ack.append(check,description);
        const save=textNode("button","Record financial exception","quiet");save.type="submit";
        function updateExceptionForm(){
          reasonLabel.hidden=choice.value!=="hold";check.checked=false;
          description.textContent=choice.value==="hold"?"I checked the external payment record. Access should be paused.":choice.value==="restore"?"I checked the receiving account. The original full payment is settled again.":"I confirmed the original full payment has already been refunded externally. This website will not issue a refund.";
        }
        choice.addEventListener("change",updateExceptionForm);reasonSelect.addEventListener("change",()=>{check.checked=false;});
        form.append(decisionLabel,reasonLabel,ack,save);updateExceptionForm();
        form.addEventListener("submit",event=>{
          event.preventDefault();if(requestBusy||!check.checked)return;
          const decision=choice.value;
          const reason=decision==="hold"?reasonSelect.value:decision==="restore"?"settled":"full_external_refund";
          const message=decision==="hold"?"Pause future artwork access?":decision==="restore"?"Restore access after confirmed settlement?":"Record a completed external full refund? This record is final and moves no money.";
          if(!window.confirm(message))return;
          reconcilePayment({action:"set_payment_exception",order_id:order.id,decision,reason,expected_state:payment,confirm_external_check:true});
        });card.append(form);
      }
      const pending=rows.find(p=>p.status==="pending_verification");
      if(enabled&&order.status==="awaiting_arrangement"&&order.payment_status==="unverified"){
        const form=document.createElement("form");form.className="payment-evidence-form";
        const method=textNode("label","External payment method");const select=document.createElement("select");
        for(const [id,label] of [["bank_transfer","Bank transfer"],["paypal","PayPal"],["stripe","Stripe"],["other","Other external method"]])select.append(new Option(label,id));
        if(pending)select.value=pending.method;method.append(select);
        const refLabel=textNode("label",pending?"Re-enter receiving-account transaction reference":"External transaction reference");
        const ref=document.createElement("input");ref.required=true;ref.minLength=6;ref.maxLength=120;ref.autocomplete="off";refLabel.append(ref);
        const amountLabel=textNode("label","Actual received amount ("+order.currency+")");
        const amount=document.createElement("input");amount.type="text";amount.inputMode="decimal";amount.required=true;amount.placeholder="0.00";amountLabel.append(amount);
        form.append(method,refLabel,amountLabel);
        let check=null;
        if(pending){
          const label=document.createElement("label");label.className="order-date-ack";check=document.createElement("input");check.type="checkbox";check.required=true;
          label.append(check,textNode("span","I independently checked the receiving account: this exact transaction is settled, not pending, reversed or refunded, and belongs to this order."));form.append(label);
        }
        const save=textNode("button",pending?"Confirm reconciled payment":"Record pending evidence","quiet");save.type="submit";form.append(save);
        form.addEventListener("submit",event=>{
          event.preventDefault();if(requestBusy||check&&!check.checked)return;
          const match=amount.value.trim().match(/^(\d{1,8})(?:\.(\d{1,2}))?$/);
          if(!match){requestText("request-detail-status","Enter the actual amount in dollars and cents.");return;}
          const minor=Number(match[1])*100+Number((match[2]||"").padEnd(2,"0"));
          const body={action:pending?"verify_payment_evidence":"record_payment_evidence",order_id:order.id,method:select.value,reference:ref.value.trim(),currency:order.currency,amount_minor:minor};
          if(pending){body.payment_id=pending.id;body.confirm_received=true;}
          reconcilePayment(body);
        });
        card.append(form);
        if(pending){
          const reject=textNode("button","Reject pending evidence","quiet");reject.type="button";
          reject.addEventListener("click",()=>reconcilePayment({action:"reject_payment_evidence",order_id:order.id,payment_id:pending.id,confirm_rejection:true}));
          card.append(reject);
        }
      }
      root.append(card);
    }
  }
  async function reconcilePayment(body){
    if(requestBusy||selectedRequestId===null)return;
    const prompt=body.action==="cancel_unpaid_order"?"Permanently cancel this unpaid order and close its request? No funds will move.":body.action==="issue_checkout_link"?"Publish this exact external invoice for this order? No payment is collected here.":body.action==="revoke_checkout_link"?"Remove this locally after confirming it cannot collect another payment externally?":body.action==="verify_payment_evidence"?"Confirm this exact external payment as received? This does not charge money or release artwork.":body.action==="reject_payment_evidence"?"Reject this evidence? It will remain in the audit history.":"Record pending evidence only? The order stays unpaid until reconciliation.";
    if(body.action!=="set_payment_exception"&&!window.confirm(prompt))return;
    const id=selectedRequestId;requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{...body,request_id:id},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){requestText("request-detail-status","Payment record not changed ("+(data.error||response.status)+").");return;}
      await openRequest(id);await requests();
      requestText("request-detail-status",body.action==="cancel_unpaid_order"?"Unpaid order cancelled. No money moved.":body.action==="issue_checkout_link"?"External invoice link published. Payment is not verified.":body.action==="revoke_checkout_link"?"Closed invoice link removed locally. No money moved.":body.action==="set_payment_exception"?"Payment exception recorded: "+data.payment_status+". No money moved.":data.status==="verified"?"Manual reconciliation recorded. No money moved or artwork released.":"Payment evidence "+data.status+". No money moved.");
    }catch(_){requestText("request-detail-status","Payment update not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }

  const pendingDeliveryFiles=new Map();
  function renderDeliveryDrafts(orders,drafts,enabled,options=[],reviews=[]) {
    const root=$("request-delivery-drafts");
    root.replaceChildren();
    if(!enabled)root.append(textNode("p","Private staging is disabled until approved.","small"));
    for(const option of options){
      const card=document.createElement("article");card.className="notification-card";
      card.append(textNode("strong","Order #"+option.order_id+" · "+workflowLabel(option.status)));
      for(const status of option.next||[]){
        const button=textNode("button",status==="in_progress"?(option.status==="ready_for_delivery"?"Return to production":"Start production"):"Mark ready for delivery","quiet");button.type="button";
        button.addEventListener("click",()=>changeFulfillment({action:"set_production_status",order_id:option.order_id,expected_status:option.status,status}));card.append(button);
      }
      root.append(card);
    }
    for(const review of reviews){
      const card=document.createElement("article");card.className="notification-card";
      card.append(textNode("strong","Customer review · Order #"+review.order_id),
        textNode("p","Included revisions used: "+review.revisions_used+" / "+review.revision_allowance,"small"));
      if(review.latest){
        card.append(textNode("p","Proof "+review.latest.round+" · "+review.latest.status.replaceAll("_"," "),"small"));
        if(review.latest.feedback)card.append(textNode("p",review.latest.feedback,"small"));
      }
      root.append(card);
    }
    for(const draft of drafts||[]){
      if(!Number.isSafeInteger(draft.id)||draft.id<1)continue;
      const card=document.createElement("article");
      card.className="notification-card";
      card.append(textNode("strong",(draft.released?"Released artwork #":"Draft #")+draft.id+" · Order #"+draft.order_id),
        textNode("small",draft.width+" × "+draft.height+" · "+draft.created_at));
      const link=textNode("a","Download private WebP draft","quiet");
      link.href="../api/admin/delivery-draft.php?id="+draft.id;
      link.rel="noopener noreferrer";
      const preview=textNode("a","Open private preview","quiet");
      preview.href=link.href+"&view=preview";preview.target="_blank";preview.rel="noopener noreferrer";
      const actions=document.createElement("div");actions.className="delivery-draft-actions";
      actions.append(preview,link);
      const review=reviews.find(r=>r.order_id===draft.order_id);
      const latest=review?.latest;
      if(!draft.released&&review?.can_offer===true&&latest?.draft_id!==draft.id){
        const offer=textNode("button","Send watermarked proof for review","quiet");offer.type="button";
        offer.addEventListener("click",()=>changeFulfillment({action:"offer_artwork_review",order_id:draft.order_id,draft_id:draft.id}));actions.append(offer);
      }
      if(!draft.released && (!latest||(latest.status==="approved"&&latest.draft_id===draft.id)) && options.some(o=>o.order_id===draft.order_id&&o.can_release===true)){
        const release=textNode("button","Release final artwork","quiet");release.type="button";
        release.addEventListener("click",()=>changeFulfillment({action:"release_delivery",order_id:draft.order_id,draft_id:draft.id,confirm_final:true}));actions.append(release);
      }
      if(enabled&&!draft.released&&!(latest?.draft_id===draft.id&&["pending","approved"].includes(latest.status))){
        const remove=textNode("button","Remove draft","quiet");
        remove.type="button";remove.disabled=requestBusy;
        remove.addEventListener("click",()=>removeDeliveryDraft(draft.id));
        actions.append(remove);
      }
      card.append(actions);root.append(card);
    }
    if(!enabled)return;
    for(const order of orders||[]){
      const eligible=["awaiting_arrangement","in_progress","ready_for_delivery","queued","drawing","awaiting_feedback","revision"].includes(order.status)&&
        (drafts||[]).filter(d=>d.order_id===order.id).length<4;
      const previous=pendingDeliveryFiles.get(order.id);
      if(!eligible&&!previous)continue;
      const form=document.createElement("form");
      form.className="delivery-draft-form";
      const label=textNode("label","Stage artwork for order #"+order.id);
      const file=document.createElement("input");file.type="file";
      file.accept="image/jpeg,image/png,image/webp";label.append(file);
      if(!eligible)label.hidden=true;
      const retained=textNode("p","","small");retained.setAttribute("role","status");
      const button=textNode("button","Stage privately","quiet");button.type="submit";
      const discard=textNode("button","Discard selected artwork","quiet");discard.type="button";
      function reflect(){
        const selected=pendingDeliveryFiles.get(order.id);
        retained.textContent=selected?"Selected: "+selected.name+" · kept in this request until staged or discarded.":"No artwork selected.";
        file.required=eligible&&!selected;
        discard.hidden=!selected;
        button.textContent=selected&&deliveryDraftUploadKeys.has(selected)?"Retry staging":"Stage privately";
        updateRequestDraftIndicator();
      }
      file.addEventListener("change",()=>{
        if(!file.files?.length)return;
        if(pendingDeliveryFiles.has(order.id)&&!window.confirm("Replace the retained delivery artwork with this new selection?")){file.value="";return;}
        pendingDeliveryFiles.set(order.id,file.files[0]);reflect();
      });
      discard.addEventListener("click",()=>{
        if(requestBusy||!window.confirm("Discard the selected delivery artwork?"))return;
        pendingDeliveryFiles.delete(order.id);file.value="";reflect();
        if(!eligible)form.remove();
      });
      form.append(label,textNode("p","JPEG, PNG or WebP; up to 8 MB. Re-encoded to high-quality WebP without embedded metadata.","small"),retained,button,discard);
      form.addEventListener("submit",event=>{
        event.preventDefault();
        const selected=pendingDeliveryFiles.get(order.id);
        if(requestBusy||!selected)return;
        if(!eligible&&!deliveryDraftUploadKeys.has(selected))return;
        stageDeliveryDraft(order.id,selected);
      });
      reflect();
      root.append(form);
    }
  }
  async function changeFulfillment(body){
    if(requestBusy||selectedRequestId===null)return;
    if(!window.confirm(body.action==="release_delivery"?
      "Release this exact drawing to the customer and complete the order? The customer can download it immediately. No charge or email is sent.":body.action==="offer_artwork_review"?"Share a reduced, watermarked proof with the customer for approval or included revisions?":"Update this paid order's production status?"))return;
    const id=selectedRequestId;requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{...body,request_id:id},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){requestText("request-detail-status","Order not changed ("+(data.error||response.status)+").");return;}
      await openRequest(id);await requests();
      requestText("request-detail-status",body.action==="release_delivery"?"Final artwork released to customer. Order completed.":body.action==="offer_artwork_review"?"Proof available for customer review. Final artwork remains private.":"Production status updated.");
    }catch(_){requestText("request-detail-status","Order update not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }
  async function removeDeliveryDraft(draftId){
    if(requestBusy||selectedRequestId===null||!Number.isSafeInteger(draftId))return;
    if(!window.confirm("Remove this private draft from the order? This cannot be undone here."))return;
    const id=selectedRequestId;
    requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{action:"remove_delivery_draft",request_id:id,draft_id:draftId,confirm_remove:true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){requestText("request-detail-status","Draft removal not confirmed. Refresh before retrying.");return;}
      await openRequest(id);
      requestText("request-detail-status",data.storage_cleanup_complete===true
        ?"Private draft removed. Order and payment status unchanged."
        :"Draft access revoked; private file cleanup requires review.");
    }catch(_){requestText("request-detail-status","Draft removal outcome uncertain. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }
  const deliveryDraftUploadKeys=new WeakMap();
  async function stageDeliveryDraft(orderId,file){
    if(requestBusy||selectedRequestId===null)return;
    if(file.size>8*1024*1024){requestText("request-detail-status","Draft must be 8 MB or smaller.");return;}
    const id=selectedRequestId,body=new FormData();
    if(!deliveryDraftUploadKeys.has(file))deliveryDraftUploadKeys.set(file,crypto.randomUUID());
    body.append("request_id",String(id));body.append("order_id",String(orderId));body.append("artwork",file);body.append("operation_key",deliveryDraftUploadKeys.get(file));
    requestBusy=true;updateRequestButtons();
    requestText("request-detail-status","Uploading and processing private artwork…");
    try{
      const response=await fetch("../api/admin/delivery-draft.php",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"X-DMO-CSRF":csrf},body});
      if(response.status===401)return sessionExpired();
      const data=await response.json();
      if(!response.ok||!data.ok){requestText("request-detail-status","Draft not staged ("+(data.error||response.status)+").");return;}
      pendingDeliveryFiles.delete(orderId);
      await openRequest(id);
      requestText("request-detail-status",data.already_saved?"Existing artwork #"+data.draft_id+" recovered ("+(data.released?"released":"staged") + "). No additional draft created.":"Artwork staged privately. Customer access and payment status unchanged.");
    }catch(_){requestText("request-detail-status","Draft upload not confirmed. Retry the retained file to recover the original receipt.");}
    finally{requestBusy=false;updateRequestButtons();
      if(pendingDeliveryFiles.has(orderId))for(const button of $("request-delivery-drafts").querySelectorAll("button[type=submit]")){const form=button.closest("form");if(form?.contains(document.activeElement)||$("request-delivery-drafts").querySelectorAll("button[type=submit]").length===1)button.textContent="Retry staging";}
    }
  }

  function renderNotifications(options,enabled) {
    const root=$("request-notifications");
    root.replaceChildren();
    if(!enabled)root.append(textNode("p","Customer email notices are disabled until approved.","small"));
    if(!Array.isArray(options)||!options.length){
      root.append(textNode("p","No current account-update notice is available.","small"));
      return;
    }
    const statusLabels={not_sent:"Not sent",accepted:"Accepted by mail server; inbox delivery not confirmed",
      failed:"Transport did not confirm acceptance; automatic retry disabled",
      uncertain:"Attempt reserved; outcome uncertain; automatic retry disabled"};
    for(const option of options){
      const card=document.createElement("article");
      card.className="notification-card";
      card.append(textNode("strong",({quote:"Quote",order:"Order",message:"Private message",proof:"Artwork review",delivery:"Final artwork"}[option.kind]||"Account update")+" #"+option.event_id),
        textNode("p",statusLabels[option.status]||"Unknown status","small"));
      if(option.can_send===true&&enabled){
        const button=textNode("button","Send account-update email","quiet");
        button.type="button";
        button.disabled=requestBusy;
        button.addEventListener("click",()=>sendCustomerNotice(option));
        card.append(button);
      }else if(enabled&&option.status==="not_sent"){
        card.append(textNode("p","Not eligible: check customer email preference, verification and current account-update status.","small"));
      }
      root.append(card);
    }
  }
  async function sendCustomerNotice(option){
    if(requestBusy||selectedRequestId===null||option.can_send!==true)return;
    if(!window.confirm("Send one generic account-update email to this verified customer? No commission details or payment request will be included."))return;
    const id=selectedRequestId;
    requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{action:"send_notification",request_id:id,kind:option.kind,event_id:option.event_id,confirm_send:true},csrf);
      if(response.status===401)return sessionExpired();
      await openRequest(id);
      requestText("request-detail-status",response.ok&&data.transport_accepted===true
        ?"Mail server accepted the notice. Inbox delivery has not been confirmed."
        :"Notice not confirmed. Review its recorded status before contacting the customer.");
    }catch(_){requestText("request-detail-status","Notice outcome uncertain. Refresh before taking any further action.");}
    finally{requestBusy=false;updateRequestButtons();}
  }

  function quoteHasDraft(){
    return $("quote-scope").value!==""||$("quote-currency").value!=="CAD"||
      $("quote-revisions").value!==$("quote-revisions").defaultValue||
      $("quote-lines").childElementCount>1||
      [...$("quote-lines").querySelectorAll("input")].some(input=>input.value!=="");
  }
  function requestDraftKinds(){
    const kinds=[];
    if($("request-note-body").value!==""||pendingAdminMessages.has("add_private_note"))kinds.push("private note");
    if($("request-message-body").value!==""||pendingAdminMessages.has("send_customer_message"))kinds.push("customer message");
    if(quoteHasDraft()||pendingAdminMessages.has("create_quote_draft"))kinds.push("quote");
    if(pendingDeliveryFiles.size)kinds.push("delivery artwork selection");
    if(hasTransientRequestEdits())kinds.push("order, payment or review fields");
    return kinds;
  }
  function updateRequestDraftIndicator(){
    const kinds=requestDraftKinds();
    $("request-drafts-warning").hidden=kinds.length===0||selectedRequestId===null;
    $("request-drafts-warning").textContent=kinds.length?"Unsaved "+kinds.join(", ")+". These edits belong to this request only."+(hasTransientRequestEdits()?" Order/payment/review fields are reset by a server refresh or another saved action. Complete the current form first.":""):"";
  }
  function confirmDiscardRequestDrafts(){
    const kinds=requestDraftKinds();
    return kinds.length===0||window.confirm("Discard the unsaved "+kinds.join(", ")+" for this request?"+(pendingAdminMessages.size?" A previous save may already have succeeded; discarding loses its retry key.":""));
  }
  for(const id of ["request-note-form","request-message-form","request-quote-form"]){
    $(id).addEventListener("input",updateRequestDraftIndicator);
    $(id).addEventListener("change",updateRequestDraftIndicator);
  }
  for(const event of ["input","change"]){
    $("request-detail").addEventListener(event,e=>{
      if(e.target instanceof Element&&e.target.matches(transientRequestSelector))updateRequestDraftIndicator();
    });
  }
  function updateRequestButtons() {
    $("request-detail-close").disabled = requestBusy;
    $("request-detail-refresh").disabled = requestBusy;
    $("request-thread-full").disabled = requestBusy;
    $("request-review-save").disabled = requestBusy || !reviewStatus;
    $("request-review-status").disabled = requestBusy || !reviewStatus;
    for(const button of $("request-notifications").querySelectorAll("button"))button.disabled=requestBusy;
    for(const field of $("request-delivery-drafts").querySelectorAll("input,button"))field.disabled=requestBusy;
    for(const field of $("request-payment-records").querySelectorAll("input,select,button"))field.disabled=requestBusy;
    for(const button of $("request-quotes").querySelectorAll("button[data-quote-withdraw]"))button.disabled=requestBusy;
    for(const field of $("request-orders").querySelectorAll(".order-date-form input,.order-date-form button"))field.disabled=requestBusy;
    for (const field of $("request-note-form").querySelectorAll("textarea")) {
      field.disabled = !requestWriteEnabled || requestBusy;
    }
    for (const field of $("request-message-form").querySelectorAll("textarea")) {
      field.disabled = !requestMessagingEnabled || requestBusy;
    }
    for (const field of $("request-quote-form").querySelectorAll("input,textarea,select")) {
      field.disabled = !quoteDraftAllowed || requestBusy;
    }
    $("request-note-save").disabled = !requestWriteEnabled || requestBusy ||
      selectedRequestId === null;
    $("request-message-send").disabled = !requestMessagingEnabled || requestBusy ||
      selectedRequestId === null;
    $("quote-save").disabled = !quoteDraftAllowed || requestBusy ||
      selectedRequestId === null;
    $("quote-add-line").disabled = !quoteDraftAllowed || requestBusy || $("quote-lines").childElementCount>=12;
    for(const button of $("quote-lines").querySelectorAll("button"))button.disabled=!quoteDraftAllowed||requestBusy||$("quote-lines").childElementCount<=1;
    updateRequestDraftIndicator();
  }

  function resetQuoteDraft(){
    pendingAdminMessages.delete('create_quote_draft');
    $("request-quote-form").reset();
    $("quote-lines").replaceChildren();
    addQuoteLine();
  }
  function resetRequestDrafts() {
    pendingAdminMessages.clear();pendingDeliveryFiles.clear();
    $("request-note-form").reset();
    $("request-message-form").reset();
    resetQuoteDraft();
  }

  function clearRequestContent() {
    transientRequestBaseline.clear();
    $("request-at-a-glance").hidden=true;
    $("request-milestones").replaceChildren();$("request-section-links").replaceChildren();$("request-next-step").textContent="";
    for (const id of ["request-detail-ref","request-detail-title","request-customer",
      "request-tier","request-contact","request-deadline","request-brief-text","request-policy-status"]) {
      requestText(id, "—");
    }
    $("request-activity-limit").hidden=true;activityBefore=null;activityBusy=false;syncActivityControls();$("request-activity-feedback").textContent="";
    for (const id of ["request-thread","request-quotes","request-references","request-orders","request-notifications","request-delivery-drafts","request-payment-records","request-activity-list"]) {
      $(id).replaceChildren();
    }
  }

  async function openRequest(id,userInitiated=false) {
    const parsed = Number(id);
    if (!Number.isSafeInteger(parsed) || parsed < 1) return;
    if (requestBusy && selectedRequestId !== parsed) return;
    const switching = selectedRequestId !== parsed;
    if(switching&&selectedRequestId!==null&&!confirmDiscardRequestDrafts())return;
    if(!switching&&userInitiated&&!confirmTransientRefresh())return;
    const generation = ++requestLoadGeneration;
    selectedRequestId = parsed;
    requestWriteEnabled = false;
    requestMessagingEnabled = false;
    quoteDeliveryEnabled = false;
    orderCreationEnabled = false;
    $("request-message-form").hidden = true;
    quoteDraftAllowed = false;
    reviewStatus = null;
    $("request-review-actions").hidden = true;
    $("request-thread-full").hidden = true;
    $("request-thread-count").textContent="";
    $("request-conversation-full").hidden=true;
    if (switching) { requestFullThread=false; resetRequestDrafts(); }
    clearRequestContent();
    requestText("request-policy-status","");
    updateRequestButtons();
    $("request-detail").hidden = false;
    requestText("request-detail-status", "Loading complete request...");
    if(switching)$("request-detail").scrollIntoView({behavior:"instant",block:"start"});
    let response, data;
    try {
      ({response, data} = await get("request.php?id=" + encodeURIComponent(parsed) + (requestFullThread ? "&thread=full" : "")));
    } catch (_) {
      if (generation === requestLoadGeneration) {
        requestText("request-detail-status", "Unable to load request. Select it again to retry.");
      }
      return;
    }
    if (generation !== requestLoadGeneration || selectedRequestId !== parsed) return;
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok || !data.detail) {
      requestText("request-detail-status", "Unable to load request: HTTP " + response.status);
      return;
    }
    const item = data.detail;
    const info = item.request || {};
    requestWriteEnabled = data.admin_writes_enabled === true;
    quoteDraftAllowed = requestWriteEnabled && !["cancelled","canceled","rejected","declined","completed","closed","archived","refunded"].includes(info.status) && !(item.quotes||[]).some(q=>q.status==="accepted");
    const choices = Array.isArray(item.review_choices) ? item.review_choices : [];
    reviewStatus = choices.length ? info.status : null;
    const select = $("request-review-status");
    select.replaceChildren();
    const labels = {submitted:"Return to review",clarification_needed:"Request clarification",rejected:"Decline request"};
    for(const choice of choices)if(labels[choice])select.append(new Option(labels[choice],choice));
    $("request-review-actions").hidden = !reviewStatus;
    $("request-thread-full").hidden = item.messages_have_older !== true;
    requestMessagingEnabled = data.customer_messaging_enabled === true;
    quoteDeliveryEnabled = data.quote_sending_enabled === true;
    orderCreationEnabled = data.order_creation_enabled === true;
    $("request-message-form").hidden = !requestMessagingEnabled;
    const policy=$("request-policy-status");policy.replaceChildren();
    if(typeof info.policy_version==="string"){
      policy.append(document.createTextNode("Agreement: "+info.policy_version+" · "+(data.policy_eligible===true?"Eligible for continuation":data.policy_eligible===false?"Continuation not approved or receipt unavailable":"Not checked")));
      if(typeof info.policy_sha256==="string"&&/^[a-f0-9]{64}$/.test(info.policy_sha256)){
        const link=document.createElement("a");link.href="../policy.php?"+new URLSearchParams({version:info.policy_version,sha256:info.policy_sha256});link.textContent=" Read recorded agreement";link.target="_blank";link.rel="noopener noreferrer";policy.append(link);
      }
    }
    requestText("request-detail-ref", info.public_ref || "COMMISSION REQUEST");
    requestText("request-detail-title", "Request #" + (info.id || parsed) +
      " · " + (info.status || "unknown"));
    requestText("request-customer",
      (info.customer_name || "Customer") + " · " + (info.customer_email || ""));
    const addons = Array.isArray(item.addons)
      ? item.addons.map(x => x.name).filter(Boolean) : [];
    requestText("request-tier", [info.subject_type || "", info.tier_name || "Custom",
      ...addons].filter(Boolean).join(" · "));
    requestText("request-contact", info.preferred_contact || "");
    requestText("request-deadline", info.preferred_deadline || "Not specified");
    requestText("request-brief-text", info.brief || "");
    renderThread(item.messages);
    const messageCount=item.conversation_message_count, messageLimit=item.conversation_message_limit;
    if(Number.isSafeInteger(messageCount)&&messageCount>=0&&Number.isSafeInteger(messageLimit)&&messageLimit>0){
      $("request-thread-count").textContent=(Array.isArray(item.messages)?item.messages.length:0)+" shown · "+messageCount+" of "+messageLimit+" notes/messages stored.";
      $("request-conversation-full").hidden=messageCount<messageLimit;
    }
    renderQuotes(item.quotes);
    renderRequestActivity(item.activity);
    renderRequestReferences(item.references);
    renderAdminOrders(item.orders, item.quotes);
    renderNotifications(data.notification_options,data.notifications_enabled===true);
    renderDeliveryDrafts(item.orders,data.delivery_drafts,data.delivery_staging_enabled===true,data.fulfillment_options||[],data.artwork_reviews||[]);
    renderPaymentRecords(item.orders,data.payment_records,data.manual_payments_enabled===true,data.payment_exceptions_enabled===true,Array.isArray(data.checkout_links)?data.checkout_links:[]);
    renderArtistRequestSummary(data);
    captureTransientRequestFields();
    requestText("request-detail-status", requestWriteEnabled
      ? "Admin draft editing available. Nothing is sent to the customer."
      : "Viewing only. Private note and quote creation are disabled on the server.");
    updateRequestButtons();
  }

  $("request-detail-refresh").addEventListener("click",()=>{
    if(selectedRequestId!==null&&!requestBusy)openRequest(selectedRequestId,true);
  });
  $("request-thread-full").addEventListener("click",()=>{
    if(selectedRequestId===null||requestBusy)return;
    if(!confirmTransientRefresh())return;
    requestFullThread=true;
    openRequest(selectedRequestId);
  });
  $("request-review-save").addEventListener("click",async()=>{
    if(requestBusy||!reviewStatus||selectedRequestId===null)return;
    const id=selectedRequestId, status=$("request-review-status").value;
    if(!window.confirm("Update this customer's review status? No message or payment will be sent."))return;
    requestBusy=true;
    updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{action:"set_review_status",request_id:id,expected_status:reviewStatus,status},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){
        requestText("request-detail-status","Status not changed. Refresh the request before retrying.");
        return;
      }
      await openRequest(id);
      await requests();
      requestText("request-detail-status","Review status updated. No message or payment sent.");
    }catch(_){requestText("request-detail-status","Status update not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  });

  function closeRequestDetail(force=false) {
    if (!force&&(requestBusy||!confirmDiscardRequestDrafts())) return false;
    ++requestLoadGeneration;
    selectedRequestId = null;
    reviewStatus = null;
    quoteDraftAllowed = false;
    requestWriteEnabled = false;
    requestMessagingEnabled = false;
    quoteDeliveryEnabled = false;
    orderCreationEnabled = false;
    $("request-detail").hidden = true;
    resetRequestDrafts();
    clearRequestContent();
    updateRequestButtons();return true;
  }
  $("request-detail-close").addEventListener("click",()=>closeRequestDetail());

  function addQuoteLine(description = "", amount = "") {
    const lines = $("quote-lines");
    if (lines.childElementCount >= 12) return;
    const row = document.createElement("div");
    row.className = "quote-line";
    const text = document.createElement("input");
    text.type = "text";
    text.maxLength = 180;
    text.className = "quote-line-description";
    text.placeholder = "Item description";
    text.setAttribute("aria-label","Quote line description");
    text.value = description;
    const price = document.createElement("input");
    price.type = "text";
    price.inputMode = "decimal";
    price.maxLength = 12;
    price.className = "quote-line-price";
    price.placeholder = "Amount ($)";
    price.setAttribute("aria-label","Quote line amount in dollars");
    price.value = amount;
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "quiet";
    remove.textContent = "Remove";
    remove.setAttribute("aria-label","Remove quote line");
    remove.addEventListener("click", () => {
      if (!quoteDraftAllowed || requestBusy) return;
      if (lines.childElementCount > 1) row.remove();
      updateQuotePreview();
    });
    price.addEventListener("input", updateQuotePreview);
    row.append(text,price,remove);
    lines.append(row);
    updateQuotePreview();
  }

  function quoteFormLines() {
    const rows = [];
    for (const line of $("quote-lines").querySelectorAll(".quote-line")) {
      const description = line.querySelector(".quote-line-description").value.trim();
      const amount_minor = parsePrice(
        line.querySelector(".quote-line-price").value
      );
      if (!description || description.length > 180 ||
          amount_minor === false || amount_minor === null || amount_minor < 1) {
        return null;
      }
      rows.push({description,amount_minor});
    }
    return rows.length > 0 && rows.length <= 12 ? rows : null;
  }

  function updateQuotePreview() {
    let total = 0, valid = true;
    for (const line of $("quote-lines").querySelectorAll(".quote-line")) {
      const cents = parsePrice(line.querySelector(".quote-line-price").value);
      if (cents === null || cents === false || cents < 1) valid = false;
      else total += cents;
    }
    requestText("quote-total",
      valid && total <= 10000000
        ? "Draft total: " + formatQuoteMoney(total, $("quote-currency").value)
        : "Enter an amount for each line");
    $("quote-add-line").disabled = !quoteDraftAllowed || requestBusy ||
      $("quote-lines").childElementCount >= 12;
    for(const button of $("quote-lines").querySelectorAll("button"))button.disabled=!quoteDraftAllowed||requestBusy||$("quote-lines").childElementCount<=1;
    updateRequestDraftIndicator();
  }

  $("quote-add-line").addEventListener("click", () => {if(quoteDraftAllowed&&!requestBusy)addQuoteLine();});
  $("quote-currency").addEventListener("change", updateQuotePreview);
  addQuoteLine();

  $("request-note-form").addEventListener("submit", async event => {
    event.preventDefault();
    if (!requestWriteEnabled || requestBusy || selectedRequestId === null) return;
    const body = $("request-note-body").value.trim();
    if (!body || body.length > 5000) {
      requestText("request-detail-status", "Note must contain 1–5000 characters.");
      return;
    }
    const id = selectedRequestId;
    if(!confirmChangedAdminOperation("add_private_note",id,body))return;
    requestBusy = true;
    updateRequestButtons();
    try {
      const {response, data} = await post("request-actions.php", {
        action:"add_private_note", request_id:id, body,operation_key:adminMessageKey("add_private_note",id,body),
      }, csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status",
          data.error==="conversation_limit"?"Conversation capacity reached. New notes cannot be saved; existing notes are retained.":"Note not saved (" + (data.error || response.status) + ").");
        return;
      }
      pendingAdminMessages.delete("add_private_note");
      $("request-note-body").value = "";
      await openRequest(id);
      requestText("request-detail-status", "Private note saved. Not sent to customer.");
    } catch (_) {
      requestText("request-detail-status", "Connection problem. Save not confirmed.");
    } finally {
      requestBusy = false;
      updateRequestButtons();
    }
  });


  $("request-message-form").addEventListener("submit", async event => {
    event.preventDefault();
    if (!requestMessagingEnabled || requestBusy || selectedRequestId === null) return;
    const body = $("request-message-body").value.trim();
    if (body.length < 2 || body.length > 3000) {
      requestText("request-detail-status", "Message must contain 2–3000 characters.");
      return;
    }
    const id = selectedRequestId;
    if(!confirmChangedAdminOperation("send_customer_message",id,body))return;
    if (!window.confirm("Send this message to the customer's private account?")) return;
    requestBusy = true;
    updateRequestButtons();
    try {
      const {response, data} = await post("request-actions.php", {
        action:"send_customer_message",request_id:id,body,operation_key:adminMessageKey("send_customer_message",id,body),
      },csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status", data.error==="conversation_limit"?"Conversation capacity reached. New replies cannot be saved; contact the customer separately if appropriate.":"Message not sent (" + (data.error || response.status) + ").");
        return;
      }
      pendingAdminMessages.delete("send_customer_message");
      $("request-message-body").value = "";
      await openRequest(id);
      requestText("request-detail-status",
        "Customer-visible message saved. No email or payment was sent.");
    } catch (_) {
      requestText("request-detail-status",
        "Connection interrupted. Retrying the unchanged message safely recovers the result.");
    } finally {
      requestBusy = false;
      updateRequestButtons();
    }
  });

  $("request-quote-form").addEventListener("submit", async event => {
    event.preventDefault();
    if (!quoteDraftAllowed || requestBusy || selectedRequestId === null) return;
    const scope = $("quote-scope").value.trim();
    const revision_allowance = Number($("quote-revisions").value);
    const lines = quoteFormLines();
    if (scope.length < 10 || scope.length > 5000 ||
        $("quote-revisions").value.trim()==="" || !Number.isInteger(revision_allowance) ||
        revision_allowance < 0 || revision_allowance > 25 || !lines ||
        lines.reduce((sum,line)=>sum+line.amount_minor,0) > 10000000) {
      requestText("request-detail-status", "Complete the scope, revisions and valid line prices.");
      return;
    }
    const id = selectedRequestId;
    const quotePayload=JSON.stringify({currency:$("quote-currency").value,scope,revision_allowance,lines});
    if(!confirmChangedAdminOperation("create_quote_draft",id,quotePayload))return;
    if (!window.confirm("Create a NEW internal quote version? It will not be sent.")) return;
    requestBusy = true;
    updateRequestButtons();
    try {
      const {response, data} = await post("request-actions.php", {
        action:"create_quote_draft", request_id:id,
        currency:$("quote-currency").value, scope, revision_allowance, lines,
        operation_key:adminMessageKey("create_quote_draft",id,quotePayload),
      }, csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status",
          "Draft not saved (" + (data.error || response.status) + ").");
        return;
      }
      resetQuoteDraft();
      await openRequest(id);
      requestText("request-detail-status",
        data.already_saved?"Existing quote version "+data.version+" recovered ("+data.status+"). No new quote created.":
        "Draft quote version " + data.version + " saved privately. No customer contact.");
    } catch (_) {
      requestText("request-detail-status", "Connection problem. Retry the unchanged quote to recover the original save.");
    } finally {
      requestBusy = false;
      updateRequestButtons();
    }
  });


  // Real artwork is processed on Hostinger and stored outside the webroot.
  // The browser never receives private storage paths or original filenames.
  let artworkRecords = [];
  let artworkWriteEnabled = false;
  let artworkBusy = false;
  let artworkPage=1,artworkGeneration=0,artworkLoading=false;
  // In-memory drafts survive filtering, paging and server refreshes, but never a sign-in session.
  const artworkDrafts=new Map();
  let artworkPendingFiles=[],artworkUploadRunning=false,artworkStopRequested=false;
  const artworkUploadKeys=new WeakMap();
  const artworkUploadPreferences=new WeakMap();
  let artworkUploadTotals=null;
  function artworkDraftKey(id,field){return id+":"+field;}
  function artworkDraftInput(work,field,input){
    const key=artworkDraftKey(work.id,field),base=String(work[field]??"");
    input.value=artworkDrafts.get(key)?.value??base;
    input.addEventListener("input",()=>{
      const old=artworkDrafts.get(key);
      if(input.value===base)artworkDrafts.delete(key);
      else artworkDrafts.set(key,{base:old?.base??base,value:input.value});
      artworkControls();
    });
  }
  function artworkDraftCanSave(work,field){
    const draft=artworkDrafts.get(artworkDraftKey(work.id,field));
    if(draft&&draft.base!==String(work[field]??"")){
      artworkState("This drawing changed on the server. Discard unsaved edits and review the latest values before saving.");
      return false;
    }
    return true;
  }
  $("artwork-review-edits").addEventListener("click",()=>{
    if(artworkBusy||artworkLoading||!artworkDrafts.size)return;
    $("artwork-filter").value="unsaved";$("artwork-orientation").value="all";$("artwork-search").value="";
    artworkPage=1;renderArtwork();$("artwork-filter").focus();
  });
  $("artwork-discard-edits").addEventListener("click",()=>{
    if(artworkBusy||artworkLoading||!artworkDrafts.size)return;
    if(!window.confirm("Discard all unsaved artwork descriptions and sort orders?"))return;
    artworkDrafts.clear();renderArtwork();artworkState("Unsaved artwork edits discarded.");
  });

  $("artwork-save-edits").addEventListener("click",async()=>{
    if(!artworkWriteEnabled||artworkBusy||artworkLoading||!artworkDrafts.size||artworkDrafts.size>60)return;
    const edits=[];
    for(const [key,draft] of artworkDrafts){
      const [rawId,field]=key.split(":"),id=Number(rawId);
      const work=artworkRecords.find(w=>w.id===id);
      if(!work){artworkState("A drawing is no longer in this library. Refresh and review unsaved edits.");return;}
      if(!artworkDraftCanSave(work,field))return;
      const value=field==="sort_order"?Number(draft.value):draft.value.trim();
      if(field==="sort_order"&&(!draft.value.trim()||!Number.isInteger(value)||value< -10000||value>10000)){
        artworkState("Check sort order for drawing #"+id+" before saving the batch.");return;
      }
      if(field==="alt"&&(new TextEncoder().encode(value).length>240||/[\x00-\x1f\x7f]/.test(value))){
        artworkState("Check description length for drawing #"+id+" before saving the batch.");return;
      }
      edits.push({id,field,expected:field==="sort_order"?Number(draft.base):draft.base,value});
    }
    if(!window.confirm("Save "+edits.length+" artwork metadata field(s) together? Published drawings will reflect these edits. No artwork will be published or unpublished."))return;
    artworkBusy=true;artworkControls();
    try{
      const {response,data}=await post("artworks.php",{action:"batch_metadata",edits,confirm:true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||data.processed!==edits.length){
        if(response.status===409||response.status>=500)artworkWriteEnabled=false;
        artworkState("Metadata batch not confirmed. No partial changes are applied. Refresh to review conflicts; unsaved edits are retained.");return;
      }
      for(const edit of edits)artworkDrafts.delete(artworkDraftKey(edit.id,edit.field));
      if(await artworksLoad())artworkState("Saved "+data.processed+" artwork metadata field(s) together. Publication unchanged.");
    }catch(_){artworkWriteEnabled=false;artworkState("Metadata save outcome uncertain. Refresh before retrying.");}
    finally{artworkBusy=false;if(!leavingSession)artworkControls();}
  });

  function artworkState(message) {
    $("artwork-library-status").textContent = message;
  }

  function artworkControls() {
    const checked = [...document.querySelectorAll("#artwork-list .artwork-select:checked")];
    const canWrite = artworkWriteEnabled && !artworkBusy && !artworkLoading;
    $("artwork-refresh").disabled=artworkBusy||artworkLoading;
    $("artwork-files").disabled=!canWrite;
    $("artwork-skip-identical").disabled=!canWrite||artworkPendingFiles.length>0;
    for(const field of $("artwork-list").querySelectorAll("button,input"))field.disabled=!canWrite;
    const selectable=$("artwork-list").querySelectorAll(".artwork-select").length;
    $("artwork-select-all").disabled=!canWrite||selectable===0;
    $("artwork-select-all").checked=selectable>0&&checked.length===selectable;
    $("artwork-select-all").indeterminate=checked.length>0&&checked.length<selectable;
    $("artwork-selection-count").textContent=checked.length+" selected on this page";
    $("artwork-unsaved").hidden=artworkDrafts.size===0;
    const editedIds=new Set([...artworkDrafts.keys()].map(key=>Number(key.split(":")[0])));
    $("artwork-unsaved-count").textContent=artworkDrafts.size+" unsaved artwork field(s) on "+editedIds.size+" drawing(s). Save individual edits or up to 60 together. Unsaved edits are not published.";
    $("artwork-review-edits").disabled=artworkBusy||artworkLoading||!editedIds.size;
    for(const button of $("artwork-list").querySelectorAll("[data-artwork-discard]")){
      button.hidden=!editedIds.has(Number(button.dataset.artworkDiscard));
      button.disabled=artworkBusy||artworkLoading;
    }
    $("artwork-save-edits").disabled=!canWrite||artworkDrafts.size===0||artworkDrafts.size>60;
    $("artwork-discard-edits").disabled=artworkBusy||artworkLoading;
    $("artwork-upload").disabled = !canWrite;
    $("artwork-upload-resume").hidden=artworkPendingFiles.length===0;
    $("artwork-upload-resume").disabled=!canWrite;
    $("artwork-upload-resume").textContent="Upload "+artworkPendingFiles.length+" remaining file(s)";
    $("artwork-upload-discard").hidden=artworkPendingFiles.length===0;
    $("artwork-upload-discard").disabled=artworkBusy||artworkLoading;
    $("artwork-upload-stop").hidden=!artworkUploadRunning;
    $("artwork-upload-stop").disabled=artworkStopRequested;
    $("artwork-publish-selected").disabled = !canWrite ||
      !checked.some(input => input.dataset.visibility === "draft");
    $("artwork-unpublish-selected").disabled = !canWrite ||
      !checked.some(input => input.dataset.visibility === "published");
    $("artwork-feature-selected").disabled=!canWrite||!checked.some(input=>input.dataset.featured==="false");
    $("artwork-unfeature-selected").disabled=!canWrite||!checked.some(input=>input.dataset.featured==="true");
  }

  function artworkSelectCount() {
    return document.querySelectorAll("#artwork-list .artwork-select:checked").length;
  }

  function renderArtwork() {
    const target = $("artwork-list");
    target.replaceChildren();
    if (!artworkRecords.length) {
      $("artwork-visible-count").textContent = "No artworks uploaded";
      $("artwork-page").textContent="";$("artwork-prev").disabled=$("artwork-next").disabled=true;
      $("artwork-select-all").checked = false;
      empty(target, "No real artwork uploaded yet. Choose drawing files above.");
      artworkControls();
      return;
    }
    const filter = $("artwork-filter").value;
    const orientation=$("artwork-orientation").value;
    const shape=w=>!Number.isSafeInteger(w.width)||!Number.isSafeInteger(w.height)||w.width<1||w.height<1?"unknown":w.width===w.height?"square":w.width>w.height?"landscape":"portrait";
    const bytes=w=>Number.isSafeInteger(w.display_bytes)&&Number.isSafeInteger(w.thumb_bytes)?w.display_bytes+w.thumb_bytes:-1;
    const query=$("artwork-search").value.trim().toLocaleLowerCase();
    const matching=artworkRecords.filter(work=>(filter==="all"||
      (filter==="featured"?work.featured===true:filter==="missing_alt"?!String(work.alt||"").trim():filter==="unsaved"?["sort_order","alt"].some(field=>artworkDrafts.has(artworkDraftKey(work.id,field))):work.visibility===filter))&&
      (orientation==="all"||shape(work)===orientation)&&(!query||String(work.id)===query.replace(/^#/,"")||String(work.alt||"").toLocaleLowerCase().includes(query)));
    const mode=$("artwork-sort-mode").value;
    if(mode==="gallery")matching.sort((a,b)=>Number(b.featured)-Number(a.featured)||a.sort_order-b.sort_order||b.id-a.id);
    if(mode==="oldest")matching.reverse();
    if(mode==="largest")matching.sort((a,b)=>bytes(b)-bytes(a)||b.id-a.id);
    const published=artworkRecords.filter(w=>w.visibility==="published").sort((a,b)=>Number(b.featured)-Number(a.featured)||a.sort_order-b.sort_order||b.id-a.id);
    const pages=Math.max(1,Math.ceil(matching.length/60));artworkPage=Math.max(1,Math.min(pages,artworkPage));
    const visible=matching.slice((artworkPage-1)*60,artworkPage*60);
    $("artwork-page").textContent="Page "+artworkPage+" of "+pages;
    $("artwork-prev").disabled=artworkPage===1;
    $("artwork-next").disabled=artworkPage===pages;
    $("artwork-select-all").checked=false;
    $("artwork-visible-count").textContent = "Showing " + matching.length + " of " +
      artworkRecords.length + " drawings · " +
      artworkRecords.filter(work => work.visibility === "draft").length + " drafts · " +
      artworkRecords.filter(work => work.visibility === "published").length + " published";
    if (!visible.length) {
      empty(target, "No artworks match this view.");
      $("artwork-select-all").checked = false;
      artworkControls();
      return;
    }
    for (const work of visible) {
      const card = document.createElement("article");
      card.className = "artwork-library-card";
      const preview = document.createElement("img");
      // All URLs are built from the integer ID returned by the admin API.
      if (Number.isSafeInteger(work.id) && work.id > 0) {
        preview.src = "../api/admin/artwork-preview.php?id=" + work.id + "&v=thumb";
      }
      preview.loading = "lazy";
      preview.decoding = "async";
      preview.alt = "Private preview of uploaded drawing " + work.id;
      preview.addEventListener("error",()=>{
        preview.alt="Preview unavailable for drawing "+work.id+"; use the full-image link to retry.";
        preview.src="../assets/artwork-unavailable.svg";
      },{once:true});
      const body = document.createElement("div");
      body.className = "artwork-card-info";
      const title = document.createElement("strong");
      title.textContent = "Drawing #" + work.id;
      const state = document.createElement("small");
      state.textContent = work.visibility === "published" ? "PUBLIC GALLERY" : "PRIVATE DRAFT";
      const dimensions=textNode("small",shape(work)!=="unknown"?work.width+" × "+work.height+" px · "+shape(work):"Image dimensions unavailable");
      if(bytes(work)>=0)dimensions.textContent+=" · "+(bytes(work)/1024).toFixed(1)+" KiB optimized";
      if(work.visibility==="published"&&typeof work.published_at==="string")dimensions.textContent+=" · Published "+work.published_at.slice(0,10);
      const inspect=textNode("a","Inspect private full image","small");
      inspect.href="../api/admin/artwork-preview.php?id="+work.id+"&v=display";
      inspect.target="_blank";inspect.rel="noopener noreferrer";
      const select = document.createElement("input");
      select.type = "checkbox";
      select.className = "artwork-select";
      select.value = String(work.id);
      select.disabled = !artworkWriteEnabled || artworkBusy;
      select.dataset.visibility = work.visibility === "published" ? "published" : "draft";
      select.dataset.featured=String(work.featured===true);
      select.setAttribute("aria-label", "Select drawing " + work.id + " for bulk actions");
      select.addEventListener("change", artworkControls);
      const pick = document.createElement("label");
      pick.className = "artwork-pick";
      pick.append(select,document.createTextNode(" Select for bulk actions"));
      const actions = document.createElement("div");
      actions.className = "artwork-card-actions";
      const toggle = document.createElement("button");
      toggle.className = "quiet";
      toggle.type = "button";
      toggle.textContent = work.visibility === "published" ? "Unpublish" : "Publish";
      toggle.disabled = !artworkWriteEnabled || artworkBusy;
      toggle.addEventListener("click", () => updateArtwork(work.id,"set_visibility",{
        visibility:work.visibility === "published" ? "draft" : "published",expected:work.visibility,
      }));
      const feature = document.createElement("button");
      feature.className = "quiet";
      feature.type = "button";
      feature.textContent = work.featured ? "★ Featured" : "☆ Feature";
      feature.disabled = !artworkWriteEnabled || artworkBusy;
      feature.addEventListener("click", () => updateArtwork(work.id,"set_featured",{
        featured:!work.featured,expected:work.featured===true,
      }));
      actions.append(toggle,feature);
      if(work.visibility==="published"&&mode==="gallery"){
        const position=published.findIndex(w=>w.id===work.id);
        for(const [direction,step,label] of [["earlier",-1,"Move earlier"],["later",1,"Move later"]]){
          const neighbor=published[position+step];
          if(!neighbor||neighbor.featured!==work.featured)continue;
          const button=textNode("button",label,"quiet");button.type="button";
          button.addEventListener("click",()=>moveArtwork(work,neighbor,direction));actions.append(button);
        }
      }
      const order = document.createElement("div");
      order.className = "artwork-sort";
      const input = document.createElement("input");
      input.type = "number";
      input.min = "-10000";
      input.max = "10000";
      input.step = "1";
      artworkDraftInput(work,"sort_order",input);
      input.disabled = !artworkWriteEnabled;
      input.setAttribute("aria-label", "Sort order for drawing " + work.id);
      const save = document.createElement("button");
      save.type = "button";
      save.className = "quiet";
      save.textContent = "Set order";
      save.disabled = !artworkWriteEnabled || artworkBusy;
      save.addEventListener("click", () => {
        const value = Number(input.value);
        if (input.value.trim()==="" || !Number.isInteger(value) || value < -10000 || value > 10000) {
          artworkState("Sort order must be a whole number between -10000 and 10000.");
          return;
        }
        if(artworkDraftCanSave(work,"sort_order"))updateArtwork(work.id,"set_order",{sort_order:value,expected:work.sort_order});
      });
      order.append(input,save);
      const accessibility=document.createElement("details");
      const summary=textNode("summary","Optional accessibility description");
      const alt=document.createElement("input");alt.type="text";alt.maxLength=240;artworkDraftInput(work,"alt",alt);
      if(artworkDrafts.has(artworkDraftKey(work.id,"alt")))accessibility.open=true;
      alt.setAttribute("aria-label","Accessibility description for drawing "+work.id);
      alt.placeholder="Describe what is visible (optional)";
      const saveAlt=textNode("button","Save description","quiet");saveAlt.type="button";
      saveAlt.addEventListener("click",()=>{if(artworkDraftCanSave(work,"alt"))updateArtwork(work.id,"set_alt",{alt:alt.value.trim(),expected:work.alt||""});});
      accessibility.append(summary,alt,saveAlt);
      const discardDrawing=textNode("button","Discard this drawing’s unsaved edits","quiet");discardDrawing.type="button";
      discardDrawing.dataset.artworkDiscard=String(work.id);
      discardDrawing.addEventListener("click",()=>{
        if(artworkBusy||artworkLoading||!["sort_order","alt"].some(field=>artworkDrafts.has(artworkDraftKey(work.id,field))))return;
        if(!window.confirm("Discard unsaved metadata edits for drawing #"+work.id+" only? Saved values and publication stay unchanged."))return;
        for(const field of ["sort_order","alt"])artworkDrafts.delete(artworkDraftKey(work.id,field));
        renderArtwork();artworkState("Unsaved edits discarded for drawing #"+work.id+".");
      });
      if(work.visibility==="published"){
        const link=textNode("a","View public drawing","small");link.href="../artwork.php?id="+work.id;link.target="_blank";link.rel="noopener noreferrer";body.append(title,state,dimensions,inspect,link,pick,actions,order,accessibility);
      }else body.append(title,state,dimensions,inspect,pick,actions,order,accessibility);
      body.append(discardDrawing);
      card.append(preview,body);
      target.append(card);
    }
    artworkControls();
  }

  async function artworksLoad() {
    const generation=++artworkGeneration;
    artworkLoading=true;artworkWriteEnabled=false;artworkControls();
    artworkState("Loading real artwork from the database…");
    try{
      const {response,data}=await get("artworks.php");
      if(generation!==artworkGeneration||leavingSession)return false;
      if(response.status===401){sessionExpired();return false;}
      if(!response.ok||!data.ok||!Array.isArray(data.artworks))throw Error("unavailable");
      artworkRecords=data.artworks;
      // Reconcile an already-applied edit after an interrupted response.
      for(const [key,draft] of artworkDrafts){
        const [id,field]=key.split(":"),work=artworkRecords.find(w=>w.id===Number(id));
        if(!work)continue;
        const applied=field==="sort_order"?draft.value.trim()!==""&&Number.isInteger(Number(draft.value))&&Number(draft.value)===work.sort_order:draft.value.trim()===String(work.alt??"");
        if(applied)artworkDrafts.delete(key);
      }
      artworkWriteEnabled=data.write_enabled===true&&data.truncated!==true;
      renderArtwork();
      artworkState(artworkRecords.length+" artwork entries · "+(data.truncated===true?"Library exceeds supported limit; writes locked":artworkWriteEnabled?"Uploads and publishing available":"Uploads locked until private server setup is complete"));
      return true;
    }catch(_){
      if(generation===artworkGeneration&&!leavingSession)artworkState("Artwork library unavailable. Reload before editing.");
      return false;
    }finally{if(generation===artworkGeneration){artworkLoading=false;artworkControls();}}
  }

  async function moveArtwork(work,neighbor,direction){
    if(!artworkWriteEnabled||artworkBusy||artworkLoading)return;
    if([...artworkDrafts.keys()].some(k=>k.endsWith(":sort_order"))){artworkState("Save or discard unsaved sort orders before moving drawings.");return;}
    if(!window.confirm("Move drawing #"+work.id+" "+direction+" in the public gallery, relative to drawing #"+neighbor.id+"? Equal sort values may be renumbered within this featured group."))return;
    artworkBusy=true;artworkControls();
    try{
      const {response,data}=await post("artworks.php",{action:"move_published",id:work.id,neighbor_id:neighbor.id,direction,expected_order:work.sort_order,expected_neighbor_order:neighbor.sort_order,expected_featured:work.featured===true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){artworkWriteEnabled=false;artworkState("Move not confirmed. Refresh to review the latest gallery order.");return;}
      if(await artworksLoad())artworkState("Drawing #"+work.id+" moved "+direction+" in the public gallery.");
    }catch(_){artworkWriteEnabled=false;artworkState("Move outcome uncertain. Refresh before retrying.");}
    finally{artworkBusy=false;if(!leavingSession)artworkControls();}
  }

  async function updateArtwork(id, action, payload) {
    if (!artworkWriteEnabled || artworkBusy) return;
    if(action==="set_visibility"&&!window.confirm(payload.visibility==="published"?"Publish this drawing to the public portfolio?":"Remove this drawing from the public portfolio? The private artwork remains saved."))return;
    artworkBusy = true;
    artworkControls();
    try {
      const {response,data} = await post("artworks.php",{
        id,action,...payload,
      },csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        if(response.status===409&&data.error==="artwork_field_changed"){
          artworkWriteEnabled=false;
          artworkState("Change not saved: another session updated this field. Refresh the library to review the conflict; unsaved values are retained.");
        }else artworkState("Change not saved: " + (data.error || "HTTP " + response.status));
        return;
      }
      if(action==="set_order"||action==="set_alt")artworkDrafts.delete(artworkDraftKey(id,action==="set_order"?"sort_order":"alt"));
      if(await artworksLoad())artworkState("Artwork #" + id + " updated.");
    } catch (_) {
      artworkWriteEnabled=false;artworkState("Connection failed. Change not confirmed; reload before editing.");
    } finally {
      artworkBusy = false;
      if(!leavingSession)artworkControls();
    }
  }

  $("artwork-refresh").addEventListener("click", () => {
    if(!artworkBusy)artworksLoad();
  });

  $("artwork-search").addEventListener("input",()=>{artworkPage=1;renderArtwork();});
  $("artwork-sort-mode").addEventListener("change",()=>{artworkPage=1;renderArtwork();});
  $("artwork-orientation").addEventListener("change",()=>{artworkPage=1;renderArtwork();});
  $("artwork-prev").addEventListener("click",()=>{artworkPage--;renderArtwork();});
  $("artwork-next").addEventListener("click",()=>{artworkPage++;renderArtwork();});
  $("artwork-filter").addEventListener("change", () => {
    artworkPage=1;
    $("artwork-select-all").checked = false;
    renderArtwork();
  });

  $("artwork-select-all").addEventListener("change", event => {
    document.querySelectorAll("#artwork-list .artwork-select").forEach(input => {
      if (!input.disabled) input.checked = event.target.checked;
    });
    artworkControls();
  });

  async function batchArtwork(visibility){
    if(!artworkWriteEnabled||artworkBusy||artworkLoading)return;
    const prior=visibility==="published"?"draft":"published";
    const ids=[...$("artwork-list").querySelectorAll(".artwork-select:checked")].filter(x=>x.dataset.visibility===prior).map(x=>Number(x.value)).filter(x=>Number.isSafeInteger(x)&&x>0);
    if(!ids.length||ids.length>60)return;
    const message=visibility==="published"?"Publish "+ids.length+" selected drawings to the public portfolio?":
      "Remove "+ids.length+" selected drawings from the public gallery? Images stay in your private library and can be republished later.";
    if(!window.confirm(message))return;
    artworkBusy=true;artworkControls();artworkState("Validating selected drawings…");
    try{
      const {response,data}=await post("artworks.php",{action:"batch_visibility",ids,visibility,confirm:true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||data.updated!==ids.length){
        artworkWriteEnabled=false;
        artworkState("Batch not confirmed. Reload before editing; no partial batch is applied by the server.");
        return;
      }
      if(await artworksLoad())artworkState((visibility==="published"?"Published ":"Unpublished ")+data.updated+" drawings together. No files deleted.");
    }catch(_){artworkWriteEnabled=false;artworkState("Batch outcome uncertain. Reload before retrying.");}
    finally{artworkBusy=false;if(!leavingSession)artworkControls();}
  }
  $("artwork-publish-selected").addEventListener("click",()=>batchArtwork("published"));
  $("artwork-unpublish-selected").addEventListener("click",()=>batchArtwork("draft"));

  async function batchArtworkFeatured(featured){
    if(!artworkWriteEnabled||artworkBusy||artworkLoading)return;
    const ids=[...$("artwork-list").querySelectorAll(".artwork-select:checked")].filter(x=>x.dataset.featured===String(!featured)).map(x=>Number(x.value)).filter(x=>Number.isSafeInteger(x)&&x>0);
    if(!ids.length||ids.length>60)return;
    if(!window.confirm((featured?"Feature ":"Remove featured status from ")+ids.length+" selected drawings? Private drafts remain private."))return;
    artworkBusy=true;artworkControls();
    try{
      const {response,data}=await post("artworks.php",{action:"batch_featured",ids,featured,confirm:true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||data.updated!==ids.length){artworkWriteEnabled=false;artworkState("Featured batch not confirmed. Refresh before editing.");return;}
      if(await artworksLoad())artworkState((featured?"Featured ":"Removed featured status from ")+ids.length+" drawings. Publication unchanged.");
    }catch(_){artworkWriteEnabled=false;artworkState("Featured batch outcome uncertain. Refresh before retrying.");}
    finally{artworkBusy=false;if(!leavingSession)artworkControls();}
  }
  $("artwork-feature-selected").addEventListener("click",()=>batchArtworkFeatured(true));
  $("artwork-unfeature-selected").addEventListener("click",()=>batchArtworkFeatured(false));

  $("artwork-files").addEventListener("change",()=>{
    if(artworkPendingFiles.length&&!window.confirm("Replace the remaining upload selection with these new files?")){$("artwork-files").value="";return;}
    artworkPendingFiles=[];artworkUploadTotals=null;
    $("artwork-upload-results").replaceChildren();$("artwork-progress").hidden=true;
    artworkControls();
    $("artwork-upload-message").textContent=$("artwork-files").files.length+" file(s) selected. Uploads remain private drafts.";
  });
  $("artwork-upload-stop").addEventListener("click",()=>{
    if(!artworkUploadRunning)return;
    artworkStopRequested=true;artworkControls();
    $("artwork-upload-message").textContent="Stopping after the current file. Unattempted files will be retained.";
  });
  $("artwork-upload-discard").addEventListener("click",()=>{
    if(artworkBusy||artworkLoading||!artworkPendingFiles.length)return;
    if(!window.confirm("Discard "+artworkPendingFiles.length+" remaining selected file(s)? An interrupted upload may already have been saved; review the library. Previously saved drawings remain."))return;
    artworkPendingFiles=[];artworkUploadTotals=null;
    $("artwork-progress").hidden=true;$("artwork-upload-message").textContent="Remaining selection discarded. Previously saved drawings remain.";
    artworkControls();
  });
  $("artwork-upload").addEventListener("click",()=>uploadArtworkFiles([...$("artwork-files").files],false));
  $("artwork-upload-resume").addEventListener("click",()=>uploadArtworkFiles([...artworkPendingFiles],true));
  async function uploadArtworkFiles(selected,resuming=false){
    if(!artworkWriteEnabled||artworkBusy||artworkLoading)return;
    if(!selected.length){
      $("artwork-upload-message").textContent="Select artwork images first.";
      return;
    }
    artworkBusy=true;artworkUploadRunning=true;artworkStopRequested=false;artworkPendingFiles=[];
    artworkControls();
    const progress=$("artwork-progress"),report=$("artwork-upload-results");
    if(!resuming||!artworkUploadTotals){
      artworkUploadTotals={total:selected.length,processed:0,saved:0,duplicates:0,rejected:0};
      report.replaceChildren();
    }
    const totals=artworkUploadTotals,offset=totals.processed;
    progress.hidden=false;progress.max=totals.total;progress.value=offset;
    let success=0,duplicates=0,rejected=0,uncertain=0,attempted=0,stopReason="";
    for(const file of selected)if(!artworkUploadPreferences.has(file))artworkUploadPreferences.set(file,$("artwork-skip-identical").checked);
    for(let i=0;i<selected.length;i++){
      if(leavingSession||artworkStopRequested){stopReason="Upload stopped.";break;}
      const file=selected[i],entry=document.createElement("li");
      entry.textContent=(offset+i+1)+". "+file.name+" — ";report.append(entry);
      const acceptedType=["image/jpeg","image/png","image/webp"].includes(file.type)||
        (!file.type&&/\.(jpe?g|png|webp)$/i.test(file.name));
      if(!acceptedType||file.size>8*1024*1024||file.size<1){
        rejected++;entry.textContent+="Skipped (unsupported format or file too large).";
      }else{
        try{
          if(!artworkUploadKeys.has(file))artworkUploadKeys.set(file,crypto.randomUUID());
          const body=new FormData();body.append("artwork",file);body.append("operation_key",artworkUploadKeys.get(file));body.append("skip_identical",artworkUploadPreferences.get(file)?"1":"0");
          const response=await fetch(api+"artwork-upload.php",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"X-DMO-CSRF":csrf},body});
          if(response.status===401){sessionExpired();return;}
          const data=await response.json();
          if(response.ok&&data.ok===true&&Number.isSafeInteger(data.artwork_id)&&data.artwork_id>0){
            if(data.identical_skipped===true){duplicates++;entry.textContent+="Identical file skipped — existing drawing #"+data.artwork_id+" ("+data.visibility+").";}
            else{success++;entry.textContent+=data.already_saved?"Existing drawing #"+data.artwork_id+" recovered ("+data.visibility+").":"Saved privately as draft #"+data.artwork_id;}
          }else if((response.status===409&&data.error==="artwork_upload_busy")||response.status===429||response.status===403||data.error==="artwork_library_limit"||data.error==="artwork_upload_disabled"){
            entry.textContent+="Paused by server ("+(data.error||response.status)+"). File retained.";
            stopReason="Uploads paused by server. Refresh or resolve the restriction before resuming.";
            break;
          }else if(response.status>=400&&response.status<500&&data.ok===false){
            rejected++;entry.textContent+="Rejected ("+(data.error||response.status)+")";
          }else{
            uncertain++;entry.textContent+="Upload outcome not confirmed. Resume uses the same upload receipt.";
            stopReason="Upload interrupted.";
          }
        }catch(_){
          uncertain++;entry.textContent+="Upload outcome not confirmed. Resume uses the same upload receipt.";
          stopReason="Upload interrupted.";
        }
      }
      if(uncertain)break;
      attempted=i+1;progress.value=offset+attempted;
      $("artwork-upload-message").textContent="Processed "+(offset+attempted)+" of "+totals.total+" images.";
      if(stopReason)break;
    }
    if(leavingSession)return;
    if(artworkStopRequested&&!stopReason&&attempted<selected.length)stopReason="Upload stopped.";
    // Keep an ambiguous file with its original operation key; resume is explicit.
    // The server returns the original receipt if it already committed.
    artworkPendingFiles=selected.slice(attempted);
    totals.processed+=attempted;totals.saved+=success;totals.duplicates+=duplicates;totals.rejected+=rejected;
    artworkUploadRunning=false;artworkStopRequested=false;
    $("artwork-files").value="";
    const refreshed=await artworksLoad();
    artworkBusy=false;artworkControls();
    if(leavingSession)return;
    $("artwork-upload-message").textContent=(stopReason?stopReason+" ":"")+totals.saved+" saved or recovered · "+totals.duplicates+" identical skipped · "+totals.rejected+" rejected/skipped · "+uncertain+" unconfirmed · "+artworkPendingFiles.length+" remaining."+
      (uncertain?" Resume to recover the unconfirmed file using the same upload ID.":"")+
      (refreshed?"":" Library refresh failed; refresh before continuing.");
  }

  // Administrative SMTP readiness, without showing secrets or permitting
  // arbitrary email recipients. Tests go to the authenticated admin only.
  let mailReady = false;
  let mailBusy = false;

  async function emailDiagnosticsLoad() {
    $("mail-check-status").textContent = "Checking transactional mail settings…";
    const {response, data} = await get("email.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) {
      $("mail-check-status").textContent =
        "Mail configuration unavailable (HTTP " + response.status + ").";
      $("mail-check-send").disabled = true;
      return;
    }
    mailReady = data.mail_ready === true;
    const transport = data.transport === "smtp" ? "TLS SMTP" :
      data.transport === "php" ? "PHP mail() fallback" : "Not configured";
    $("mail-check-status").textContent = mailReady
      ? "Transactional mail configured (" + transport + ")."
      : "Email transport not ready. Customer registration remains closed.";
    $("mail-check-detail").textContent =
      "SMTP settings complete: " + (data.smtp_settings_present ? "yes" : "no") +
      " · Customer login: " + (data.customer_login_enabled ? "enabled" : "disabled") +
      " · Customer registration: " +
      (data.customer_registration_enabled ? "enabled" : "disabled") +
      " · Test recipient: your registered administrator email";
    $("mail-check-send").disabled = !mailReady || mailBusy;
  }

  $("mail-check-refresh").addEventListener("click", () => {
    emailDiagnosticsLoad().catch(() => {
      $("mail-check-status").textContent = "Unable to check mail settings.";
    });
  });

  $("mail-check-send").addEventListener("click", async () => {
    if (!mailReady || mailBusy) return;
    if (!window.confirm(
      "Send one transactional mail test to this admin account's email? " +
      "A maximum of two tests per hour is allowed."
    )) return;
    mailBusy = true;
    $("mail-check-send").disabled = true;
    $("mail-test-feedback").textContent = "Requesting test message…";
    try {
      const {response,data} = await post("email.php",{action:"send_test"},csrf);
      if (response.status === 401) return sessionExpired();
      if (response.ok && data.ok) {
        $("mail-test-feedback").textContent =
          "SMTP accepted reference " + data.test_reference +
          ". Verify arrival in your inbox or spam folder. " +
          "Sending a test does not enable customer registration.";
      } else {
        const errors = {
          mail_not_configured:"The SMTP mail transport is not configured.",
          test_limit_reached:"Maximum two mail tests per hour; try again later.",
          mail_transport_failed:"The mail server rejected the message or a network error occurred.",
          csrf_failed:"Session security check failed. Refresh the admin page.",
        };
        $("mail-test-feedback").textContent = errors[data.error] ||
          "Mail test failed (HTTP " + response.status + ").";
      }
    } catch (_) {
      $("mail-test-feedback").textContent =
        "A network error occurred. Email delivery is not confirmed.";
    } finally {
      mailBusy = false;
      $("mail-check-send").disabled = !mailReady;
    }
  });

  let designDraftVersion = 0;
  let designWriteEnabled = false;
  let designBusy=false,designGeneration=0,designHistory=[],designSaved=null,designLoaded=false;
  const designIds = {
    site_style: "design-site",
    color_theme: "design-color",
    gallery_layout: "design-layout",
    default_mode: "design-mode",
    logo: "design-logo",
  };

  function designValues() {
    const values = {};
    for (const [key, id] of Object.entries(designIds)) {
      values[key] = $(id).value;
    }
    return values;
  }

  function designDisplay(data) {
    designDraftVersion = Number.isSafeInteger(data.draft?.version)
      ? data.draft.version : 0;
    designWriteEnabled = data.write_enabled === true;
    const snapshot = (data.draft?.exists ? data.draft.settings : data.published?.settings) || {};
    for (const [key, id] of Object.entries(designIds)) {
      if (typeof snapshot[key] === "string" &&
          Array.from($(id).options).some(option => option.value === snapshot[key])) {
        $(id).value = snapshot[key];
      }
    }
    designSaved=designValues();designLoaded=true;
    const version = data.published?.exists
      ? "Published v" + data.published.version : "Not published yet";
    $("design-state").textContent = "Draft v" + designDraftVersion +
      " · " + version + " · " +
      (designWriteEnabled ? "Editing available" : "Editing locked until setup is complete")+
      (data.presentation==="published"?" · Public presentation":" · Development preview");
    designHistory=Array.isArray(data.history)?data.history.filter(x=>Number.isSafeInteger(x.id)&&x.id>0&&Number.isSafeInteger(x.version)&&x.version>0):[];
    const history=$("design-history");
    history.replaceChildren(new Option(designHistory.length?"Choose a previous version":"No previous versions",""));
    for(const item of designHistory)history.append(new Option("Published v"+item.version,String(item.id)));
    syncDesignControls();
  }

  function designDirty(){return designSaved!==null&&Object.keys(designIds).some(key=>designValues()[key]!==designSaved[key]);}
  function syncDesignControls(){
    const locked=designBusy||!designWriteEnabled;
    const dirty=designDirty();
    $("design-unsaved").hidden=!dirty;
    for(const id of Object.values(designIds))$(id).disabled=locked;
    $("design-save").disabled=locked;
    $("design-publish").disabled=locked||designDraftVersion===0||dirty;
    $("design-history").disabled=locked||designHistory.length===0;
    $("design-restore").disabled=locked||!designHistory.some(x=>String(x.id)===$("design-history").value);
    $("design-reload").disabled=designBusy;
  }
  async function designLoad(){
    const generation=++designGeneration;designLoaded=false;
    designWriteEnabled=false;syncDesignControls();
    $("design-state").textContent="Loading draft and published settings…";
    try{
      const {response,data}=await get("design.php");
      if(generation!==designGeneration||leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok)throw Error("unavailable");
      designDisplay(data);
    }catch(_){
      if(generation===designGeneration&&!leavingSession)$("design-state").textContent="Settings are unavailable. Reload before editing.";
    }
  }
  async function designAction(action){
    if(designBusy||!designWriteEnabled||!csrf||(action==="publish"&&designDirty()))return;
    const body={action,expected_version:designDraftVersion};
    if(action==="save_draft")body.settings=designValues();
    if(action==="restore_draft"){
      const snapshot=designHistory.find(x=>String(x.id)===$("design-history").value);
      if(!snapshot)return;
      body.snapshot_id=snapshot.id;
    }
    designBusy=true;syncDesignControls();
    $("design-state").textContent="Saving design settings…";
    try{
      const {response,data}=await post("design.php",body,csrf);
      if(leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){
        designWriteEnabled=false;
        $("design-state").textContent=response.status===409?"This draft changed in another tab. Reload before editing.":"Change not confirmed. Reload before editing.";
        return;
      }
      await designLoad();
    }catch(_){
      designWriteEnabled=false;
      $("design-state").textContent="Connection failed. Reload before editing.";
    }finally{
      designBusy=false;syncDesignControls();
    }
  }
  $("design-form").addEventListener("submit",event=>{event.preventDefault();designAction("save_draft");});
  for(const id of Object.values(designIds))$(id).addEventListener("change",syncDesignControls);
  $("design-reload").addEventListener("click",()=>{if(!designBusy&&(!designDirty()||window.confirm("Discard unsaved appearance changes and reload?")))designLoad();});
  $("design-history").addEventListener("change",syncDesignControls);
  $("design-publish").addEventListener("click",()=>{
    if(!designBusy&&designWriteEnabled&&!designDirty()&&window.confirm("Publish this saved design draft?"))designAction("publish");
  });
  $("design-restore").addEventListener("click",()=>{
    if(!designBusy&&designWriteEnabled&&window.confirm("Replace your saved draft"+(designDirty()?" and discard unsaved changes":"")+" with this previous design? The public design will not change."))designAction("restore_draft");
  });

  // Commission catalog editor. Displayed amounts are dollars, but the API
  // always receives exact integer minor units; never use floating arithmetic.
  let packageCatalog = { tiers: [], addons: [] };
  let packageSelected = { kind: "tier", id: null };
  let packageWrite = false;
  let packagePublish = false;
  let packageBusy = false;
  let packageGeneration=0,packageSaved=null,packageLoaded=false,packageLoading=false;
  const packageFieldIds=['package-slug','package-name','package-description','package-currency','package-order','package-min','package-max','package-features'];
  function packageSnapshot(){return {fields:JSON.stringify(packageFieldIds.map(id=>$(id).value)),compat:JSON.stringify([$('package-compatibility-mode').value,[...$('package-compatibility-options').querySelectorAll('input:checked')].map(x=>x.value)])};}
  function packageDirty(){const current=packageSnapshot();return {fields:!!packageSaved&&current.fields!==packageSaved.fields,compat:!!packageSaved&&current.compat!==packageSaved.compat};}
  function packageHasChanges(){const dirty=packageDirty();return dirty.fields||dirty.compat;}
  function packageControls(){
    const row=packageRows(packageSelected.kind).find(r=>r.id===packageSelected.id),dirty=packageDirty(),locked=packageBusy||packageLoading||!packageWrite||(row?.is_active===true&&!packagePublish);
    for(const id of packageFieldIds)$(id).disabled=locked||dirty.compat;
    $('package-save').disabled=locked||dirty.compat;
    $('package-visibility').disabled=packageBusy||packageLoading||!packagePublish||!row||dirty.fields||dirty.compat||(packageSelected.kind==='tier'&&!row.is_active&&row.compatibility?.editable!==true);
    $('package-refresh').disabled=packageBusy||packageLoading;
    $('package-unsaved').hidden=!dirty.fields&&!dirty.compat;
    $('package-unsaved').textContent=dirty.fields?'Unsaved package changes. Save before publishing or editing extras.':'Unsaved extra availability. Save before publishing or editing package details.';
    $('package-revert').disabled=packageBusy||packageLoading||!packageHasChanges();
    $('package-duplicate').disabled=packageBusy||packageLoading||!packageWrite||!row||packageHasChanges();
    packageCompatibilityControls();renderPackagePreview();
  }

  function packageRows(kind) {
    return kind === "tier" ? packageCatalog.tiers : packageCatalog.addons;
  }

  function moneyText(value) {
    if (!Number.isSafeInteger(value) || value < 0) return "";
    return Math.floor(value / 100) + "." + String(value % 100).padStart(2, "0");
  }

  function parsePrice(text) {
    const raw = text.trim();
    if (raw === "") return null;
    if (!/^\d{1,6}(?:\.\d{1,2})?$/.test(raw)) return false;
    const [dollars, cents = ""] = raw.split(".");
    const value = Number(dollars) * 100 + Number(cents.padEnd(2, "0"));
    return Number.isSafeInteger(value) && value <= 10000000 ? value : false;
  }

  function packageShow(message, error = false) {
    const banner = $("package-message");
    banner.textContent = message;
    banner.dataset.error = String(error);
  }

  function renderPackageLists() {
    const lists = [
      { kind:"tier", id:"package-tier-list" },
      { kind:"addon", id:"package-addon-list" },
    ];
    for (const list of lists) {
      const target = $(list.id);
      target.replaceChildren();
      const records = packageRows(list.kind);
      if (records.length === 0) {
        const note = document.createElement("p");
        note.className = "small";
        note.textContent = "No items created.";
        target.appendChild(note);
      }
      for (const record of records) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "package-list-item";
        if (packageSelected.kind === list.kind && packageSelected.id === record.id) {
          button.classList.add("selected");
        }
        const name = document.createElement("strong");
        name.textContent = record.name || record.slug;
        const status = document.createElement("small");
        status.textContent = record.is_active
          ? "PUBLIC" : "UNPUBLISHED";
        button.append(name,status);
        button.addEventListener("click", () => packageSelect(list.kind, record.id));
        target.appendChild(button);
      }
    }
  }

  function packageCompatibilityControls(){
    const record=packageSelected.kind==="tier"?packageRows("tier").find(row=>row.id===packageSelected.id):null;
    const state=record?.compatibility;
    const enabled=!!state&&state.editable===true&&packageWrite&&!packageBusy&&!packageLoading&&!packageDirty().fields&&(!record.is_active||packagePublish);
    const selected=$("package-compatibility-mode").value==="selected";
    $("package-compatibility-mode").disabled=!enabled;
    $("package-compatibility-options").querySelectorAll("input").forEach(input=>input.disabled=!enabled||!selected);
    $("package-compatibility-save").disabled=!enabled||(selected&&packageRows("addon").length===0);
  }
  function renderPackageCompatibility(record,kind){
    const panel=$("package-compatibility"),options=$("package-compatibility-options"),status=$("package-compatibility-status");
    panel.hidden=kind!=="tier"||!record;
    options.replaceChildren();status.textContent="";
    if(panel.hidden)return;
    const state=record.compatibility;
    $("package-compatibility-mode").value=state?.mode==="selected"?"selected":"all";
    const allowed=new Set(Array.isArray(state?.allowed_addon_ids)?state.allowed_addon_ids:[]);
    for(const addon of packageRows("addon")){
      const label=document.createElement("label"),input=document.createElement("input");
      input.type="checkbox";input.value=String(addon.id);input.checked=allowed.has(addon.id);
      label.append(input,document.createTextNode(addon.name+(addon.is_active?"":" (unpublished)")));options.append(label);
    }
    if(!options.childElementCount)options.append(textNode("p","Create extras before restricting a package.","small"));
    if(state?.editable!==true)status.textContent="Compatibility editing unavailable for this inventory.";
    else status.textContent=record.is_active?"Changes affect this published package after confirmation.":"Draft package; changes are not public until publication.";
    packageCompatibilityControls();
  }

  function packageSelect(kind, id = null, force=false) {
    if (packageBusy||packageLoading||!["tier", "addon"].includes(kind)) return;
    if(!force&&packageHasChanges()&&!window.confirm("Discard unsaved package or extra changes?"))return;
    const record = id === null ? null : packageRows(kind).find(row => row.id === id);
    if (id !== null && !record) return;
    packageSelected = { kind, id };
    const blank = {
      slug:"",name:"",description:"",currency:"CAD",
      estimate_min_minor:null,estimate_max_minor:null,sort_order:0,features:[],
      is_active:false,
    };
    const item = record || blank;
    $("package-kind-label").textContent =
      kind === "tier" ? "COMMISSION TIER" : "OPTIONAL ADD-ON";
    $("package-edit-title").textContent = record
      ? "Editing " + record.name
      : (kind === "tier" ? "New commission tier" : "New optional add-on");
    $("package-slug").value = item.slug || "";
    $("package-name").value = item.name || "";
    $("package-description").value = item.description || "";
    $("package-currency").value = item.currency === "USD" ? "USD" : "CAD";
    $("package-order").value = Number.isInteger(item.sort_order)
      ? String(item.sort_order) : "0";
    $("package-min").value = moneyText(item.estimate_min_minor);
    $("package-max").value = moneyText(item.estimate_max_minor);
    $("package-feature-wrap").hidden = kind !== "tier";
    $("package-features").value = Array.isArray(item.features)
      ? item.features.join("\n") : "";
    $("package-editor-status").textContent = record
      ? (item.is_active ? "Published to public catalog" : "Not visible to customers")
      : "New item · not published";
    $("package-save").textContent = record?.is_active
      ? "Save live changes" : "Save unpublished";
    $("package-save").disabled = !packageWrite || packageBusy ||
      (record?.is_active === true && !packagePublish);
    $("package-visibility").textContent = record?.is_active
      ? "Unpublish" : "Publish";
    $("package-visibility").disabled = !packagePublish || packageBusy || !record || (kind==="tier"&&!record?.is_active&&record?.compatibility?.editable!==true);
    renderPackageCompatibility(record,kind);
    packageSaved=packageSnapshot();packageControls();
    renderPackageLists();
  }

  async function packagesLoad() {
    const generation=++packageGeneration;
    packageWrite=false;packagePublish=false;packageLoading=true;packageLoaded=false;packageControls();
    packageShow("Loading existing commission tiers and add-ons…");
    try{
      const { response, data } = await get("packages.php");
      if(generation!==packageGeneration||leavingSession)return false;
      if(response.status===401){sessionExpired();return false;}
      if(!response.ok||!data.ok||!Array.isArray(data.catalog?.tiers)||!Array.isArray(data.catalog?.addons))throw Error("unavailable");
      packageWrite=data.write_enabled===true;packagePublish=data.publish_enabled===true;
      packageCatalog=data.catalog;packageLoaded=true;
      if(!packageRows(packageSelected.kind).some(row=>row.id===packageSelected.id))packageSelected.id=null;
      packageLoading=false;
      if(!packageBusy)packageSelect(packageSelected.kind,packageSelected.id,true);
      packageShow(packageWrite?(packagePublish?"Editing and publication available. Confirm rates before publishing.":"Unpublished editing available. Public publishing remains locked."):"Editing locked until the private backend switch is enabled.");
      return true;
    }catch(_){if(generation===packageGeneration)packageShow("Unable to load commission packages. Refresh before editing.",true);return false;}
    finally{if(generation===packageGeneration){packageLoading=false;packageControls();}}
  }

  function renderPackagePreview(){
    const name=$("package-name").value.trim(),description=$("package-description").value.trim(),slug=$("package-slug").value.trim(),currency=$("package-currency").value;
    const low=parsePrice($("package-min").value),high=parsePrice($("package-max").value);
    const features=packageSelected.kind==="tier"?$("package-features").value.split("\n").map(x=>x.trim()).filter(Boolean):[];
    $("package-preview-name").textContent=name||"Unnamed package";
    const validPrice=Number.isSafeInteger(low)&&Number.isSafeInteger(high)&&low>=0&&high>=low&&high<=10000000;
    $("package-preview-price").textContent=validPrice?currency+" "+moneyText(low)+(low===high?"":" – "+moneyText(high))+" estimated":"Estimate not set or invalid";
    $("package-preview-description").textContent=description||"Description not yet entered.";
    const list=$("package-preview-features");list.replaceChildren();
    for(const feature of features.slice(0,20))list.append(textNode("li",feature));list.hidden=!list.childElementCount;
    const record=packageRows(packageSelected.kind).find(x=>x.id===packageSelected.id);
    const mode=$("package-compatibility-mode").value;
    const selected=new Set([...$("package-compatibility-options").querySelectorAll("input:checked")].map(x=>Number(x.value)));
    const extras=packageRows("addon").filter(x=>x.is_active&&(mode!=="selected"||selected.has(x.id)));
    $("package-preview-extras").textContent=packageSelected.kind!=="tier"?"":!record?"New tier: review extra availability after saving.":extras.length?"Published extras selected: "+extras.map(x=>x.name).join(", "):"No published extras selected.";
    const issues=[];if(!name)issues.push("name");if(!description)issues.push("description");if(!/^[a-z0-9][a-z0-9-]{1,79}$/.test(slug))issues.push("valid unique slug");if(!validPrice)issues.push("valid estimates");if(features.length>20||features.some(x=>x.length>180))issues.push("shorter feature list");
    $("package-preview-check").textContent=issues.length?"Before publication, complete: "+issues.join(", ")+".":"Basic fields complete. Server validation and explicit publication approval still required.";
  }
  $("package-duplicate").addEventListener("click",()=>{
    const record=packageRows(packageSelected.kind).find(x=>x.id===packageSelected.id);
    if(!record||!packageWrite||packageBusy||packageLoading||packageHasChanges())return;
    if(!window.confirm("Copy the saved package fields into a new unsaved draft? Its visibility and extra compatibility will not be copied. Review the unique slug and extras before saving or publishing."))return;
    const kind=packageSelected.kind;packageSelect(kind,null,true);
    $("package-name").value=(String(record.name||"Package").slice(0,113)+" (copy)").slice(0,120);
    $("package-slug").value="";$("package-description").value=record.description||"";
    $("package-currency").value=record.currency==="USD"?"USD":"CAD";
    $("package-min").value=moneyText(record.estimate_min_minor);$("package-max").value=moneyText(record.estimate_max_minor);
    $("package-order").value=String(record.sort_order||0);$("package-features").value=Array.isArray(record.features)?record.features.join("\n"):"";
    packageControls();packageShow("Copied into an unsaved draft. Choose a new slug. Extra availability must be reviewed separately.");
    $("package-slug").focus();
  });
  function packagePayload() {
    const name = $("package-name").value.trim();
    const slug = $("package-slug").value.trim();
    const low = parsePrice($("package-min").value);
    const high = parsePrice($("package-max").value);
    if (!name || !/^[a-z0-9][a-z0-9-]{1,79}$/.test(slug)) {
      packageShow("Enter a name and a lowercase slug, e.g. simple-sketch.", true);
      return null;
    }
    if (low === false || high === false || (low === null) !== (high === null) ||
        (low !== null && low > high)) {
      packageShow("Enter valid minimum and maximum estimates, or leave both blank.", true);
      return null;
    }
    const order = Number($("package-order").value);
    if (!Number.isSafeInteger(order) || order < -10000 || order > 10000) {
      packageShow("Sort order must be a whole number between -10000 and 10000.", true);
      return null;
    }
    const item = {
      id:packageSelected.id,
      slug,name,description:$("package-description").value.trim(),
      currency:$("package-currency").value,
      estimate_min_minor:low,estimate_max_minor:high,sort_order:order,
    };
    if (packageSelected.kind === "tier") {
      item.features = $("package-features").value.split("\n")
        .map(x => x.trim()).filter(Boolean);
      if (item.features.length > 20 || item.features.some(x => x.length > 180)) {
        packageShow("Use up to 20 short features, 180 characters each.", true);
        return null;
      }
    }
    return item;
  }

  $("package-new-tier").addEventListener("click", () => packageSelect("tier"));
  $("package-new-addon").addEventListener("click", () => packageSelect("addon"));
  $("package-refresh").addEventListener("click",()=>{if(!packageBusy&&!packageLoading&&(!packageHasChanges()||window.confirm("Discard unsaved package or extra changes and refresh?")))packagesLoad();});
  $("package-revert").addEventListener("click",()=>{if(window.confirm("Discard unsaved package or extra changes?"))packageSelect(packageSelected.kind,packageSelected.id,true);});
  for(const id of packageFieldIds)$(id).addEventListener("input",packageControls);
  $("package-compatibility-options").addEventListener("change",packageControls);
  $("package-form").addEventListener("submit", async event => {
    event.preventDefault();
    if (!packageWrite || packageBusy || packageLoading || packageDirty().compat) return;
    const item = packagePayload();
    if (!item) return;
    const current = packageRows(packageSelected.kind)
      .find(row => row.id === packageSelected.id);
    if (current?.is_active === true &&
        !window.confirm("This changes a published price or package immediately. Continue?")) {
      return;
    }
    let refreshed=false;
    packageBusy = true;packageControls();
    packageShow("Saving package without publishing…");
    try {
      const action = packageSelected.kind === "tier" ? "save_tier" : "save_addon";
      const { response, data } = await post("packages.php", {action,item,expected_revision:current?.revision??null}, csrf);
      if(response.status===401)return sessionExpired();
      if(response.status===409&&data.error==="stale_catalog_reload_required"){packageWrite=false;packagePublish=false;packageShow("This item changed. Reload before editing again.",true);return;}
      if (!response.ok || !data.ok) {
        packageShow(data.error === "duplicate_slug_or_conflict"
          ? "That slug is already used. Choose another one."
          : "Unable to save: " + (data.error || "server refused the change"), true);
        return;
      }
      packageSelected.id = data.id;
      refreshed=await packagesLoad();
      if(refreshed)packageShow("Saved. New items are unpublished until explicitly activated.");
    } catch (_) {
      packageWrite=false;packagePublish=false;packageShow("Connection problem. Save was not confirmed. Refresh before retrying.", true);
    } finally {
      packageBusy = false;
      if(!leavingSession){if(refreshed)packageSelect(packageSelected.kind,packageSelected.id,true);else packageControls();}
    }
  });

  $("package-visibility").addEventListener("click", async () => {
    if (!packagePublish || packageBusy || packageLoading || packageHasChanges() || packageSelected.id === null) return;
    const row = packageRows(packageSelected.kind)
      .find(x => x.id === packageSelected.id);
    if (!row) return;
    const active = !row.is_active;
    const name = row.name || row.slug;
    if (!window.confirm(active
      ? "Publish " + name + " to the public catalog? Confirm its prices are approved."
      : "Remove " + name + " from the public catalog?")) return;
    let refreshed=false;
    packageBusy = true;packageControls();
    try {
      const { response, data } = await post("packages.php", {
        action:"set_active",kind:packageSelected.kind,
        id:packageSelected.id,active,expected_revision:row.revision??null,
        expected_compatibility_revision:row.compatibility?.revision??null,
      },csrf);
      if(response.status===401)return sessionExpired();
      if(response.status===409&&data.error==="stale_catalog_reload_required"){packageWrite=false;packagePublish=false;packageShow("This item changed. Reload before editing again.",true);return;}
      if (!response.ok || !data.ok) {
        packageShow("Unable to change visibility: " +
          (data.error || "not approved"), true);
        return;
      }
      refreshed=await packagesLoad();
      if(refreshed)packageShow(active ? "Published to public catalog." : "Unpublished from catalog.");
    } catch (_) {
      packageWrite=false;packagePublish=false;packageShow("Connection problem. Visibility was not confirmed. Refresh before retrying.", true);
    } finally {
      packageBusy = false;
      if(!leavingSession){if(refreshed)packageSelect(packageSelected.kind,packageSelected.id,true);else packageControls();}
    }
  });

  $("package-compatibility-mode").addEventListener("change",packageControls);
  $("package-compatibility-save").addEventListener("click",async()=>{
    const record=packageSelected.kind==="tier"?packageRows("tier").find(row=>row.id===packageSelected.id):null;
    if(!record||packageBusy||packageLoading||packageDirty().fields||!packageWrite||!record.compatibility?.editable||(record.is_active&&!packagePublish))return;
    const mode=$("package-compatibility-mode").value;
    const ids=mode==="selected"?[...$("package-compatibility-options").querySelectorAll("input:checked")].map(input=>Number(input.value)):[];
    if(mode==="selected"&&!packageRows("addon").length)return;
    if(!window.confirm("Save extra availability for this package?"+(record.is_active?" This changes the public catalog immediately.":"")))return;
    let refreshed=false;packageBusy=true;packageControls();
    try{
      const {response,data}=await post("packages.php",{action:"set_compatibility",kind:"tier",id:record.id,mode,allowed_addon_ids:ids,
        expected_revision:record.revision,expected_compatibility_revision:record.compatibility.revision},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){packageWrite=false;packagePublish=false;packageShow("Change not confirmed. Reload before editing.",true);return;}
      refreshed=await packagesLoad();
      if(!leavingSession&&refreshed)packageShow("Extra availability saved.");
    }catch(_){packageWrite=false;packagePublish=false;packageShow("Connection failed. Reload before editing.",true);}
    finally{packageBusy=false;if(!leavingSession){if(refreshed)packageSelect(packageSelected.kind,packageSelected.id,true);else packageControls();}}
  });

  let customerGeneration=0,customerCursor=null,customerSearch="",customerState="all",customerHistory="all";
  function clearCustomers(){++customerGeneration;customerCursor=null;$("customer-directory-list").replaceChildren();$("customer-directory-next").hidden=true;}
  async function loadCustomers(older=false){
    if(leavingSession||!csrf||(older&&customerCursor===null))return;
    if(!older){clearCustomers();customerSearch=$("customer-query").value.trim();customerState=$("customer-state").value;customerHistory=$("customer-history").value;}
    const generation=++customerGeneration,list=$("customer-directory-list"),status=$("customer-directory-status"),next=$("customer-directory-next");
    next.disabled=true;status.textContent="Loading customer records…";
    try{
      const params=new URLSearchParams({q:customerSearch,state:customerState,history:customerHistory});if(older)params.set("before",String(customerCursor));
      const {response,data}=await get("customers.php?"+params);
      if(generation!==customerGeneration||leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||!Array.isArray(data.customers))throw Error("unavailable");
      for(const row of data.customers){
        if(!Number.isSafeInteger(row.id)||[...list.children].some(el=>el.dataset.customerId===String(row.id)))continue;
        const card=document.createElement("article");card.className="notification-card";card.dataset.customerId=String(row.id);
        card.append(textNode("strong",row.display_name||"Customer"),textNode("p",row.email||"","small"),textNode("p","Account #"+row.id+" · "+String(row.account_status).replaceAll("_"," ")+" · "+(row.email_verified?"Email verified":"Email not verified"),"small"),textNode("p",row.request_count+" commission request(s)"+(row.last_request_at?" · Latest: "+row.last_request_at:""),"small"));
        const btn=textNode("button","View commission history","quiet");btn.type="button";btn.disabled=row.request_count===0;
        btn.addEventListener("click",()=>{if(!setRequestCustomer(row.id))return;requestListPage=1;requestListQuery="";requestListFilter="all";$("request-list-query").value="";$("request-list-filter").value="all";resetRequestSearchOptions();syncScheduleFilter(true);selectPanel("requests");});card.append(btn);list.append(card);
      }
      const previous=customerCursor;
      customerCursor=Number.isSafeInteger(data.next_before)&&data.next_before>0&&(!older||data.next_before<previous)?data.next_before:null;next.hidden=customerCursor===null;
      status.textContent=list.children.length+" customer(s) loaded"+(Number.isSafeInteger(data.matching_count)?" of "+data.matching_count+" matching":"")+".";
      if(!list.children.length)status.textContent="No customers match these filters.";
    }catch(_){if(generation===customerGeneration){
      if(older){status.textContent="Older customers unavailable. Loaded results retained; retry or search again.";}
      else{clearCustomers();next.disabled=false;status.textContent="Customer directory unavailable. Search again to retry.";}
    }}
    finally{if(generation===customerGeneration)next.disabled=false;}
  }
  $("customer-filters").addEventListener("submit",event=>{event.preventDefault();loadCustomers();});
  $("customer-clear-filters").addEventListener("click",()=>{$("customer-filters").reset();loadCustomers();});
  for(const id of ["customer-query","customer-state","customer-history"])$(id).addEventListener(id==="customer-query"?"input":"change",()=>{
    clearCustomers();$("customer-directory-next").disabled=false;
    $("customer-directory-status").textContent="Filters changed. Search customers to apply.";
  });
  $("customer-directory-next").addEventListener("click",()=>loadCustomers(true));
  let financeGeneration=0,financeCursor=null,financeRecords=[],financeReport=null;
  const financeLabels={pending:"Awaiting verification",reconciled:"Reconciled",held:"On hold",refunded:"Full refund recorded",rejected:"Rejected evidence",review:"Needs review"};
  function financeAmount(value,currency){
    if(!Number.isSafeInteger(value)||value<0)return "Unavailable";
    if(!["CAD","USD"].includes(currency))return String(value)+" minor units ("+String(currency)+")";
    return currency+" "+(value/100).toLocaleString("en-CA",{minimumFractionDigits:2,maximumFractionDigits:2});
  }
  function clearFinance(){
    ++financeGeneration;financeCursor=null;financeRecords=[];financeReport=null;
    $("finance-summary").replaceChildren();$("finance-records").replaceChildren();
    $("finance-next").hidden=true;$("finance-export").disabled=true;
  }
  async function loadFinance(older=false){
    if(leavingSession||!csrf||(older&&financeCursor===null))return;
    if(!older)clearFinance();
    const generation=++financeGeneration,filter=$("finance-state").value,currency=$("finance-currency").value;
    const from=$("finance-from").value,to=$("finance-to").value;
    if((from&&!$("finance-from").checkValidity())||(to&&!$("finance-to").checkValidity())||(from&&to&&from>to)){
      clearFinance();$("finance-next").disabled=$("finance-refresh").disabled=false;
      $("finance-status").textContent="Choose a valid UTC date range; start cannot follow end.";return;
    }
    $("finance-next").disabled=$("finance-refresh").disabled=true;$("finance-export").disabled=true;
    $("finance-status").textContent="Loading payment records…";
    try{
      const params=new URLSearchParams({state:filter,currency});
      if(from)params.set("from",from);if(to)params.set("to",to);
      if(older)params.set("before",String(financeCursor));
      const {response,data}=await get("finance.php?"+params);
      if(generation!==financeGeneration||leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||data.scope!=="local_records_only"||!Array.isArray(data.summary)||!Array.isArray(data.records))throw Error("finance_unavailable");
      if(data.next_before!==null&&data.next_before!==undefined&&(!Number.isSafeInteger(data.next_before)||data.next_before<1||(older&&data.next_before>=financeCursor)))throw Error("Invalid finance cursor");
      $("finance-summary").replaceChildren();
      for(const group of data.summary){
        if(!financeLabels[group.record_state])continue;
        const card=document.createElement("article");card.className="launch-check";
        card.append(textNode("strong",String(group.currency)+" · "+financeLabels[group.record_state]),textNode("p",financeAmount(group.amount_minor,group.currency)+" · "+group.record_count+" record(s)","small"));
        if(["CAD","USD"].includes(group.currency)){
          const view=textNode("button","View these records","quiet");view.type="button";
          view.setAttribute("aria-label","View "+group.currency+" "+financeLabels[group.record_state].toLowerCase()+" records");
          view.addEventListener("click",()=>{$("finance-state").value=group.record_state;$("finance-currency").value=group.currency;loadFinance();});
          card.append(view);
        }
        $("finance-summary").append(card);
      }
      for(const item of data.records){
        if(!Number.isSafeInteger(item.id)||!Number.isSafeInteger(item.request_id)||!financeLabels[item.record_state]||financeRecords.some(row=>row.id===item.id))continue;
        const clean={id:item.id,order_id:item.order_id,request_id:item.request_id,public_ref:item.public_ref,method:item.method,currency:item.currency,amount_minor:item.amount_minor,status:item.status,record_state:item.record_state,created_at:item.created_at,verified_at:item.verified_at};
        financeRecords.push(clean);
        const card=document.createElement("article");card.className="notification-card";
        card.append(textNode("strong",financeLabels[item.record_state]+" · "+financeAmount(item.amount_minor,item.currency)),
          textNode("p","Order #"+item.order_id+" · "+item.public_ref+" · "+String(item.method).replaceAll("_"," "),"small"),
          textNode("p","Recorded: "+item.created_at+(item.verified_at?" · Reviewed: "+item.verified_at:""),"small"));
        const button=textNode("button","Open commission","quiet");button.type="button";button.addEventListener("click",()=>{if(!setRequestCustomer())return;requestListPage=1;requestListQuery="";requestListFilter="all";$("request-list-query").value="";$("request-list-filter").value="all";resetRequestSearchOptions();syncScheduleFilter(true);selectPanel("requests");openRequest(item.request_id,true);});card.append(button);$("finance-records").append(card);
      }
      financeCursor=Number.isSafeInteger(data.next_before)?data.next_before:null;
      financeReport={scope:"local_records_only",first_page_as_of:older?financeReport?.first_page_as_of:data.as_of,last_page_as_of:data.as_of,
        filter,currency,date_basis:"record_created_utc",date_from:from||null,date_to:to||null,
        matching_count_at_last_page:Number.isSafeInteger(data.matching_count)?data.matching_count:null,
        pagination_exhausted:financeCursor===null,consistent_snapshot:false};
      $("finance-next").hidden=financeCursor===null;
      $("finance-status").textContent=financeRecords.length+" payment record(s) loaded"+(Number.isSafeInteger(data.matching_count)?" of "+data.matching_count+" currently matching":"")+" · local evidence only."+(older?" Pages read at separate times; refresh for current classifications.":"");
      $("finance-export").disabled=financeRecords.length===0;
    }catch(_){if(generation===financeGeneration){
      if(older){$("finance-status").textContent="Older payment records unavailable. Existing loaded records retained; retry or refresh. Export disabled until refreshed or retry succeeds.";}
      else{clearFinance();$("finance-next").disabled=$("finance-refresh").disabled=false;$("finance-status").textContent="Payment records unavailable. Refresh to retry.";}
    }}
    finally{if(generation===financeGeneration){$("finance-next").disabled=$("finance-refresh").disabled=false;}}
  }
  $("finance-state").addEventListener("change",()=>loadFinance());
  $("finance-currency").addEventListener("change",()=>loadFinance());
  $("finance-refresh").addEventListener("click",()=>loadFinance());
  for(const id of ["finance-from","finance-to"])$(id).addEventListener("change",()=>{clearFinance();$("finance-next").disabled=$("finance-refresh").disabled=false;$("finance-status").textContent="Date range changed. Apply dates to load matching records.";});
  $("finance-clear-dates").addEventListener("click",()=>{$("finance-from").value=$("finance-to").value="";loadFinance();});
  $("finance-next").addEventListener("click",()=>loadFinance(true));
  $("finance-export").addEventListener("click",()=>{
    if(!financeReport||leavingSession||!financeRecords.length)return;
    if($("finance-export").disabled||!window.confirm("Download private payment records? This file contains commission references and amounts. Keep it secure. Loaded records only, not a bank statement."))return;
    const url=URL.createObjectURL(new Blob([JSON.stringify({...financeReport,loaded_records_only:true,records:financeRecords},null,2)+"\n"],{type:"application/json"}));
    const link=document.createElement("a");link.href=url;link.download="drawmeone-payment-records.json";document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });

  let adminSecurityBusy=false,adminSecurityReady=false,adminSecurityGeneration=0;
  function syncAdminSecurity(){
    const change=$("admin-security-action").value==="change_password";
    $("admin-security-new-fields").hidden=!change;
    $("admin-security-new").required=$("admin-security-confirm").required=change;
    $("admin-security-submit").disabled=adminSecurityBusy||!adminSecurityReady||!csrf;
  }
  function clearAdminSecurity(){
    ++adminSecurityGeneration;adminSecurityReady=false;$("admin-security-form").reset();
    $("admin-security-activity").replaceChildren();syncAdminSecurity();
  }
  async function loadAdminSecurity(){
    if(leavingSession||!csrf||adminSecurityBusy)return;
    const generation=++adminSecurityGeneration;adminSecurityReady=false;syncAdminSecurity();
    $("admin-security-refresh").disabled=true;$("admin-security-status").textContent="Checking account security…";$("admin-security-activity").replaceChildren();
    try{
      const {response,data}=await get("security.php");
      if(generation!==adminSecurityGeneration||leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||!Number.isSafeInteger(data.active_sessions)||!Array.isArray(data.activity))throw Error("unavailable");
      const labels={"admin.bootstrap":"Account created","admin.login":"Signed in","admin.logout":"Signed out","admin.password_reset_cli":"Password reset by operator","admin.password_changed":"Password changed","admin.other_sessions_revoked":"Other sessions revoked"};
      for(const item of data.activity){if(labels[item.action])$("admin-security-activity").append(textNode("li",labels[item.action]+" · "+item.created_at+" UTC","small"));}
      $("admin-security-status").textContent=data.active_sessions+" active session(s), including this device.";
      adminSecurityReady=true;
    }catch(_){if(generation===adminSecurityGeneration)$("admin-security-status").textContent="Security information unavailable. Refresh to retry.";}
    finally{if(generation===adminSecurityGeneration){$("admin-security-refresh").disabled=false;syncAdminSecurity();}}
  }
  $("admin-security").addEventListener("toggle",()=>{if($("admin-security").open)loadAdminSecurity();});
  $("admin-security-refresh").addEventListener("click",loadAdminSecurity);
  $("admin-security-action").addEventListener("change",()=>{$("admin-security-new").value=$("admin-security-confirm").value="";syncAdminSecurity();});
  $("admin-security-form").addEventListener("submit",async event=>{
    event.preventDefault();
    if(adminSecurityBusy||!adminSecurityReady||!csrf||!event.currentTarget.reportValidity())return;
    const action=$("admin-security-action").value,payload={action,current_password:$("admin-security-current").value};
    if(action==="change_password"){
      const next=$("admin-security-new").value;
      if(next!==$("admin-security-confirm").value||new TextEncoder().encode(next).length<16||new TextEncoder().encode(next).length>72){$("admin-security-feedback").textContent="New passwords must match and contain 16–72 UTF-8 bytes.";return;}
      payload.new_password=next;
    }
    if(!window.confirm(action==="change_password"?"Change your password and sign out all your administrator sessions?":"Sign out all other sessions for your administrator account?"))return;
    $("admin-security-current").value=$("admin-security-new").value=$("admin-security-confirm").value="";
    adminSecurityBusy=true;adminSecurityReady=false;syncAdminSecurity();
    for(const field of event.currentTarget.querySelectorAll("input,select,button"))field.disabled=true;
    $("admin-security-feedback").textContent="Confirming…";
    try{
      const {response,data}=await post("security.php",payload,csrf);
      if(leavingSession)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){$("admin-security-feedback").textContent=response.status===403?"Current password not accepted.":response.status===429?"Too many attempts. Try again later.":"Change not confirmed. Refresh security information before retrying.";return;}
      if(data.requires_sign_in===true)return freshSignIn("password-changed");
      $("admin-security-feedback").textContent=data.sessions_revoked+" other session(s) revoked.";
    }catch(_){$("admin-security-feedback").textContent="Outcome uncertain. Refresh security information before retrying.";}
    finally{adminSecurityBusy=false;for(const field of $("admin-security-form").querySelectorAll("input,select,button"))field.disabled=false;syncAdminSecurity();if(!leavingSession)loadAdminSecurity();}
  });

  function selectPanel(name) {
    clearError();
    document.querySelectorAll("[data-panel]").forEach(section => {
      section.hidden = section.dataset.panel !== name;
    });
    document.querySelectorAll("[data-view]").forEach(button => {
      if (button.dataset.view === name) {
        button.setAttribute("aria-current", "page");
      } else {
        button.removeAttribute("aria-current");
      }
    });
    if (name === "overview") overview().catch(() => showError("Connection unavailable."));
    if (name === "requests") requests().catch(() => showError("Connection unavailable."));
    if (name === "messages") messages().catch(() => showError("Connection unavailable."));
    if(name==="finance")loadFinance();
    if(name==="customers")loadCustomers();
    if (name === "settings"&&!designBusy&&!designDirty()&&!designLoaded) designLoad().catch(() => showError("Settings unavailable."));
    if (name === "settings") emailDiagnosticsLoad().catch(() => {
      $("mail-check-status").textContent = "Email diagnostics unavailable.";
    });
    if (name === "packages"&&!packageBusy&&!packageLoading&&!packageLoaded&&!packageHasChanges()) packagesLoad();
    if (name === "artwork"&&!artworkBusy) artworksLoad();
  }

  let leavingSession = false;
  function freshSignIn(reason) {
    if (leavingSession) return;
    leavingSession = true;
    csrf = "";
    // Invalidate pending reads, immediately hide private content, then replace
    // the whole document so no previous session's DOM or JS state is reused.
    ++requestLoadGeneration;
    ++requestListGeneration;
    ++messageGeneration;
    ++designGeneration;
    ++packageGeneration;
    ++overviewGeneration;
    ++artworkGeneration;
    artworkRecords=[];artworkDrafts.clear();artworkPendingFiles=[];artworkUploadTotals=null;$("artwork-upload-results").replaceChildren();artworkWriteEnabled=false;$("artwork-list").replaceChildren();
    intakeDraft=null;intakeSaved=null;intakeVersion=null;syncIntakeDraft();
    renderLaunchEvidence(null);renderConfigurationReview(null);clearAdminSecurity();
    closeRequestDetail(true);++messageGeneration;messagePage=1;messageQuery="";$("message-list-query").value="";$("message-list").replaceChildren();privacyDrafts.clear();syncPrivacyDrafts();clearPrivacyQueue();clearFinance();clearCustomers();setRequestCustomer();$("customer-query").value="";
    $("workspace").hidden = true;
    const url = new URL(location.href);
    url.search = "";
    url.hash = "";
    url.searchParams.set("session", reason);
    location.replace(url.href);
  }

  function sessionExpired() {
    freshSignIn("expired");
  }

  // A browser history restoration must not reveal an old private workspace.
  window.addEventListener("pagehide", () => {
    $("workspace").hidden = true;
  });
  window.addEventListener("pageshow", event => {
    if (event.persisted) location.reload();
  });

  async function checkSession() {
    try {
      const { response, data } = await get("me.php");
      if (!response.ok || !data.authenticated || !data.admin) return false;
      csrf = data.csrf_token || "";
      $("welcome-admin").textContent =
        "Signed in as " + data.admin.display_name + " · " + data.admin.email;
      show("workspace");
      selectPanel("overview");
      return true;
    } catch (_) {
      return false;
    }
  }

  function enableCaptcha(key) {
    if (widget !== null || !key) return;
    const tag = document.createElement("script");
    tag.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    tag.async = true;
    tag.addEventListener("load", () => {
      if (!window.turnstile) return;
      widget = window.turnstile.render("#admin-captcha", {
        sitekey: key, action: "admin_login", appearance: "always",
      });
      $("login-button").disabled = false;
    });
    tag.addEventListener("error", () => {
      $("login-message").textContent = "Security check is unavailable.";
    });
    document.head.appendChild(tag);
  }

  $("login-form").addEventListener("submit", async event => {
    event.preventDefault();
    const form = $("login-form");
    if (!form.reportValidity() || !window.turnstile || widget === null) return;
    const token = window.turnstile.getResponse(widget) || "";
    if (!token) {
      $("login-message").textContent = "Complete the security check first.";
      return;
    }
    $("login-button").disabled = true;
    $("login-message").textContent = "Checking credentials…";
    try {
      const { response, data } = await post("login.php", {
        email: $("login-email").value,
        password: $("login-password").value,
        turnstile_token: token,
      });
      $("login-password").value = "";
      window.turnstile.reset(widget);
      if (response.ok && data.ok) {
        if (await checkSession()) return;
        $("login-message").textContent =
          "Password accepted, but the admin session was not recognized. " +
          "Check whether this browser allows cookies for drawmeone.com.";
        return;
      }
      // The API never discloses whether an email address exists.
      // Status-specific guidance helps distinguish CAPTCHA, transport,
      // temporary lockout and actual credential problems.
      switch (response.status) {
        case 401:
          $("login-message").textContent =
            "Admin email/password did not match an active administrator. " +
            "Check the exact email used with the SSH account-creation command.";
          break;
        case 422:
          $("login-message").textContent =
            data.error === "verification_failed"
              ? "Cloudflare Turnstile rejected the security challenge. " +
                "Check widget hostname, site key and secret key configuration."
              : "Login request validation failed. Check the email and retry.";
          break;
        case 403:
          $("login-message").textContent =
            "Secure request refused (HTTP 403). HTTPS or same-origin validation " +
            "may be failing at the server. Do not disable security checks.";
          break;
        case 429:
          $("login-message").textContent =
            "Too many login attempts. Please wait at least 15 minutes before retrying.";
          break;
        case 503:
          $("login-message").textContent =
            "Server-side login setup failed (HTTP 503). Check Hostinger's PHP error log.";
          break;
        default:
          $("login-message").textContent =
            "Login failed (HTTP " + response.status +
            "). Please report only this status, not your password.";
      }
    } catch (_) {
      $("login-message").textContent = "Unable to connect. Try again later.";
    } finally {
      $("login-button").disabled = false;
    }
  });

  $("logout-button").addEventListener("click", async () => {
    try {
      const { response, data } = await post("logout.php", {}, csrf);
      if (!response.ok || data.ok !== true) throw new Error("logout_failed");
      freshSignIn("signed-out");
    } catch (_) {
      showError("Could not sign out. Please retry or close your browser.");
    }
  });

  document.querySelectorAll("[data-view]").forEach(button => {
    button.addEventListener("click", () => selectPanel(button.dataset.view));
  });

  async function init() {
    try {
      const { response, data } = await get("status.php");
      if (!response.ok || !data.admin_available || !data.turnstile_site_key) {
        $("closed-status").textContent = data.admin_schema_ready
          ? "Admin login is built but still disabled until private security settings are configured."
          : "Admin database setup is not ready. The private studio remains closed.";
        return;
      }
      siteKey = data.turnstile_site_key;
      const url = new URL(location.href);
      const reason = url.searchParams.get("session");
      const fresh = reason === "expired" || reason === "signed-out" || reason === "password-changed";
      if (fresh) {
        url.searchParams.delete("session");
        history.replaceState(null, "", url.href);
      }
      if (!fresh && await checkSession()) return;
      show("sign-in");
      if (fresh) {
        $("login-message").textContent = reason === "expired"
          ? "Your session has expired. Please sign in again." : reason==="password-changed"?"Password changed. Sign in with your new password.":"Signed out.";
      }
      enableCaptcha(siteKey);
    } catch (_) {
      $("closed-status").textContent =
        "Unable to verify admin availability. The private studio remains closed.";
    }
  }
  init();
})();
