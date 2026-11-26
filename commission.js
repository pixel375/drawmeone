"use strict";
(() => {
  const $ = id => document.getElementById(id);
  const state = {
    service: null, auth: null, catalog: null, account: null, csrf: "",
    sending: false, pendingKey: null, selectedRef: null, messageBusy: false,
    uploadBusy: false, quoteBusy: false, deleteBusy: false, requests: [],
  };
  const labels = {
    original_character: "Original character (OC)",
    fan_character: "Game, anime or fan character",
    portrait: "Portrait",
    other: "Other",
  };

  async function getJSON(url) {
    const response = await fetch(url, {
      credentials: "same-origin", cache: "no-store",
    });
    const body = await response.json();
    return {response, body};
  }
  let leavingSession = false;
  const proofDrafts=new Map();
  let pendingReply=null,remainingReferences=[],referenceStopRequested=false;
  const referenceUploadKeys=new WeakMap();
  function customerSessionExpired() {
    if (leavingSession) return;
    leavingSession = true;
    state.account = null;
    state.csrf = "";
    state.selectedRef = null;
    state.requests = [];
    clearDetailDrafts();
    ++requestsFetchSequence;
    ++detailRequestSequence;
    renderCustomerAttention(null);
    $("detail-summary").hidden=true;$("detail-summary").removeAttribute("href");
    $("customer-workspace").hidden = true;
    location.replace("./account.html");
  }
  window.addEventListener("pagehide", () => {
    $("customer-workspace").hidden = true;
  });
  window.addEventListener("pageshow", event => {
    if (event.persisted) location.reload();
  });
  function detailMutationBusy() {
    return state.messageBusy || state.uploadBusy || state.quoteBusy || state.deleteBusy;
  }
  function detailDraftKinds(){
    const kinds=[];
    if($("detail-reply-body").value!==""||pendingReply)kinds.push("private message");
    if($("detail-reference-files").files.length||remainingReferences.length)kinds.push("selected reference images");
    if([...proofDrafts.values()].some(value=>value!==""))kinds.push("proof revision feedback");
    return kinds;
  }
  function updateDetailDraftWarning(){
    const kinds=detailDraftKinds();
    $("detail-drafts-warning").hidden=kinds.length===0;
    $("detail-drafts-warning").textContent=kinds.length?"Not submitted: "+kinds.join(", ")+". These edits belong to this request only.":"";
  }
  function confirmDiscardDetailDrafts(){
    const kinds=detailDraftKinds();
    return !kinds.length||window.confirm("Discard your unsent "+kinds.join(", ")+" for this request?"+(pendingReply?" A previous send may already have succeeded; discarding loses its retry key.":""));
  }
  $("detail-reply-body").addEventListener("input",updateDetailDraftWarning);
  function syncReferenceResume(){
    $("detail-reference-resume").hidden=!remainingReferences.length;
    $("detail-reference-resume").disabled=state.uploadBusy||($("detail-reference-form").hidden&&!remainingReferences.some(file=>referenceUploadKeys.has(file)));
    $("detail-reference-resume").textContent="Upload "+remainingReferences.length+" remaining image(s)";
    $("detail-reference-stop").hidden=!state.uploadBusy;
    $("detail-reference-stop").disabled=referenceStopRequested;
    updateDetailDraftWarning();
  }
  $("detail-reference-files").addEventListener("change",()=>{
    if(remainingReferences.length&&$("detail-reference-files").files.length){
      if(!window.confirm("Replace the remaining reference upload selection with these new files?"))$("detail-reference-files").value="";
      else remainingReferences=[];
    }
    syncReferenceResume();
  });
  $("detail-reference-stop").addEventListener("click",()=>{
    if(state.uploadBusy){referenceStopRequested=true;syncReferenceResume();$("detail-reference-feedback").textContent="Stopping after the current image…";}
  });
  function clearDetailDrafts() {
    proofDrafts.clear();pendingReply=null;remainingReferences=[];referenceStopRequested=false;
    $("detail-reply-body").value = "";
    $("detail-reference-files").value = "";
    $("detail-reference-feedback").textContent = "";
    $("detail-reply-feedback").textContent = "";
    $("detail-reference-progress").hidden = true;
    syncReferenceResume();
  }
  function displayStatus(text, error = false) {
    const node = $("request-status");
    node.textContent = text;
    node.dataset.error = String(error);
  }
  function feedback(text, error = false) {
    const node = $("request-feedback");
    node.hidden = false;
    node.dataset.error = String(error);
    node.textContent = text;
  }
  function renderText(tag, value, className) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = String(value ?? "");
    return node;
  }
  function money(minor, currency) {
    if (!Number.isSafeInteger(minor) || minor < 0) return null;
    if (!["CAD","USD"].includes(currency)) return null;
    return new Intl.NumberFormat("en-CA",{
      currency, style: "currency",
    }).format(minor / 100);
  }
  function estimate(item) {
    const low = money(item.estimate_min_minor, item.currency);
    const high = money(item.estimate_max_minor, item.currency);
    return low && high ? (low === high ? low : low + "–" + high) + " estimated"
      : "Quote after artist review";
  }

  const preferredPackage=new URLSearchParams(location.search).get("package");
  const validPreferredPackage=typeof preferredPackage==="string"&&/^[a-z0-9][a-z0-9-]{1,79}$/.test(preferredPackage)?preferredPackage:null;
  let initialPackageUsed=false;
  let requestFormBaseline=null;
  function requestFormSnapshot(){
    return JSON.stringify([$("request-tier").value,$("request-subject").value,$("request-brief").value,$("request-deadline").value,$("request-contact").value,$("request-ack").checked,[...$("addons-options").querySelectorAll("input:checked")].map(x=>x.value)]);
  }
  function requestFormDirty(){return requestFormBaseline!==null&&requestFormSnapshot()!==requestFormBaseline;}
  function refreshRequestDraftState(){
    const raw=$("request-brief").value,size=new TextEncoder().encode(raw).length,trimmed=new TextEncoder().encode(raw.trim()).length;
    $("request-brief-count").textContent=size+" / 6000 UTF-8 bytes"+(size>6000?" · Shorten your brief":trimmed<30?" · Add more detail":"");
    $("request-brief").setCustomValidity(size>6000?"Shorten your brief to at most 6000 UTF-8 bytes.":trimmed<30&&raw.length?"Add more detail (at least 30 UTF-8 bytes).":"");
    const dirty=requestFormDirty();$("request-unsent").hidden=!dirty;$("request-clear-draft").hidden=!dirty;$("request-clear-draft").disabled=state.sending||receiptBusy;
  }
  $("request-form").addEventListener("input",refreshRequestDraftState);
  $("request-form").addEventListener("change",refreshRequestDraftState);
  $("request-clear-draft").addEventListener("click",()=>{
    if(state.sending||receiptBusy||!requestFormDirty()||!window.confirm("Clear the unsent request fields? Any existing submission receipt ID will be retained."))return;
    $("request-form").reset();$("request-tier").dispatchEvent(new Event("change"));
    requestFormBaseline=requestFormSnapshot();refreshRequestDraftState();
  });
  window.addEventListener("beforeunload",event=>{
    if(leavingSession)return;
    if(requestFormDirty()||detailDraftKinds().length||state.sending||detailMutationBusy()){event.preventDefault();event.returnValue="";}
  });
  function renderCatalog() {
    const tiers = Array.isArray(state.catalog?.tiers) ? state.catalog.tiers : [];
    const addons = Array.isArray(state.catalog?.addons) ? state.catalog.addons : [];
    const tierSelect = $("request-tier");
    tierSelect.replaceChildren(new Option("Custom idea — discuss with artist", ""));
    for (const tier of tiers) {
      if (!Number.isSafeInteger(tier.id) || tier.id < 1) continue;
      const option = new Option(tier.name + " · " + estimate(tier), String(tier.id));
      tierSelect.append(option);
    }
    const addonWrap = $("addons-options");
    addonWrap.replaceChildren();
    $("addons-empty").hidden = addons.length > 0;
    for (const addon of addons) {
      if (!Number.isSafeInteger(addon.id) || addon.id < 1) continue;
      const label = document.createElement("label");
      label.className = "addon-entry";
      const input = document.createElement("input");
      input.type = "checkbox";
      input.value = String(addon.id);
      input.setAttribute("aria-label",addon.name);
      const copy = document.createElement("span");
      copy.append(renderText("strong",addon.name),
        renderText("small",estimate(addon)));
      label.append(input,copy);
      addonWrap.append(label);
    }
    const updateEstimate=()=>{
      const tier=tiers.find(x=>String(x.id)===tierSelect.value);
      const chosen=addons.filter(x=>[...addonWrap.querySelectorAll("input:checked:not(:disabled)")].some(input=>input.value===String(x.id)));
      $("request-addons-count").textContent=chosen.length+" of 8 extras selected";
      const box=$("request-estimate"),output=$("request-estimate-value");
      if(!tier){box.hidden=true;output.textContent="";return;}
      box.hidden=false;
      const selected=[tier,...chosen],same=selected.every(x=>x.currency===tier.currency&&Number.isSafeInteger(x.estimate_min_minor)&&Number.isSafeInteger(x.estimate_max_minor)&&x.estimate_min_minor>=0&&x.estimate_max_minor>=x.estimate_min_minor);
      if(!same){output.textContent="Individual quote required — selected prices use different currencies or are unavailable.";return;}
      const low=selected.reduce((sum,x)=>sum+x.estimate_min_minor,0),high=selected.reduce((sum,x)=>sum+x.estimate_max_minor,0);
      output.textContent=tier.currency+" "+money(low,tier.currency)+(low===high?"":" – "+money(high,tier.currency));
    };
    const describeTier = () => {
      const tier = tiers.find(x => String(x.id) === tierSelect.value);
      $("tier-note").textContent = tier ?
        (tier.description || "Estimated price is subject to an individual quote.")
        : "Choose a package or describe a custom idea.";
      const features=$("request-tier-features");features.replaceChildren();
      for(const value of Array.isArray(tier?.features)?tier.features.slice(0,20):[]){if(typeof value==="string")features.append(renderText("li",value));}
      features.hidden=!features.childElementCount;
      // If tier/add-on compatibility is supplied, hide incompatible add-ons.
      const supported = Array.isArray(tier?.available_addon_ids)
        ? new Set(tier.available_addon_ids.map(String)) : null;
      for (const checkbox of addonWrap.querySelectorAll('input[type="checkbox"]')) {
        const available = !supported || supported.has(checkbox.value);
        checkbox.disabled = !available;
        if (!available) checkbox.checked = false;
        checkbox.closest(".addon-entry").hidden = !available;
      }
      $("addons-empty").hidden=!!addonWrap.querySelector("input:not(:disabled)");
      updateEstimate();
    };
    tierSelect.onchange=describeTier;addonWrap.onchange=event=>{
      if(event.target.matches("input[type=checkbox]")&&event.target.checked&&addonWrap.querySelectorAll("input:checked").length>8){event.target.checked=false;updateEstimate();$("request-addons-count").textContent="8 of 8 extras selected · Deselect one before choosing another.";}
      else updateEstimate();refreshRequestDraftState();
    };
    if(!initialPackageUsed){
      initialPackageUsed=true;
      if(validPreferredPackage){const matched=tiers.find(x=>x.slug===validPreferredPackage&&Number.isSafeInteger(x.id)&&x.id>0);if(matched){tierSelect.value=String(matched.id);$("request-package-handoff").textContent="Package selected from the services page. You can change it before submitting.";}else{$("request-package-handoff").textContent="That package is no longer available. Choose another package or a custom idea.";} }
    }else $("request-package-handoff").textContent="";
    describeTier();requestFormBaseline=requestFormSnapshot();refreshRequestDraftState();
  }

  function localTomorrow() {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2,"0"),
      String(date.getDate()).padStart(2,"0"),
    ].join("-");
  }
  function newKey() {
    if (!globalThis.crypto || typeof crypto.randomUUID !== "function") {
      throw new Error("Secure request IDs require a modern HTTPS browser.");
    }
    return crypto.randomUUID();
  }
  function storedRequestKey(){
    let stored=null;
    try{stored=sessionStorage.getItem("dmo_request_pending_key");}catch(_){}
    return typeof stored==="string"&&/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(stored)?stored:null;
  }
  function syncReceiptRecovery(){
    $("request-recovery").hidden=!(state.pendingKey||storedRequestKey());
    $("request-recovery-check").disabled=state.sending||receiptBusy;
    $("request-recovery-open").disabled=state.sending||receiptBusy;
    $("request-recovery-dismiss").disabled=state.sending||receiptBusy;
  }
  let receiptBusy=false;
  function requestKey() {
    if(state.pendingKey)return state.pendingKey;
    state.pendingKey=storedRequestKey()||newKey();
    try { sessionStorage.setItem("dmo_request_pending_key",state.pendingKey); } catch (_) {}
    syncReceiptRecovery();
    return state.pendingKey;
  }

  const closedRequestStatuses = new Set([
    "cancelled","canceled","rejected","declined","completed",
    "closed","archived","refunded","payment_refunded",
  ]);
  const requestStatusLabels = {
    submitted:"Awaiting artist review",
    clarification_needed:"Clarification requested",
    quote_ready:"Quote under review",
    quoted:"Quote available",
    awaiting_customer:"Awaiting your response",
    accepted:"Quote accepted",
    quote_accepted:"Quote accepted",
    quote_available:"Awaiting your quote decision",
    quote_expired:"Quote expired",
    quote_declined:"Quote declined",
    quote_inactive:"Quote no longer available",
    payment_on_hold:"Payment under review",payment_refunded:"External refund recorded",
    awaiting_arrangement:"Awaiting payment arrangements",
    queued:"In the artist queue",
    drawing:"Drawing in progress",
    in_progress:"In progress",
    awaiting_feedback:"Awaiting feedback",
    revision:"Revisions in progress",
    ready_for_delivery:"Ready for delivery",
    completed:"Completed",
    cancelled:"Cancelled",
    canceled:"Cancelled",
    rejected:"Declined by artist",
    declined:"Declined",
    closed:"Closed",
    archived:"Archived",
    refunded:"Refunded",
  };
  function readableRequestStatus(value) {
    return requestStatusLabels[value] || "Awaiting review";
  }
  function renderRequestHistory() {
    const target = $("my-request-list");
    const rows = state.requests;
    const query = $("request-history-search").value.trim().toLocaleLowerCase();
    const filter = $("request-history-filter").value;
    const matches = historyServerCriteria ? [...rows] : rows.filter(item => {
      const closed = closedRequestStatuses.has(item.workflow_stage || item.status);
      if (filter === "closed" && !closed) return false;
      if (filter === "active" && closed) return false;
      return !query || [
        item.public_ref, item.tier_name, labels[item.subject_type],
        readableRequestStatus(item.workflow_stage || item.status),
      ].some(value => String(value || "").toLocaleLowerCase().includes(query));
    });
    const sort=$("request-history-sort").value;
    if(!historyServerCriteria&&sort==="oldest")matches.reverse();
    if(!historyServerCriteria&&sort==="deadline")matches.sort((a,b)=>{
      const date=item=>closedRequestStatuses.has(item.workflow_stage||item.status)?"9999-12-31":item.order_deadline||"9999-12-31";
      return date(a).localeCompare(date(b));
    });
    target.replaceChildren();
    $("request-history-count").textContent = historyServerCriteria ? "Entire-history search: "+rows.length+" matching request(s) loaded"+(historyHasMore?" · More matches available":"") : rows.length
      ? "Showing " + matches.length + " of " + rows.length + (historyHasMore ? " loaded requests · Older requests available" : " requests")
      : "No requests yet";
    if (!rows.length) {
      target.append(renderText("p",
        historyServerCriteria?"No requests match this search. Try changing the reference, category, package or status.":"You don't have any commission requests yet.","small"));
      return;
    }
    if (!matches.length) {
      target.append(renderText("p",
        historyHasMore ? "No loaded requests match. Load older requests to search further." : "No requests match these filters. Try another status or reference.","small"));
      return;
    }
    for (const item of matches) {
      const card = document.createElement("article");
      card.className = "request-list-item";
      const detail = document.createElement("div");
      detail.append(
        renderText("strong",item.public_ref),
        renderText("small",(item.tier_name || labels[item.subject_type] || "Custom") +
          " · " + readableRequestStatus(item.workflow_stage || item.status)),
        renderText("small","Submitted " + (item.submitted_at || ""))
      );
      if(item.order_deadline)detail.append(renderText("small","Agreed date: "+item.order_deadline));
      const button = document.createElement("button");
      button.type = "button";
      button.className = "text-button";
      button.textContent = "View details";
      button.setAttribute("aria-label","View details for " + item.public_ref);
      button.addEventListener("click", () => openDetail(item.public_ref));
      card.append(detail,button);
      target.append(card);
    }
  }

  function renderCustomerAttention(attention){
    const root=$("customer-attention"),list=$("customer-attention-items");
    list.replaceChildren();root.hidden=true;
    if(!attention||typeof attention!=="object"||leavingSession)return;
    const labels={clarifications:"Clarification requested",quotes:"Review quote",proofs:"Review artwork proof",invoices:"Review payment arrangements",artist_replies:"Recent artist reply",deliveries:"View finished artwork"};
    const grouped=new Map();
    for(const [key,label] of Object.entries(labels)){
      const refs=Array.isArray(attention[key])?attention[key].filter(ref=>typeof ref==="string"&&/^DMO-[0-9a-f]{20}$/.test(ref)).slice(0,10):[];
      for(const ref of new Set(refs)){
        if(!grouped.has(ref))grouped.set(ref,[]);
        grouped.get(ref).push(label);
      }
    }
    for(const [ref,updates] of grouped){
      const card=document.createElement("article"),description=document.createElement("div");
      description.append(renderText("strong",ref));
      const items=document.createElement("ul");items.className="customer-attention-labels";
      for(const update of updates)items.append(renderText("li",update));
      description.append(items);
      const button=renderText("button","View details","text-button");button.type="button";
      button.setAttribute("aria-label","View commission updates for "+ref);
      button.addEventListener("click",()=>openDetail(ref));
      card.append(description,button);list.append(card);
    }
    $("customer-attention-count").textContent=grouped.size+" commission"+(grouped.size===1?"":"s")+" with recent updates or available actions.";
    root.hidden=list.childElementCount===0;
  }

  let requestsFetchSequence = 0;
  let historyPage = 0;
  let historyHasMore = false;
  let historyBusy = false;
  let historyServerCriteria=null;
  function historyControlsChanged(){
    if(historyServerCriteria){historyServerCriteria=null;loadMyRequests();}else renderRequestHistory();
  }
  $("request-history-search-all").addEventListener("click",()=>{
    historyServerCriteria={q:$("request-history-search").value.trim(),status:$("request-history-filter").value,sort:$("request-history-sort").value};loadMyRequests();
  });
  function syncHistoryControls() {
    $("request-history-older").hidden = !historyHasMore;
    $("request-history-older").textContent=historyServerCriteria?"Load more matches":"Load older requests";
    $("request-history-older").disabled = historyBusy;
  }
  async function loadMyRequests(older = false) {
    const append = older === true;
    if (leavingSession || (append && (historyBusy || !historyHasMore))) return;
    const sequence = ++requestsFetchSequence;
    const page = append ? historyPage + 1 : 1;
    const target = $("my-request-list");
    historyBusy = true;
    if (!append) {
      historyHasMore = false;
      historyPage = 0;
      state.requests = [];
      renderCustomerAttention(null);
      target.replaceChildren(renderText("p","Loading your requests…","small"));
    }
    syncHistoryControls();
    $("request-history-feedback").textContent = "";
    $("request-history-count").textContent = append ? "Loading older requests…" : "Refreshing request history…";
    try {
      const params=new URLSearchParams({page:String(page),...(historyServerCriteria||{})});
      const {response, body} = await getJSON("./api/requests.php?" + params);
      if (sequence !== requestsFetchSequence) return;
      if (response.status === 401) return customerSessionExpired();
      if (!response.ok || !body.ok || !Array.isArray(body.requests) ||
          (body.page !== undefined && body.page !== page)) {
        throw new Error("Requests unavailable");
      }
      const rows = body.requests.filter(item =>
        typeof item.public_ref === "string" &&
        /^DMO-[0-9a-f]{20}$/.test(item.public_ref)
      );
      const merged = new Map((append ? state.requests : []).map(item=>[item.public_ref,item]));
      for (const row of rows) merged.set(row.public_ref,row);
      state.requests = [...merged.values()];
      historyPage = page;
      historyHasMore = body.has_more === true;
      if(!append)renderCustomerAttention(body.attention);
      renderRequestHistory();
    } catch (_) {
      if (sequence !== requestsFetchSequence) return;
      if (append) {
        renderRequestHistory();
        $("request-history-feedback").textContent = "Could not load older requests. Try again.";
      } else {
        $("request-history-count").textContent = "Could not refresh request history";
        target.replaceChildren(renderText("p","Couldn't load your requests. Try refreshing.","small"));
      }
    } finally {
      if (sequence === requestsFetchSequence) {
        historyBusy = false;
        syncHistoryControls();
      }
    }
  }
  $("request-history-older").addEventListener("click", () => loadMyRequests(true));
  $("request-history-search").addEventListener("input", historyControlsChanged);
  $("request-history-filter").addEventListener("change", historyControlsChanged);
  $("request-history-sort").addEventListener("change", historyControlsChanged);


  function syncQuoteButtons() {
    for (const card of $("detail-quotes").querySelectorAll(".customer-quote-card")) {
      const checkbox = card.querySelector(".customer-quote-confirm input");
      const accept = card.querySelector('[data-quote-action="accept"]');
      const decline = card.querySelector('[data-quote-action="decline"]');
      if (accept) accept.disabled = state.quoteBusy || !checkbox?.checked;
      if (decline) decline.disabled = state.quoteBusy;
    }
  }

  async function respondToQuote(ref, quoteId, policyVersion, decision, checkbox) {
    if (detailMutationBusy() || state.selectedRef !== ref || !state.csrf ||
        !Number.isSafeInteger(quoteId) || quoteId < 1) return;
    if (decision === "accept" && !checkbox?.checked) {
      $("detail-quote-feedback").textContent =
        "Read the quote and confirm the acknowledgement before accepting.";
      return;
    }
    if (!window.confirm(decision === "accept"
        ? "Confirm agreement with this exact quoted scope and price? No payment is collected."
        : "Decline this quote? You can ask the artist about a different version.")) return;
    state.quoteBusy = true;
    $("detail-quote-feedback").textContent = "Recording your decision securely…";
    $("detail-refresh").disabled = true;
    syncQuoteButtons();
    try {
      const response = await fetch("./api/quote-response.php", {
        method:"POST", credentials:"same-origin", cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify({
          ref,quote_id:quoteId,decision,policy_version:policyVersion,
          acknowledge_quote:decision === "accept",
        }),
      });
      if (response.status === 401) return customerSessionExpired();
      const body = await response.json();
      if (state.selectedRef !== ref) return;
      if (!response.ok || !body.ok) {
        $("detail-quote-feedback").textContent =
          response.status === 409
            ? "This quote is no longer available for a decision. Refresh the request."
            : "Could not record decision (" + (body.error || response.status) + ").";
        return;
      }
      // Do not navigate back to this request if the customer selected
      // another request while a quote decision was in flight.
      if (state.selectedRef !== ref) return;
      await openDetail(ref);
      await loadMyRequests();
      if (state.selectedRef !== ref || $("detail-content").hidden) return;
      $("detail-quote-feedback").textContent =
        decision === "accept"
          ? "Your agreement to the quote was recorded. No order or payment has been created."
          : "You declined this quote. Contact the artist if you'd like a revised offer.";
    } catch (_) {
      if (state.selectedRef === ref) {
        $("detail-quote-feedback").textContent =
          "Connection interrupted. Refresh the quote status before trying again.";
      }
    } finally {
      state.quoteBusy = false;
      $("detail-refresh").disabled = $("my-request-detail").hidden;
      syncQuoteButtons();
    }
  }


  async function respondArtworkReview(id,decision,feedback,status,box){
    if(detailMutationBusy()||!state.selectedRef||!state.csrf)return;
    if(decision==="approve"&&proofDrafts.get(id)&&!window.confirm("Discard your unsent revision feedback and approve this proof instead?"))return;
    if(!window.confirm(decision==="approve"?"Approve this exact proof for final delivery?":"Use one included revision from your accepted quote?"))return;
    const ref=state.selectedRef;state.quoteBusy=true;
    for(const field of box.querySelectorAll("button,textarea"))field.disabled=true;
    status.textContent="Recording your decision…";
    try{
      const response=await fetch("./api/artwork-review.php",{method:"POST",credentials:"same-origin",cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},body:JSON.stringify({review_id:id,decision,feedback})});
      if(response.status===401)return customerSessionExpired();
      const data=await response.json();
      if(!response.ok||!data.ok){status.textContent="Decision not recorded ("+(data.error||response.status)+"). Refresh before retrying.";return;}
      proofDrafts.delete(id);updateDetailDraftWarning();
      await openDetail(ref);await loadMyRequests();
    }catch(_){status.textContent="Decision not confirmed. Refresh before retrying.";}
    finally{state.quoteBusy=false;$("detail-refresh").disabled=$("my-request-detail").hidden;syncQuoteButtons();for(const field of box.querySelectorAll("button,textarea"))field.disabled=false;}
  }

  function renderCustomerProgress(body){
    const request=body.request||{},stage=request.workflow_stage||request.status;
    const quotes=Array.isArray(body.quotes)?body.quotes:[],orders=Array.isArray(body.orders)?body.orders:[],order=orders[0];
    const accepted=quotes.find(q=>q.status==="accepted"),live=quotes.find(q=>q.status==="sent"&&!q.is_expired&&q.can_respond===true);
    const closed=closedRequestStatuses.has(stage),held=order?.payment_status==="on_hold"||stage==="payment_on_hold",refunded=order?.payment_status==="refunded"||stage==="payment_refunded";
    const paid=order?.payment_status==="paid"&&!held&&!refunded;
    const reviewRecord=paid&&(Array.isArray(body.artwork_reviews)?body.artwork_reviews:[]).find(r=>r.order_id===order.id);
    const review=reviewRecord?.latest;
    const delivery=paid&&order.status==="completed"&&(Array.isArray(body.deliveries)?body.deliveries:[]).some(d=>d.order_id===order.id&&Number.isSafeInteger(d.id)&&d.id>0);
    const invoice=!closed&&!held&&!refunded&&order?.status==="awaiting_arrangement"&&order.payment_status==="unverified"&&(Array.isArray(body.checkout_links)?body.checkout_links:[]).some(l=>l.order_id===order.id&&Number.isSafeInteger(l.id)&&l.id>0);
    const proofPending=reviewRecord?.can_respond===true&&review?.status==="pending"&&order?.status==="in_progress"&&Number.isSafeInteger(review.id)&&review.id>0;
    const actions=$("detail-next-actions"),milestones=$("detail-milestones");actions.replaceChildren();milestones.replaceChildren();
    let next="Your request is with the artist for review.";
    const jump=(label,id)=>{const button=renderText("button",label,"text-button");button.type="button";button.addEventListener("click",()=>{const target=$(id);if(target&&!target.hidden){target.tabIndex=-1;target.focus({preventScroll:true});target.scrollIntoView({behavior:"instant",block:"start"});}});actions.append(button);};
    if(refunded)next="An external refund has been recorded. Contact the artist if you have questions.";
    else if(held)next="Payment is under review. Artwork access is paused; contact the artist before proceeding.";
    else if(delivery){next="Your finished artwork is available to download.";jump("Go to finished artwork","detail-order-section");}
    else if(closed||order?.status==="cancelled")next=stage==="completed"||order?.status==="completed"?"This commission is recorded as completed. A downloadable file is not currently available here.":"This request or order is closed. You can review the history.";
    else if(order){
      if(order.payment_status!=="paid"){next=invoice?"An external invoice is available. Do not pay again if you have already paid; the artist verifies receipt separately.":"Payment arrangements or verification are still pending.";jump("View order and payment details","detail-order-section");}
      else if(proofPending){next="A watermarked artwork proof is awaiting your review.";jump("Review artwork proof","detail-order-section");}
      else if(review?.status==="pending")next="A proof is recorded but not currently available to review. Contact the artist if this persists.";
      else if(review?.status==="revision_requested")next="Your revision feedback is with the artist.";
      else if(order.status==="ready_for_delivery")next="The artist has marked the artwork ready for delivery. A download will appear after release.";
      else next="The order is recorded as paid; the artist will update production progress here.";
    }else if(accepted)next="Your quote was accepted. The artist still needs to confirm the order.";
    else if(live){next="A quote is available for your decision.";jump("Review quote","detail-quote-section");}
    else if(stage==="clarification_needed"){next="The artist has requested clarification. Review your conversation.";if(body.can_reply===true)jump("Go to private reply","detail-reply-form");}
    else if(quotes.some(q=>q.status==="sent"&&q.is_expired))next="The latest offer has expired. Contact the artist for an updated quote.";
    else if(stage==="quote_declined"||stage==="quote_inactive"||stage==="quote_expired")next="There is no current offer to accept. Contact the artist if you want to discuss a new quote.";
    else if(stage==="quote_available"||quotes.some(q=>q.status==="sent"))next="A quote is recorded but is not currently available for a decision. Refresh or contact the artist.";
    $("detail-next-step").textContent=next;
    const entries=[["Request","Received"],["Quote",accepted?"Accepted":live?"Awaiting decision":quotes.length?"Historical offer(s)":"Not issued"],["Order",order?readableRequestStatus(order.status):"Not confirmed"],["Payment",!order?"No order":held?"Under review":refunded?"Refund recorded":order.payment_status==="paid"?"Verified by artist":"Not verified"],["Artwork review",held||refunded?"Access paused":review?(proofPending?"Awaiting review":({approved:"Approved",revision_requested:"Revision requested"}[review.status]||"Recorded")):"No proof available"],["Final artwork",held||refunded?"Access paused":delivery?"Download available":"Not currently available"]];
    for(const [label,value] of entries){const row=document.createElement("div");row.append(renderText("dt",label),renderText("dd",value));milestones.append(row);}
  }
  function renderCustomerOrders(orders,deliveries=[],reviews=[],checkoutLinks=[]) {
    const root = $("detail-orders");
    root.replaceChildren();
    const records = Array.isArray(orders) ? orders : [];
    $("detail-order-section").hidden = records.length === 0;
    const labels = {
      awaiting_arrangement:"Awaiting payment arrangements",
      in_progress:"Artwork in progress",
      ready_for_delivery:"Ready for delivery",
      completed:"Completed",
      cancelled:"Cancelled",
    };
    for (const order of records) {
      const card = document.createElement("article");
      card.className = "customer-order-card";
      card.append(
        renderText("strong","Order #" + order.id),
        renderText("p","Status: " +
          (labels[order.status] || readableRequestStatus(order.status))),
        renderText("small","Quote total: " +
          (money(order.total_minor,order.currency) || "Not available")),
        renderText("small","Payment status: " +
          ({paid:"Verified by artist",unverified:"Not verified",on_hold:"Under review",refunded:"External refund recorded"}[order.payment_status] || "Not verified")),
        renderText("small","Order recorded: " + (order.created_at || "Not available"))
      );
      card.append(renderText("small","Agreed delivery date: "+(order.agreed_deadline||"Not agreed yet")));
      if(order.payment_status==="on_hold")card.append(renderText("p","Payment is under review. Artwork access is paused. Please contact the artist.","small"));
      if(order.payment_status==="refunded")card.append(renderText("p","A full external refund has been recorded. Please contact the artist if you have questions.","small"));
      if (order.payment_status === "paid") card.append(renderText("p","The artist has recorded receipt after manual reconciliation. This is not an automated provider receipt.","small"));
      if(order.status==="cancelled")card.append(renderText("p","This unpaid order was cancelled. Do not send payment. The original quote remains in your history.","small"));
      if (order.payment_status === "unverified"&&order.status!=="cancelled") {
        const invoice=checkoutLinks.find(x=>x.order_id===order.id&&Number.isSafeInteger(x.id)&&x.id>0);
        if(invoice){
          card.append(renderText("p","An external invoice is available. Check the receiving merchant, currency and exact quote total before paying. Do not pay again if you have already paid. The artist must verify receipt separately.","small"),
            renderText("small","Provider: "+invoice.provider_host+" · Local access until "+invoice.expires_at+" UTC"));
          const link=renderText("a","Open external invoice","text-button");link.href="./api/checkout.php?id="+invoice.id;link.target="_blank";link.rel="noopener noreferrer";card.append(link);
        }else card.append(renderText("p","Payment has not been verified. Please use the agreed contact method with the artist; no hosted invoice is available.","small"));
      }
      const review=order.payment_status==="paid"&&order.status!=="cancelled"?reviews.find(r=>r.order_id===order.id):null;
      if(review?.latest){
        const latest=review.latest;
        const box=document.createElement("section");box.className="customer-proof";
        box.append(renderText("strong","Artwork review · Proof "+latest.round),
          renderText("p","Included revisions used: "+review.revisions_used+" / "+review.revision_allowance,"small"),
          renderText("p",latest.status==="pending"?(review.can_respond===true?"Please review the proof.":"This proof is not currently available to review. Refresh or contact the artist."):latest.status==="approved"?"You approved this proof.":"Revision requested.","small"));
        if(latest.feedback)box.append(renderText("p",latest.feedback,"small"));
        if(review.can_respond===true&&latest.status==="pending"&&order.status==="in_progress"&&Number.isSafeInteger(latest.id)&&latest.id>0){
          const img=document.createElement("img");img.alt="Watermarked artwork proof";img.loading="lazy";
          box.append(img,renderText("p","This is a reduced watermarked proof, not your final download.","small"));
          const approve=renderText("button","Approve proof","text-button");approve.type="button";
          const status=renderText("p","","small");status.setAttribute("role","status");
          approve.addEventListener("click",()=>respondArtworkReview(latest.id,"approve","",status,box));
          box.append(approve);
          if(review.revisions_used<review.revision_allowance){
            const form=document.createElement("form");const label=renderText("label","What should the artist change?");
            const note=document.createElement("textarea");note.required=true;note.minLength=3;note.maxLength=500;note.value=proofDrafts.get(latest.id)||"";note.addEventListener("input",()=>{if(note.value)proofDrafts.set(latest.id,note.value);else proofDrafts.delete(latest.id);updateDetailDraftWarning();});label.append(note);
            const revise=renderText("button","Request included revision","text-button");revise.type="submit";
            form.append(label,revise);form.addEventListener("submit",event=>{event.preventDefault();respondArtworkReview(latest.id,"revise",note.value.trim(),status,box);});
            box.append(form);
          }else box.append(renderText("p","Included revisions are exhausted. Contact the artist separately about additional changes.","small"));
          box.append(status);
          // A failed/unfinished image load must not leave an unseen proof
          // actionable. Refresh requests fresh eligibility and media.
          const fields=()=>box.querySelectorAll("button,textarea");
          const setProofReady=ready=>{for(const field of fields())field.disabled=!ready;};
          setProofReady(false);
          status.textContent="Loading private proof…";
          img.addEventListener("load",()=>{setProofReady(true);status.textContent="";},{once:true});
          img.addEventListener("error",()=>{setProofReady(false);status.textContent="The proof could not load. Refresh the request before deciding."},{once:true});
          img.src="./api/artwork-review.php?id="+latest.id;
        }
        card.append(box);
      }
      for(const delivery of (order.payment_status==="paid"&&order.status==="completed"?deliveries.filter(d=>d.order_id===order.id):[])){
        if(!Number.isSafeInteger(delivery.id)||delivery.id<1)continue;
        card.append(renderText("p","Your finished artwork is available ("+delivery.width+" × "+delivery.height+", WebP).","small"));
        const link=renderText("a","Download finished artwork","text-button");
        link.href="./api/delivery.php?id="+delivery.id;
        link.rel="noopener noreferrer";
        card.append(link);
      }
      root.append(card);
    }
  }

  function renderCustomerQuotes(quotes, ref, policyVersion) {
    const root = $("detail-quotes");
    root.replaceChildren();
    $("detail-quote-section").hidden = !Array.isArray(quotes) || !quotes.length;
    if (!Array.isArray(quotes)) return;
    const statuses = {
      sent:"Awaiting your decision",
      accepted:"Accepted",
      declined:"Declined",
      superseded:"No longer available (withdrawn or replaced)",
    };
    const hasAccepted = quotes.some(quote => quote.status === "accepted");
    for (const quote of quotes) {
      if (!Number.isSafeInteger(quote.id) || quote.id < 1) continue;
      const card = document.createElement("article");
      card.className = "customer-quote-card";
      const statusText = statuses[quote.status] || "Under review";
      card.append(
        renderText("strong", "Quote v" + quote.version_number),
        renderText("p",statusText + (quote.is_expired && quote.status === "sent" ?
          " · Expired" : ""),"customer-quote-status"),
        renderText("p", quote.scope_description || ""),
        renderText("small", "Total: " +
          (money(quote.total_minor,quote.currency) || "Unavailable") +
          " · Included revisions: " + quote.revision_allowance),
        renderText("small", "Valid until: " +
          (quote.validity_ends_at || "not specified") + " UTC")
      );
      const lines = document.createElement("ul");
      for (const line of quote.lines || []) {
        lines.append(renderText("li",
          (line.description || "") + " — " +
          (money(line.amount_minor,quote.currency) || "—")));
      }
      card.append(lines);
      if (quote.is_expired && quote.status === "sent") {
        card.append(renderText("small",
          "This quote has expired. Contact the artist for an updated offer."));
      }
      if (quote.can_respond === true && quote.status === "sent" &&
          !quote.is_expired && !hasAccepted) {
        const label = document.createElement("label");
        label.className = "customer-quote-confirm";
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.addEventListener("change", syncQuoteButtons);
        const span = renderText("span",
          "I have read this quote and agree to its exact scope, price and revisions. " +
          "Payment and any order confirmation will be handled separately.");
        label.append(checkbox,span);
        const actions = document.createElement("div");
        actions.className = "customer-quote-actions";
        const accept = document.createElement("button");
        accept.type = "button";
        accept.className = "primary-button";
        accept.textContent = "Accept quoted terms";
        accept.dataset.quoteAction = "accept";
        accept.disabled = true;
        accept.addEventListener("click",()=>respondToQuote(
          ref,quote.id,policyVersion,"accept",checkbox
        ));
        const decline = document.createElement("button");
        decline.type = "button";
        decline.className = "text-button";
        decline.textContent = "Decline quote";
        decline.dataset.quoteAction = "decline";
        decline.addEventListener("click",()=>respondToQuote(
          ref,quote.id,policyVersion,"decline",null
        ));
        actions.append(accept,decline);
        card.append(label,actions);
      }
      root.append(card);
    }
    syncQuoteButtons();
  }

  function showFact(id, value) {
    $(id).textContent = value === undefined || value === null || value === "" ?
      "Not specified" : String(value);
  }
  let detailRequestSequence = 0;
  let detailFullThread = false;
  async function withdrawRequest(ref,expectedStatus){
    if(detailMutationBusy()||state.selectedRef!==ref||!state.csrf)return;
    if(!window.confirm("Withdraw this unaccepted request? Outstanding offers will be closed. Your records remain available. This does not issue a refund."))return;
    state.quoteBusy=true;$("detail-withdraw").disabled=true;
    try{
      const response=await fetch("./api/request-withdraw.php",{method:"POST",credentials:"same-origin",cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify({ref,expected_status:expectedStatus,confirm_withdrawal:true})});
      if(response.status===401)return customerSessionExpired();
      const data=await response.json();
      if(!response.ok||!data.ok){$("detail-withdraw-status").textContent="Withdrawal not recorded. Refresh details before retrying.";return;}
      await openDetail(ref);await loadMyRequests();
      if(state.selectedRef===ref&&!leavingSession)$("detail-withdraw-status").textContent="Request withdrawn. No money moved.";
    }catch(_){$("detail-withdraw-status").textContent="Outcome uncertain. Refresh details before retrying.";}
    finally{state.quoteBusy=false;$("detail-withdraw").disabled=false;$("detail-refresh").disabled=false;}
  }

  async function openDetail(ref) {
    if (typeof ref !== "string" || !/^DMO-[0-9a-f]{20}$/.test(ref)) return;
    if (leavingSession || (detailMutationBusy() && state.selectedRef !== ref)) return;
    if(state.selectedRef!==null&&state.selectedRef!==ref&&!confirmDiscardDetailDrafts())return;
    const sequence = ++detailRequestSequence;
    const switching=state.selectedRef!==ref;
    if (switching) { clearDetailDrafts(); detailFullThread=false; }
    $("detail-thread-full").hidden = true;
    state.selectedRef = ref;
    const panel = $("my-request-detail");
    panel.hidden = false;
    panel.setAttribute("aria-busy","true");
    $("detail-content").hidden = true;
    $("detail-summary").hidden=true;$("detail-summary").removeAttribute("href");
    $("detail-withdraw").hidden=true;$("detail-withdraw").onclick=null;$("detail-withdraw-status").textContent="";
    for (const id of ["detail-status","detail-type","detail-options","detail-contact","detail-deadline","detail-brief","detail-policy"]) {
      $(id).textContent = "";
    }
    $("detail-messages").replaceChildren();
    $("detail-load-status").hidden = false;
    $("detail-load-status").textContent = "Loading private request details…";
    $("detail-refresh").disabled = true;
    $("detail-reply-form").hidden = true;
    $("detail-reply-send").disabled = true;
    $("detail-reference-form").hidden = true;
    $("detail-reference-list").replaceChildren();
    $("detail-quote-section").hidden = true;
    $("detail-quotes").replaceChildren();
    $("detail-order-section").hidden = true;
    $("detail-orders").replaceChildren();
    $("detail-quote-feedback").textContent = "";
    showFact("detail-ref","Loading " + ref + "...");
    if(switching)panel.scrollIntoView({behavior:"instant",block:"start"});
    try {
      const {response,body} = await getJSON(
        "./api/request.php?ref=" + encodeURIComponent(ref) + (detailFullThread ? "&thread=full" : "")
      );
      if (sequence !== detailRequestSequence || state.selectedRef !== ref) return;
      if (response.status === 401) return customerSessionExpired();
      if (!response.ok || !body.ok ||
          body.request?.public_ref !== ref) {
        throw new Error("Request unavailable");
      }
      const canReply = body.can_reply === true && !!state.csrf;
      $("detail-reply-form").hidden = !canReply;
      $("detail-reply-disabled").hidden = canReply;
      $("detail-reply-send").disabled = !canReply || state.messageBusy;
      const references = Array.isArray(body.references) ? body.references : [];
      const referenceRoot = $("detail-reference-list");
      referenceRoot.replaceChildren();
      for (const reference of references) {
        if (!Number.isSafeInteger(reference.id) || reference.id < 1) continue;
        const frame = document.createElement("a");
        frame.href = "./api/reference.php?id=" + reference.id + "&variant=display";
        frame.target = "_blank";
        frame.rel = "noopener noreferrer";
        frame.setAttribute("aria-label", "Open private reference image");
        const image = document.createElement("img");
        image.src = "./api/reference.php?id=" + reference.id + "&variant=thumb";
        image.alt = "Private reference image";
        image.loading = "lazy";
        frame.append(image);
        const item = document.createElement("div");
        item.className = "reference-item";
        item.append(frame);
        if (body.can_manage_references === true) {
          const remove = document.createElement("button");
          remove.type = "button";
          remove.className = "text-button";
          remove.textContent = "Remove";
          remove.setAttribute("aria-label", "Remove this private reference image");
          remove.addEventListener("click", () => deleteReference(ref, reference.id));
          item.append(remove);
        }
        referenceRoot.append(item);
      }
      if (!references.length) {
        referenceRoot.append(renderText("p","No reference images attached yet.","small"));
      }
      $("detail-reference-form").hidden = body.can_upload_references !== true;
      $("detail-reference-upload").disabled = body.can_upload_references !== true ||
        state.uploadBusy;
      syncReferenceResume();
      const r = body.request || {};
      $("detail-withdraw").hidden=body.can_withdraw!==true;
      $("detail-withdraw").onclick=()=>withdrawRequest(ref,r.status);
      renderCustomerQuotes(body.quotes || [], ref, r.policy_version || "");
      renderCustomerOrders(body.orders || [],Array.isArray(body.deliveries)?body.deliveries:[],Array.isArray(body.artwork_reviews)?body.artwork_reviews:[],Array.isArray(body.checkout_links)?body.checkout_links:[]);
      renderCustomerProgress(body);
      showFact("detail-ref",r.public_ref);
      showFact("detail-status",readableRequestStatus(r.workflow_stage || r.status));
      showFact("detail-type",labels[r.subject_type] || r.subject_type);
      const addons = Array.isArray(body.addons) ? body.addons : [];
      showFact("detail-options",
        [r.tier_name || "Custom",...addons].filter(Boolean).join(" · "));
      showFact("detail-contact",r.preferred_contact);
      showFact("detail-deadline",r.preferred_deadline);
      const policy=$("detail-policy");policy.replaceChildren();
      if(typeof r.policy_version==="string"&&/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/.test(r.policy_version)&&typeof r.policy_sha256==="string"&&/^[a-f0-9]{64}$/.test(r.policy_sha256)){
        const link=document.createElement("a");link.href="./policy.php?"+new URLSearchParams({version:r.policy_version,sha256:r.policy_sha256});link.target="_blank";link.rel="noopener noreferrer";link.textContent="Read version "+r.policy_version;policy.append(link);
      }else policy.textContent=r.policy_version?"Version "+r.policy_version+" (document receipt unavailable)":"Unavailable";
      showFact("detail-brief",r.brief);
      $("detail-thread-full").hidden = body.messages_have_older !== true;
      const root = $("detail-messages");
      root.replaceChildren();
      if (Array.isArray(body.messages) && body.messages.length) {
        root.append(renderText("h4","Conversation"));
        for (const m of body.messages) {
          const item = document.createElement("article");
          item.append(renderText("small",(m.sender_name || "User") +
            " · " + (m.created_at || "")),renderText("p",m.body));
          root.append(item);
        }
      }
      $("detail-summary").href="./commission-summary.php?ref="+encodeURIComponent(ref);
      $("detail-summary").hidden=false;
      $("detail-content").hidden = false;
      $("detail-load-status").hidden = true;
      updateDetailDraftWarning();
    } catch (_) {
      if (sequence !== detailRequestSequence || state.selectedRef !== ref) return;
      showFact("detail-ref","Unable to retrieve this request.");
      $("detail-load-status").textContent =
        "This request could not be loaded. Check your connection and try Refresh details.";
    } finally {
      if (sequence === detailRequestSequence && state.selectedRef === ref) {
        $("detail-refresh").disabled = state.quoteBusy;
        panel.removeAttribute("aria-busy");
      }
    }
  }

  $("refresh-requests").addEventListener("click",loadMyRequests);
  $("detail-refresh").addEventListener("click", () => {
    if (state.selectedRef && !detailMutationBusy()) openDetail(state.selectedRef);
  });
  $("detail-thread-full").addEventListener("click",()=>{
    if(!state.selectedRef||detailMutationBusy())return;
    detailFullThread=true;
    openDetail(state.selectedRef);
  });
  $("detail-close").addEventListener("click",() => {
    if (detailMutationBusy()||!confirmDiscardDetailDrafts()) return;
    clearDetailDrafts();
    detailRequestSequence++;
    state.selectedRef = null;
    $("my-request-detail").hidden = true;
    $("detail-content").hidden = true;
    $("detail-load-status").hidden = true;
  });



  async function deleteReference(ref, attachment_id) {
    if (detailMutationBusy() || !state.csrf || state.selectedRef !== ref ||
        !Number.isSafeInteger(attachment_id) || attachment_id < 1) return;
    if (!window.confirm("Remove this private reference image from the request?")) return;
    state.deleteBusy = true;
    $("detail-reference-feedback").textContent = "Removing reference image…";
    try {
      const response = await fetch("./api/reference-delete.php", {
        method:"POST", credentials:"same-origin", cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify({ref,attachment_id}),
      });
      if (response.status === 401) return customerSessionExpired();
      const data = await response.json();
      if (!response.ok || !data.ok) {
        $("detail-reference-feedback").textContent =
          "Reference couldn't be removed (" + (data.error || response.status) + ").";
        return;
      }
      await openDetail(ref);
      $("detail-reference-feedback").textContent =
        data.storage_cleanup_complete
          ? "Reference removed from your request."
          : "Reference removed from your request; private file cleanup is pending.";
    } catch (_) {
      $("detail-reference-feedback").textContent =
        "Connection interrupted. Refresh your references before retrying.";
    } finally {
      state.deleteBusy = false;
    }
  }

  $("detail-reference-form").addEventListener("submit",event=>{
    event.preventDefault();uploadReferences(Array.from($("detail-reference-files").files||[]));
  });
  $("detail-reference-resume").addEventListener("click",()=>uploadReferences([...remainingReferences]));
  async function uploadReferences(files){
    const ref=state.selectedRef;
    if(!ref||detailMutationBusy()||!state.csrf||($("detail-reference-form").hidden&&!files.some(file=>referenceUploadKeys.has(file))))return;
    if(!files.length||files.length>8||files.some(file=>file.size>4*1024*1024||file.size<1||
      !(["image/jpeg","image/png","image/webp"].includes(file.type)||(!file.type&&/\.(jpe?g|png|webp)$/i.test(file.name))))){
      $("detail-reference-feedback").textContent="Choose up to eight JPEG, PNG or WebP images, maximum 4 MB each.";
      return;
    }
    const progress=$("detail-reference-progress"),status=$("detail-reference-feedback");
    state.uploadBusy=true;referenceStopRequested=false;remainingReferences=[];
    $("detail-reference-files").disabled=true;$("detail-reference-upload").disabled=true;
    syncReferenceResume();
    progress.hidden=false;progress.max=files.length;progress.value=0;
    let attempted=0,saved=0,rejected=0,uncertain=0,reason="";
    try{
      for(let i=0;i<files.length;i++){
        if(referenceStopRequested||leavingSession){reason="Upload stopped.";break;}
        if(!referenceUploadKeys.has(files[i])){
          if($("detail-reference-form").hidden){reason="New reference uploads unavailable.";break;}
          referenceUploadKeys.set(files[i],newKey());
        }
        const payload=new FormData();payload.append("ref",ref);payload.append("reference",files[i]);payload.append("operation_key",referenceUploadKeys.get(files[i]));
        try{
          const response=await fetch("./api/request-attachment.php",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"X-DMO-CSRF":state.csrf},body:payload});
          if(response.status===401)return customerSessionExpired();
          const data=await response.json();
          if(response.ok&&data.ok===true&&Number.isSafeInteger(data.attachment_id)&&data.attachment_id>0)saved++;
          else if(response.status===429&&data.error==="too_many_uploads"){
            reason="Upload rate limit reached. Try remaining images later.";break;
          }else if(response.status>=400&&response.status<500&&data.ok===false){
            rejected++;reason="Upload stopped ("+(data.error||response.status)+").";
          }else{
            uncertain++;reason="Upload outcome not confirmed. Resume uses the original upload ID.";
          }
        }catch(_){
          uncertain++;reason="Connection interrupted. Resume uses the original upload ID.";
        }
        if(uncertain)break;
        attempted=i+1;progress.value=attempted;
        status.textContent="Processed "+attempted+" of "+files.length+" reference images.";
        if(reason)break;
      }
      if(leavingSession)return;
      remainingReferences=files.slice(attempted);
      $("detail-reference-files").value="";
      await openDetail(ref);
      if(leavingSession)return;
      status.textContent=(reason?reason+" ":"")+saved+" saved · "+rejected+" rejected · "+uncertain+" unconfirmed · "+remainingReferences.length+" remaining."+
        (remainingReferences.length&&$("detail-reference-form").hidden?" Uploads are unavailable until the request permits more references.":"");
    }finally{
      state.uploadBusy=false;referenceStopRequested=false;progress.hidden=true;
      if(!leavingSession){$("detail-reference-files").disabled=false;$("detail-reference-upload").disabled=$("detail-reference-form").hidden;syncReferenceResume();}
    }
  }

  $("detail-reply-form").addEventListener("submit", async event => {
    event.preventDefault();
    const ref = state.selectedRef;
    if (!ref || detailMutationBusy() || !$("detail-reply-form").reportValidity() ||
        !state.csrf) return;
    const body = $("detail-reply-body").value.trim();
    if (body.length < 2 || body.length > 3000) return;
    if(pendingReply&&pendingReply.ref===ref&&pendingReply.body!==body){
      if(!window.confirm("The previous message may already have been saved. Sending changed text creates a separate message. Continue?")){
        $("detail-reply-body").value=pendingReply.body;
        $("detail-reply-feedback").textContent="Original message restored. Retry unchanged to recover the earlier result.";
        updateDetailDraftWarning();return;
      }
    }
    if(!pendingReply||pendingReply.ref!==ref||pendingReply.body!==body)pendingReply={ref,body,key:newKey()};
    state.messageBusy = true;
    $("detail-reply-body").disabled=true;
    $("detail-reply-send").disabled = true;
    $("detail-reply-feedback").textContent = "Sending private message…";
    try {
      const response = await fetch("./api/request-message.php",{
        method:"POST",credentials:"same-origin",cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify({ref,body,operation_key:pendingReply.key}),
      });
      if (response.status === 401) return customerSessionExpired();
      const data = await response.json();
      if (!response.ok || !data.ok) {
        $("detail-reply-feedback").textContent = response.status === 429
          ? "Too many messages. Please retry later."
          : data.error === "conversation_closed" ? "This request is no longer accepting messages."
          : data.error === "conversation_limit" ? "This conversation has reached its storage limit. Contact the artist separately; do not send duplicate requests."
          : response.status>=500 ? "Message not confirmed. Retry the unchanged message to recover the result."
          : "Message could not be sent. Check the request and retry.";
        return;
      }
      pendingReply=null;
      $("detail-reply-body").value = "";
      await openDetail(ref);
      $("detail-reply-feedback").textContent =
        "Message saved privately for the artist. No email was sent.";
    } catch (_) {
      $("detail-reply-feedback").textContent =
        "Connection interrupted. Retry the unchanged message to safely recover the result.";
    } finally {
      state.messageBusy = false;
      $("detail-reply-body").disabled=false;
      updateDetailDraftWarning();
      $("detail-reply-send").disabled = $("detail-reply-form").hidden;
    }
  });

  function clearPendingRequestKey(){
    state.pendingKey=null;
    try{sessionStorage.removeItem("dmo_request_pending_key");}catch(_){}
    $("request-new-key").hidden=true;
    $("request-recovery-open").hidden=true;
    $("request-recovery-open").onclick=null;
    $("request-recovery-status").textContent="";
    syncReceiptRecovery();
  }
  $("request-recovery-check").addEventListener("click",async()=>{
    const key=state.pendingKey||storedRequestKey();
    if(!key||!state.csrf||receiptBusy||state.sending)return;
    receiptBusy=true;syncReceiptRecovery();
    const status=$("request-recovery-status"),open=$("request-recovery-open");
    open.hidden=true;status.textContent="Checking your previous submission…";
    try{
      const response=await fetch("./api/requests.php",{method:"POST",cache:"no-store",credentials:"same-origin",headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},body:JSON.stringify({action:"lookup_submission",idempotency_key:key})});
      if(response.status===401)return customerSessionExpired();
      const data=await response.json();
      if(!response.ok||data.ok!==true)throw Error("Receipt unavailable");
      if(data.found===true&&typeof data.receipt?.public_ref==="string"&&/^DMO-[0-9a-f]{20}$/.test(data.receipt.public_ref)){
        status.textContent="Existing request found: "+data.receipt.public_ref+". Review it before sending another request. No new request was created.";
        open.onclick=()=>openDetail(data.receipt.public_ref);
        await loadMyRequests();
        if(!leavingSession)open.hidden=false;
      }else if(data.found===false)status.textContent="No saved request was found for this submission ID. An unfinished upload may still be processing; wait for confirmation before starting a separate request.";
      else throw Error("Invalid receipt");
    }catch(_){status.textContent="Could not check the receipt. Your submission ID is retained; try again.";}
    finally{receiptBusy=false;syncReceiptRecovery();}
  });
  $("request-recovery-dismiss").addEventListener("click",()=>{
    if(state.sending||receiptBusy||!window.confirm("Clear the saved submission ID? Check your history first to avoid duplicate requests."))return;
    clearPendingRequestKey();
  });
  $("request-new-key").addEventListener("click",()=>{
    if(state.sending||receiptBusy||!window.confirm("Have you reviewed the earlier request? Use a new submission ID for a separate commission request?"))return;
    clearPendingRequestKey();
    feedback("A new request ID will be used. Your current form is preserved. Review it before submitting.");
  });
  $("request-form").addEventListener("submit",async event => {
    event.preventDefault();
    if (state.sending || receiptBusy || !state.service?.requests_open || !state.csrf) return;
    const form = $("request-form");
    refreshRequestDraftState();if (!form.reportValidity()) return;
    const brief = $("request-brief").value.trim();
    const addon_ids = [...$("addons-options").querySelectorAll("input:checked")]
      .map(node => Number(node.value));
    if (new TextEncoder().encode(brief).length < 30 || new TextEncoder().encode(brief).length > 6000 || addon_ids.length > 8) {
      feedback("Please describe your idea in 30–6000 UTF-8 bytes and choose no more than eight add-ons.",true);
      return;
    }
    const tierId = $("request-tier").value;
    const payload = {
      idempotency_key:requestKey(),
      tier_id:tierId ? Number(tierId) : null,
      addon_ids,
      subject_type:$("request-subject").value,
      brief,
      preferred_contact:$("request-contact").value,
      preferred_deadline:$("request-deadline").value || null,
      policy_version:state.service.policy_version,
      policy_sha256:state.service.policy_sha256,
      acknowledge_review:$("request-ack").checked === true,
    };
    const button = $("request-submit");
    state.sending = true;syncReceiptRecovery();
    const fields=[...form.querySelectorAll('input,select,textarea,button')].map(field=>[field,field.disabled]);
    for(const [field] of fields)field.disabled=true;
    button.disabled = true;
    feedback("Submitting your request securely…");
    try {
      const response = await fetch("./api/requests.php",{
        method:"POST",cache:"no-store",credentials:"same-origin",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify(payload),
      });
      if (response.status === 401) return customerSessionExpired();
      const body = await response.json();
      if (response.ok && body.ok) {
        clearPendingRequestKey();
        form.reset();requestFormBaseline=requestFormSnapshot();refreshRequestDraftState();
        feedback("Request " + body.public_ref +
          " received for artist review. No order or charge has been created.");
        await loadMyRequests();
        return;
      }
      if(response.status===409&&["request_key_conflict","request_retry_unverified"].includes(body.error)){
        const ref=typeof body.public_ref==="string"&&/^DMO-[a-f0-9]{20}$/.test(body.public_ref)?body.public_ref:"the earlier request";
        feedback("Submission not repeated. "+ref+" already exists, but this form could not be confirmed as the same submission. Review your request history before starting a separate request. Your form is preserved.",true);
        $("request-new-key").hidden=false;
        await loadMyRequests();
        return;
      }
      const errors = {
        commissions_closed:"New commission submissions are closed. Your existing requests are unaffected.",
        commissions_at_capacity:"The artist is currently at capacity. Your form has been preserved; please try again later.",
        too_many_requests:"Too many attempts. Please try again later.",
        daily_request_limit:"Maximum three new requests per 24 hours.",
        customer_login_required:"Please sign in again to submit a request.",
        invalid_deadline:"Choose a valid future deadline.",
        catalog_no_longer_available:"The published catalog is no longer available. Refresh and choose again, or send a custom idea.",
        tier_no_longer_available:"The selected package is no longer available. Refresh and choose again.",
        addon_no_longer_available:"An add-on is no longer available. Refresh and choose again.",
        addon_not_available_for_tier:"An add-on is not offered with this package.",
        policy_acknowledgement_required:"Confirm the request acknowledgement before submitting.",
      };
      feedback(errors[body.error] ||
        "Unable to submit (HTTP " + response.status + "). Your form has not been cleared.",true);
    } catch (_) {
      feedback("Connection interrupted. Your form is preserved; retrying uses the same request ID to prevent duplicates.",true);
    } finally {
      state.sending = false;syncReceiptRecovery();
      for(const [field,disabled] of fields)field.disabled=disabled;
      if(!leavingSession){$('request-tier').dispatchEvent(new Event('change'));refreshRequestDraftState();}
      button.disabled = !state.service?.requests_open||leavingSession;
    }
  });

  async function optionalStatus(url) {
    try {
      const {response,body}=await getJSON(url);
      return response.ok&&body?.ok===true?body:null;
    }catch(_){return null;}
  }
  async function init() {
    $("request-deadline").min=localTomorrow();
    // Public catalog and intake failures must not prevent access to a valid
    // private session. A new request still requires positive current gates.
    const [service,catalog,auth,me]=await Promise.all([
      optionalStatus("./api/request-status.php"),
      optionalStatus("./api/catalog.php"),
      optionalStatus("./api/auth/status.php"),
      optionalStatus("./api/auth/me.php"),
    ]);
    if(leavingSession)return;
    state.auth=auth;
    state.catalog=catalog;
    state.service=service?{...service}:{requests_open:false,intake_reason:"not_available"};
    const validPolicy=typeof state.service.policy_version==="string"&&/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/.test(state.service.policy_version)&&typeof state.service.policy_sha256==="string"&&/^[a-f0-9]{64}$/.test(state.service.policy_sha256);
    const intakeUnavailable=!service||!catalog||(service.requests_open===true&&!validPolicy);
    state.service.requests_open=service?.requests_open===true&&!intakeUnavailable&&auth?.auth_available===true;
    if(state.service.requests_open)$("request-policy-link").href="./policy.php?"+new URLSearchParams({version:state.service.policy_version,sha256:state.service.policy_sha256});
    if(me?.authenticated===true&&me.user?.role==="customer"&&typeof me.csrf_token==="string"&&me.csrf_token){
      state.account=me.user;state.csrf=me.csrf_token;
    }
    if(state.account){
      $("customer-workspace").hidden=false;
      syncReceiptRecovery();
      $("request-create-panel").hidden=!state.service.requests_open;
      $("request-closed").hidden=true;
      $("request-signin").hidden=true;
      if(state.service.requests_open){
        renderCatalog();$("request-submit").disabled=false;
        displayStatus("Signed in · New commission requests are open for artist review.");
      }else if(intakeUnavailable||!auth){
        displayStatus("New-request availability could not be checked. Your existing commissions remain accessible.",true);
      }else{
        displayStatus(state.service.intake_reason==="at_capacity"?"The artist is currently at capacity. You can still review your existing requests.":state.service.intake_reason==="paused"?"New requests are temporarily paused. Your existing commissions are unaffected.":"New commissions are closed. You can still review your existing requests.");
      }
      loadMyRequests();
    }else if(state.service.requests_open&&me?.authenticated!==true){
      $("request-signin").hidden=false;$("request-closed").hidden=true;
      if(validPreferredPackage)$("request-signin-link").href="./account.html?"+new URLSearchParams({package:validPreferredPackage});
      displayStatus("Customer sign-in is required before submitting an idea.");
    }else{
      $("request-closed").hidden=false;$("request-signin").hidden=true;
      if(intakeUnavailable||!auth)displayStatus("New-request availability could not be checked. Please try again later.",true);
      else displayStatus(state.service.intake_reason==="at_capacity"?"The artist is currently at capacity. Please check back later.":state.service.intake_reason==="paused"?"The artist has temporarily paused new commissions. Please check back later.":"Commission intake is closed while the artist prepares the service.");
    }
  }
  init();
})();
