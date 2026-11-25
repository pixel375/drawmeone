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

  let intakeVersion=null,intakeBusy=false;
  function renderIntake(data){
    const form=$("intake-form"),notice=$("intake-status");
    const configured=data?.control_enabled===true;
    form.hidden=!configured;intakeVersion=configured&&Number.isSafeInteger(data.version)?data.version:null;
    if(!configured){notice.textContent=data?.requests_open?"Open under existing launch configuration. Artist capacity control is not enabled.":"New commissions are closed. Artist capacity control is not enabled.";return;}
    $("intake-mode").value=data.paused?"paused":"open";
    $("intake-capacity").value=String(data.capacity);
    const label={open:"Accepting new requests",paused:"Paused by artist",at_capacity:"At capacity",not_available:"Commercial launch gates remain closed"};
    notice.textContent=(label[data.reason]||"Unavailable")+" · "+data.active_requests+" / "+data.capacity+" active requests.";
    $("intake-save").disabled=intakeBusy||intakeVersion===null;
  }
  $("intake-form").addEventListener("submit",async event=>{
    event.preventDefault();
    if(intakeBusy||intakeVersion===null||!csrf||!$("intake-form").reportValidity())return;
    const paused=$("intake-mode").value==="paused",capacity=Number($("intake-capacity").value);
    if(!Number.isSafeInteger(capacity)||capacity<1||capacity>100)return;
    if(!window.confirm("Save these intake settings? Existing orders and commercial launch gates remain unchanged."))return;
    intakeBusy=true;
    for(const field of $("intake-form").querySelectorAll("input,select,button"))field.disabled=true;
    try{
      const {response,data}=await post("intake.php",{expected_version:intakeVersion,paused,capacity,confirm_change:true},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){
        $("intake-status").textContent=response.status===409?"Settings changed elsewhere. Refresh readiness before retrying.":"Settings not confirmed. Refresh readiness before retrying.";
        intakeVersion=null;return;
      }
      renderIntake(data.intake);
    }catch(_){intakeVersion=null;$("intake-status").textContent="Outcome uncertain. Refresh readiness before retrying.";}
    finally{
      intakeBusy=false;for(const field of $("intake-form").querySelectorAll("input,select,button"))field.disabled=false;
      $("intake-save").disabled=intakeVersion===null;
    }
  });
  async function overview() {
    const { response, data } = await get("overview.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) return showError("Unable to load live studio counts.");
    if(!intakeBusy)renderIntake(data.intake);
    const counters = {
      "stat-requests": data.counts?.requests_for_review,
      "stat-orders": data.counts?.active_orders,
      "stat-due": data.counts?.orders_due_soon,
      "stat-quote-followup": data.counts?.quotes_to_follow_up,
      "stat-payments": data.counts?.payments_to_verify,
      "stat-holds": data.counts?.payment_holds,
      "stat-messages": data.counts?.messages_last_seven_days,
    };
    for (const [name, value] of Object.entries(counters)) {
      $(name).textContent = Number.isSafeInteger(value) && value >= 0
        ? value.toLocaleString() : "—";
    }
    const integrity=$("integrity-checks");integrity.replaceChildren();
    const checksMap=data.integrity?.checks;
    const labels={order_quote_mismatch:"Order / accepted quote mismatch",paid_without_evidence:"Paid orders without eligible evidence",paid_order_exception_conflict:"Paid flag conflicts with payment hold or refund",duplicate_verified_payment:"Multiple verified payments per order",multiple_orders:"Multiple orders per request",draft_order_mismatch:"Draft attached to unrelated request",delivery_missing_draft:"Missing or inconsistent delivery record",delivery_state_mismatch:"Released artwork with incomplete order",completed_without_delivery:"Completed order without deliverable",review_draft_mismatch:"Pending or approved proof without matching draft",review_pending_multiple:"Multiple pending proofs",review_allowance_exceeded:"Included revision allowance exceeded",delivery_approval_mismatch:"Released drawing differs from approved proof"};
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
      chip.textContent = status === "ready" ? "Ready" :
        status === "planned" ? "Planned" : "Not enabled";
      top.append(name,chip);
      const detail = document.createElement("p");
      detail.className = "small";
      detail.textContent = String(check.detail || "");
      card.append(top,detail);
      list.append(card);
    }
    $("launch-status").textContent = checks.length
      ? totals.ready + " of " + checks.length +
        " checks ready · " + totals.blocked + " not enabled · " +
        totals.planned + " pending implementation"
      : "No readiness information is currently available.";
  }

  $("launch-refresh").addEventListener("click", () => {
    $("launch-status").textContent = "Refreshing live launch requirements…";
    overview().catch(() => {
      $("launch-status").textContent = "Unable to refresh. Please try again.";
    });
  });

  let requestListPage = 1;
  let requestListQuery = "";
  let requestListFilter = "all";
  let requestListGeneration = 0;

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
    const generation = ++requestListGeneration;
    const list = $("request-list");
    const status = $("request-list-status");
    const previous = $("request-list-previous");
    const next = $("request-list-next");
    previous.disabled = next.disabled = true;
    status.textContent = "Loading requests…";
    list.replaceChildren();
    let response, data;
    try {
      const query = new URLSearchParams({page:String(requestListPage),
        q:requestListQuery,filter:requestListFilter});
      ({response,data} = await get("requests.php?" + query));
    } catch (_) {
      if (generation === requestListGeneration) status.textContent = "Unable to load requests. Try Refresh.";
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
    status.textContent = "Page " + requestListPage + " · " + data.requests.length + " request(s)";
    if (data.requests.length === 0) {
      empty(list, requestListQuery || requestListFilter !== "all"
        ? "No requests match your search." : "No commission requests on this page.");
      return;
    }
    for (const row of data.requests) {
      record(list,
        (row.public_ref || "REQUEST") + " · " + workflowLabel(row.workflow_stage || row.status),
        row.customer_name || "Customer",
        [row.tier_name || "Custom", row.subject_type || "", row.order_deadline ? "Agreed date: " + row.order_deadline : "", row.brief_excerpt || ""]
          .filter(Boolean).join(" · "),
        row.submitted_at || ""
      );
      const open = document.createElement("button");
      open.type = "button";
      open.className = "quiet request-open";
      open.textContent = "View brief & drafts";
      open.addEventListener("click", () => openRequest(row.id).catch(() => {
        showError("Unable to open commission request.");
      }));
      list.lastElementChild?.appendChild(open);
    }
  }

  document.querySelectorAll("[data-request-queue]").forEach(button=>button.addEventListener("click",()=>{
    requestListPage=1;
    requestListQuery="";
    requestListFilter=button.dataset.requestQueue;
    $("request-list-query").value="";
    $("request-list-filter").value=requestListFilter;
    selectPanel("requests");
  }));
  $("request-list-filters").addEventListener("submit", event => {
    event.preventDefault();
    requestListPage = 1;
    requestListQuery = $("request-list-query").value.trim();
    requestListFilter = $("request-list-filter").value;
    requests();
  });
  $("request-list-previous").addEventListener("click", () => {
    if (requestListPage > 1) { requestListPage--; requests(); }
  });
  $("request-list-next").addEventListener("click", () => {
    requestListPage++; requests();
  });
  $("request-list-refresh").addEventListener("click", () => requests());

  let messagePage=1, messageGeneration=0;
  async function messages() {
    const generation=++messageGeneration;
    const list=$("message-list"), status=$("message-list-status");
    const previous=$("message-list-previous"),next=$("message-list-next");
    previous.disabled=next.disabled=true;
    status.textContent="Loading messages…";
    list.replaceChildren();
    try {
      const query=new URLSearchParams({page:String(messagePage),filter:$("message-list-filter").value});
      const {response,data}=await get("messages.php?"+query);
      if(generation!==messageGeneration)return;
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok||!Array.isArray(data.messages))throw Error("unavailable");
      previous.disabled=messagePage===1;
      next.disabled=data.has_more!==true;
      status.textContent="Page "+messagePage+" · "+data.messages.length+" message(s)";
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
          openRequest(row.request_id).catch(() => showError("Request unavailable."));
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
  $("message-list-refresh").addEventListener("click",()=>messages());
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
    for (const item of records) {
      if (!Number.isSafeInteger(item.id) || item.id < 1) continue;
      const frame = document.createElement("a");
      frame.href = "../api/reference.php?id=" + item.id + "&variant=display";
      frame.target = "_blank";
      frame.rel = "noopener noreferrer";
      frame.setAttribute("aria-label", "View customer reference image privately");
      const img = document.createElement("img");
      img.src = "../api/reference.php?id=" + item.id + "&variant=thumb";
      img.alt = "Private customer reference";
      img.loading = "lazy";
      frame.append(img);
      root.append(frame);
    }
  }

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

  function renderPaymentRecords(orders,records,enabled,exceptionsEnabled=false) {
    const root=$("request-payment-records");root.replaceChildren();
    if(!enabled)root.append(textNode("p","Manual reconciliation is disabled until approved.","small"));
    for(const order of orders||[]){
      const rows=(records||[]).filter(p=>p.order_id===order.id);
      const card=document.createElement("article");card.className="notification-card";
      card.append(textNode("strong","Order #"+order.id+" · "+formatQuoteMoney(order.total_minor,order.currency)));
      for(const p of rows)card.append(textNode("p","Payment #"+p.id+" · "+p.method+" · "+formatQuoteMoney(p.amount_minor,p.currency)+" · "+p.status,"small"));
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
    const prompt=body.action==="verify_payment_evidence"?"Confirm this exact external payment as received? This does not charge money or release artwork.":body.action==="reject_payment_evidence"?"Reject this evidence? It will remain in the audit history.":"Record pending evidence only? The order stays unpaid until reconciliation.";
    if(body.action!=="set_payment_exception"&&!window.confirm(prompt))return;
    const id=selectedRequestId;requestBusy=true;updateRequestButtons();
    try{
      const {response,data}=await post("request-actions.php",{...body,request_id:id},csrf);
      if(response.status===401)return sessionExpired();
      if(!response.ok||!data.ok){requestText("request-detail-status","Payment record not changed ("+(data.error||response.status)+").");return;}
      await openRequest(id);await requests();
      requestText("request-detail-status",body.action==="set_payment_exception"?"Payment exception recorded: "+data.payment_status+". No money moved.":data.status==="verified"?"Manual reconciliation recorded. No money moved or artwork released.":"Payment evidence "+data.status+". No money moved.");
    }catch(_){requestText("request-detail-status","Payment update not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }

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
      if(!["awaiting_arrangement","in_progress","ready_for_delivery","queued","drawing","awaiting_feedback","revision"].includes(order.status))continue;
      if((drafts||[]).filter(d=>d.order_id===order.id).length>=4)continue;
      const form=document.createElement("form");
      form.className="delivery-draft-form";
      const label=textNode("label","Stage artwork for order #"+order.id);
      const file=document.createElement("input");file.type="file";file.required=true;
      file.accept="image/jpeg,image/png,image/webp";label.append(file);
      const button=textNode("button","Stage privately","quiet");button.type="submit";
      form.append(label,textNode("p","JPEG, PNG or WebP; up to 8 MB. Re-encoded to high-quality WebP without embedded metadata.","small"),button);
      form.addEventListener("submit",event=>{
        event.preventDefault();
        if(requestBusy||!file.files?.length)return;
        stageDeliveryDraft(order.id,file.files[0]);
      });
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
  async function stageDeliveryDraft(orderId,file){
    if(requestBusy||selectedRequestId===null)return;
    if(file.size>8*1024*1024){requestText("request-detail-status","Draft must be 8 MB or smaller.");return;}
    const id=selectedRequestId,body=new FormData();
    body.append("request_id",String(id));body.append("order_id",String(orderId));body.append("artwork",file);
    requestBusy=true;updateRequestButtons();
    requestText("request-detail-status","Uploading and processing private artwork…");
    try{
      const response=await fetch("../api/admin/delivery-draft.php",{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"X-DMO-CSRF":csrf},body});
      if(response.status===401)return sessionExpired();
      const data=await response.json();
      if(!response.ok||!data.ok){requestText("request-detail-status","Draft not staged ("+(data.error||response.status)+").");return;}
      await openRequest(id);
      requestText("request-detail-status","Artwork staged privately. Customer access and payment status unchanged.");
    }catch(_){requestText("request-detail-status","Draft upload not confirmed. Refresh before retrying.");}
    finally{requestBusy=false;updateRequestButtons();}
  }

  function renderNotifications(options,enabled) {
    const root=$("request-notifications");
    root.replaceChildren();
    if(!enabled)root.append(textNode("p","Customer email notices are disabled until approved.","small"));
    if(!Array.isArray(options)||!options.length){
      root.append(textNode("p","No current quote or unpaid order notice is available.","small"));
      return;
    }
    const statusLabels={not_sent:"Not sent",accepted:"Accepted by mail server; inbox delivery not confirmed",
      failed:"Transport did not confirm acceptance; automatic retry disabled",
      uncertain:"Attempt reserved; outcome uncertain; automatic retry disabled"};
    for(const option of options){
      const card=document.createElement("article");
      card.className="notification-card";
      card.append(textNode("strong",(option.kind==="quote"?"Quote":"Order")+" #"+option.event_id),
        textNode("p",statusLabels[option.status]||"Unknown status","small"));
      if(option.can_send===true&&enabled){
        const button=textNode("button","Send account-update email","quiet");
        button.type="button";
        button.disabled=requestBusy;
        button.addEventListener("click",()=>sendCustomerNotice(option));
        card.append(button);
      }else if(enabled&&option.status==="not_sent"){
        card.append(textNode("p","Not eligible: check customer email preference, verification and current offer/order status.","small"));
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

  function updateRequestButtons() {
    $("request-detail-close").disabled = requestBusy;
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
    $("quote-add-line").disabled = !quoteDraftAllowed || requestBusy;
  }

  function resetRequestDrafts() {
    $("request-note-form").reset();
    $("request-message-form").reset();
    $("request-quote-form").reset();
    $("quote-lines").replaceChildren();
    addQuoteLine();
  }

  function clearRequestContent() {
    for (const id of ["request-detail-ref","request-detail-title","request-customer",
      "request-tier","request-contact","request-deadline","request-brief-text"]) {
      requestText(id, "—");
    }
    for (const id of ["request-thread","request-quotes","request-references","request-orders","request-notifications","request-delivery-drafts","request-payment-records"]) {
      $(id).replaceChildren();
    }
  }

  async function openRequest(id) {
    const parsed = Number(id);
    if (!Number.isSafeInteger(parsed) || parsed < 1) return;
    if (requestBusy && selectedRequestId !== parsed) return;
    const generation = ++requestLoadGeneration;
    const switching = selectedRequestId !== parsed;
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
    if (switching) { requestFullThread=false; resetRequestDrafts(); }
    clearRequestContent();
    requestText("request-policy-status","");
    updateRequestButtons();
    $("request-detail").hidden = false;
    requestText("request-detail-status", "Loading complete request...");
    $("request-detail").scrollIntoView({behavior:"smooth",block:"start"});
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
        const link=document.createElement("a");link.href="../policy.html?"+new URLSearchParams({version:info.policy_version,sha256:info.policy_sha256});link.textContent=" Read recorded agreement";link.target="_blank";link.rel="noopener noreferrer";policy.append(link);
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
    renderQuotes(item.quotes);
    renderRequestReferences(item.references);
    renderAdminOrders(item.orders, item.quotes);
    renderNotifications(data.notification_options,data.notifications_enabled===true);
    renderDeliveryDrafts(item.orders,data.delivery_drafts,data.delivery_staging_enabled===true,data.fulfillment_options||[],data.artwork_reviews||[]);
    renderPaymentRecords(item.orders,data.payment_records,data.manual_payments_enabled===true,data.payment_exceptions_enabled===true);
    requestText("request-detail-status", requestWriteEnabled
      ? "Admin draft editing available. Nothing is sent to the customer."
      : "Viewing only. Private note and quote creation are disabled on the server.");
    updateRequestButtons();
  }

  $("request-thread-full").addEventListener("click",()=>{
    if(selectedRequestId===null||requestBusy)return;
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

  $("request-detail-close").addEventListener("click", () => {
    if (requestBusy) return;
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
    updateRequestButtons();
  });

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
      if (!requestWriteEnabled || requestBusy) return;
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
    $("quote-add-line").disabled = !requestWriteEnabled || requestBusy ||
      $("quote-lines").childElementCount >= 12;
  }

  $("quote-add-line").addEventListener("click", () => addQuoteLine());
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
    requestBusy = true;
    updateRequestButtons();
    try {
      const {response, data} = await post("request-actions.php", {
        action:"add_private_note", request_id:id, body,
      }, csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status",
          "Note not saved (" + (data.error || response.status) + ").");
        return;
      }
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
    if (!window.confirm("Send this message to the customer's private account?")) return;
    requestBusy = true;
    updateRequestButtons();
    try {
      const {response, data} = await post("request-actions.php", {
        action:"send_customer_message",request_id:id,body,
      },csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status", "Message not sent (" +
          (data.error || response.status) + ").");
        return;
      }
      $("request-message-body").value = "";
      await openRequest(id);
      requestText("request-detail-status",
        "Customer-visible message saved. No email or payment was sent.");
    } catch (_) {
      requestText("request-detail-status",
        "Connection interrupted. Check conversation before retrying to avoid duplicates.");
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
        !Number.isInteger(revision_allowance) ||
        revision_allowance < 0 || revision_allowance > 25 || !lines ||
        lines.reduce((sum,line)=>sum+line.amount_minor,0) > 10000000) {
      requestText("request-detail-status", "Complete the scope, revisions and valid line prices.");
      return;
    }
    const id = selectedRequestId;
    if (!window.confirm("Create a NEW internal quote version? It will not be sent.")) return;
    requestBusy = true;
    updateRequestButtons();
    try {
      const {response, data} = await post("request-actions.php", {
        action:"create_quote_draft", request_id:id,
        currency:$("quote-currency").value, scope, revision_allowance, lines,
      }, csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        requestText("request-detail-status",
          "Draft not saved (" + (data.error || response.status) + ").");
        return;
      }
      await openRequest(id);
      requestText("request-detail-status",
        "Draft quote version " + data.version + " saved privately. No customer contact.");
    } catch (_) {
      requestText("request-detail-status", "Connection problem. Draft save not confirmed.");
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

  function artworkState(message) {
    $("artwork-library-status").textContent = message;
  }

  function artworkControls() {
    const checked = [...document.querySelectorAll("#artwork-list .artwork-select:checked")];
    const canWrite = artworkWriteEnabled && !artworkBusy;
    $("artwork-upload").disabled = !canWrite;
    $("artwork-publish-selected").disabled = !canWrite ||
      !checked.some(input => input.dataset.visibility === "draft");
    $("artwork-unpublish-selected").disabled = !canWrite ||
      !checked.some(input => input.dataset.visibility === "published");
  }

  function artworkSelectCount() {
    return document.querySelectorAll("#artwork-list .artwork-select:checked").length;
  }

  function renderArtwork() {
    const target = $("artwork-list");
    target.replaceChildren();
    if (!artworkRecords.length) {
      $("artwork-visible-count").textContent = "No artworks uploaded";
      $("artwork-select-all").checked = false;
      empty(target, "No real artwork uploaded yet. Choose drawing files above.");
      artworkControls();
      return;
    }
    const filter = $("artwork-filter").value;
    const visible = artworkRecords.filter(work => filter === "all" ||
      (filter === "draft" ? work.visibility === "draft" : work.visibility === "published"));
    $("artwork-visible-count").textContent = "Showing " + visible.length + " of " +
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
      const body = document.createElement("div");
      body.className = "artwork-card-info";
      const title = document.createElement("strong");
      title.textContent = "Drawing #" + work.id;
      const state = document.createElement("small");
      state.textContent = work.visibility === "published" ? "PUBLIC GALLERY" : "PRIVATE DRAFT";
      const select = document.createElement("input");
      select.type = "checkbox";
      select.className = "artwork-select";
      select.value = String(work.id);
      select.disabled = !artworkWriteEnabled || artworkBusy;
      select.dataset.visibility = work.visibility === "published" ? "published" : "draft";
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
        visibility:work.visibility === "published" ? "draft" : "published",
      }));
      const feature = document.createElement("button");
      feature.className = "quiet";
      feature.type = "button";
      feature.textContent = work.featured ? "★ Featured" : "☆ Feature";
      feature.disabled = !artworkWriteEnabled || artworkBusy;
      feature.addEventListener("click", () => updateArtwork(work.id,"set_featured",{
        featured:!work.featured,
      }));
      actions.append(toggle,feature);
      const order = document.createElement("div");
      order.className = "artwork-sort";
      const input = document.createElement("input");
      input.type = "number";
      input.min = "-10000";
      input.max = "10000";
      input.step = "1";
      input.value = String(work.sort_order);
      input.disabled = !artworkWriteEnabled;
      input.setAttribute("aria-label", "Sort order for drawing " + work.id);
      const save = document.createElement("button");
      save.type = "button";
      save.className = "quiet";
      save.textContent = "Set order";
      save.disabled = !artworkWriteEnabled || artworkBusy;
      save.addEventListener("click", () => {
        const value = Number(input.value);
        if (!Number.isInteger(value) || value < -10000 || value > 10000) {
          artworkState("Sort order must be a whole number between -10000 and 10000.");
          return;
        }
        updateArtwork(work.id,"set_order",{sort_order:value});
      });
      order.append(input,save);
      body.append(title,state,pick,actions,order);
      card.append(preview,body);
      target.append(card);
    }
    artworkControls();
  }

  async function artworksLoad() {
    artworkState("Loading real artwork from the database…");
    const {response,data} = await get("artworks.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) {
      artworkState("Artwork library unavailable (HTTP " + response.status + ").");
      return;
    }
    artworkRecords = Array.isArray(data.artworks) ? data.artworks : [];
    artworkWriteEnabled = data.write_enabled === true;
    $("artwork-select-all").checked = false;
    renderArtwork();
    artworkState(artworkRecords.length + " artwork entries · " +
      (artworkWriteEnabled ? "Uploads and publishing available" :
        "Uploads locked until private server setup is complete"));
  }

  async function updateArtwork(id, action, payload) {
    if (!artworkWriteEnabled || artworkBusy) return;
    if (action === "set_visibility" && payload.visibility === "published" &&
        !window.confirm("Publish this drawing to the public portfolio?")) return;
    artworkBusy = true;
    artworkControls();
    try {
      const {response,data} = await post("artworks.php",{
        id,action,...payload,
      },csrf);
      if (response.status === 401) return sessionExpired();
      if (!response.ok || !data.ok) {
        artworkState("Change not saved: " + (data.error || "HTTP " + response.status));
        return;
      }
      await artworksLoad();
      artworkState("Artwork #" + id + " updated.");
    } catch (_) {
      artworkState("Connection failed. Change not confirmed.");
    } finally {
      artworkBusy = false;
      renderArtwork();
    }
  }

  $("artwork-refresh").addEventListener("click", () => {
    artworksLoad().catch(() => artworkState("Cannot refresh artwork library."));
  });

  $("artwork-filter").addEventListener("change", () => {
    $("artwork-select-all").checked = false;
    renderArtwork();
  });

  $("artwork-select-all").addEventListener("change", event => {
    document.querySelectorAll("#artwork-list .artwork-select").forEach(input => {
      if (!input.disabled) input.checked = event.target.checked;
    });
    artworkControls();
  });

  $("artwork-publish-selected").addEventListener("click", async () => {
    if (!artworkWriteEnabled || artworkBusy) return;
    const ids = [...document.querySelectorAll("#artwork-list .artwork-select:checked")]
      .filter(x => x.dataset.visibility === "draft")
      .map(x=>Number(x.value)).filter(x=>Number.isSafeInteger(x) && x > 0);
    if (!ids.length || !window.confirm(
      "Publish " + ids.length + " selected drawings to the public portfolio?"
    )) return;
    artworkBusy = true;
    artworkControls();
    let completed = 0, failures = 0;
    for (const id of ids) {
      try {
        const {response,data} = await post("artworks.php",{
          id,action:"set_visibility",visibility:"published",
        },csrf);
        if (response.status === 401) {sessionExpired();break;}
        if (response.ok && data.ok) completed++;
        else failures++;
      } catch (_) {
        failures++;
      }
      artworkState("Publishing: " + (completed + failures) + " / " + ids.length);
    }
    artworkBusy = false;
    await artworksLoad();
    artworkState("Published " + completed + " drawing(s)" +
      (failures ? "; " + failures + " failed." : "."));
  });

  $("artwork-unpublish-selected").addEventListener("click", async () => {
    if (!artworkWriteEnabled || artworkBusy) return;
    const ids = [...document.querySelectorAll("#artwork-list .artwork-select:checked")]
      .filter(x => x.dataset.visibility === "published")
      .map(x => Number(x.value)).filter(x => Number.isSafeInteger(x) && x > 0);
    if (!ids.length || !window.confirm(
      "Remove " + ids.length + " selected drawings from the public gallery? " +
      "Images stay in your private library and can be republished later."
    )) return;
    artworkBusy = true;
    artworkControls();
    let completed = 0, failures = 0;
    for (const id of ids) {
      try {
        const {response,data} = await post("artworks.php", {
          id,action:"set_visibility",visibility:"draft",
        },csrf);
        if (response.status === 401) {sessionExpired();break;}
        if (response.ok && data.ok) completed++;
        else failures++;
      } catch (_) {
        failures++;
      }
      artworkState("Unpublishing: " + (completed + failures) + " / " + ids.length);
    }
    artworkBusy = false;
    await artworksLoad();
    artworkState("Unpublished " + completed + " drawing(s)" +
      (failures ? "; " + failures + " failed." : ".") +
      " Originals remain private and are not deleted.");
  });

  $("artwork-upload").addEventListener("click", async () => {
    if (!artworkWriteEnabled || artworkBusy) return;
    const selected = [...$("artwork-files").files];
    if (!selected.length) {
      $("artwork-upload-message").textContent = "Select artwork images first.";
      return;
    }
    artworkBusy = true;
    artworkControls();
    const progress = $("artwork-progress");
    progress.hidden = false;
    progress.max = selected.length;
    progress.value = 0;
    const report = $("artwork-upload-results");
    report.replaceChildren();
    let success = 0;
    for (let i = 0; i < selected.length; i++) {
      const file = selected[i];
      const entry = document.createElement("li");
      entry.textContent = (i + 1) + ". " + file.name + " — ";
      report.append(entry);
      if (!["image/jpeg","image/png","image/webp"].includes(file.type) ||
          file.size > 8 * 1024 * 1024 || file.size < 1) {
        entry.textContent += "Skipped (unsupported format or file too large).";
        progress.value = i + 1;
        continue;
      }
      try {
        const body = new FormData();
        body.append("artwork",file);
        const response = await fetch(api + "artwork-upload.php",{
          method:"POST", credentials:"same-origin",cache:"no-store",
          headers:{"X-DMO-CSRF":csrf},body,
        });
        const data = await response.json();
        if (response.status === 401) {sessionExpired();break;}
        if (response.ok && data.ok) {
          success++;
          entry.textContent += "Saved privately as draft #" + data.artwork_id;
        } else {
          entry.textContent += "Failed (" + (data.error || response.status) + ")";
        }
      } catch (_) {
        entry.textContent += "Network error; upload not confirmed.";
      }
      progress.value = i + 1;
      $("artwork-upload-message").textContent =
        "Processed " + (i + 1) + " of " + selected.length + " images.";
    }
    artworkBusy = false;
    $("artwork-files").value = "";
    await artworksLoad();
    $("artwork-upload-message").textContent = success + " of " + selected.length +
      " drawings saved as unpublished drafts. Review before publishing.";
  });


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
    const snapshot = data.draft?.exists
      ? data.draft.settings : (data.published?.settings || {});
    for (const [key, id] of Object.entries(designIds)) {
      if (typeof snapshot[key] === "string" &&
          Array.from($(id).options).some(option => option.value === snapshot[key])) {
        $(id).value = snapshot[key];
      }
    }
    const version = data.published?.exists
      ? "Published v" + data.published.version : "Not published yet";
    $("design-state").textContent = "Draft v" + designDraftVersion +
      " · " + version + " · " +
      (designWriteEnabled ? "Editing available" : "Editing locked until setup is complete");
    $("design-save").disabled = !designWriteEnabled;
    $("design-publish").disabled = !designWriteEnabled || designDraftVersion === 0;
  }

  async function designLoad() {
    $("design-state").textContent = "Loading draft and published settings…";
    const { response, data } = await get("design.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) {
      $("design-state").textContent = "Settings are unavailable. Please retry.";
      return;
    }
    designDisplay(data);
  }

  async function designAction(action) {
    if (!designWriteEnabled || !csrf) return;
    const button = action === "save_draft" ? $("design-save") : $("design-publish");
    button.disabled = true;
    $("design-state").textContent = action === "save_draft"
      ? "Saving design draft…" : "Publishing saved design draft…";
    try {
      const body = {
        action,
        expected_version: designDraftVersion,
      };
      if (action === "save_draft") body.settings = designValues();
      const { response, data } = await post("design.php", body, csrf);
      if (response.status === 409) {
        $("design-state").textContent =
          "This draft changed in another tab. Reload before saving again.";
        return;
      }
      if (!response.ok || !data.ok) {
        $("design-state").textContent = "Unable to save settings. Check server configuration.";
        return;
      }
      await designLoad();
    } catch (_) {
      $("design-state").textContent = "Connection failed. No changes confirmed.";
    } finally {
      button.disabled = !designWriteEnabled;
      if (action === "publish") {
        $("design-publish").disabled = !designWriteEnabled || designDraftVersion === 0;
      }
    }
  }

  $("design-form").addEventListener("submit", event => {
    event.preventDefault();
    designAction("save_draft");
  });
  $("design-reload").addEventListener("click", () => {
    designLoad().catch(() => {
      $("design-state").textContent = "Connection failed. Please try again.";
    });
  });
  $("design-publish").addEventListener("click", () => {
    if (!designWriteEnabled) return;
    if (window.confirm("Publish the last saved design draft? Unsaved changes will not be included.")) {
      designAction("publish");
    }
  });

  // Commission catalog editor. Displayed amounts are dollars, but the API
  // always receives exact integer minor units; never use floating arithmetic.
  let packageCatalog = { tiers: [], addons: [] };
  let packageSelected = { kind: "tier", id: null };
  let packageWrite = false;
  let packagePublish = false;
  let packageBusy = false;

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

  function packageSelect(kind, id = null) {
    if (!["tier", "addon"].includes(kind)) return;
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
    $("package-visibility").disabled = !packagePublish || packageBusy || !record;
    renderPackageLists();
  }

  async function packagesLoad() {
    packageShow("Loading existing commission tiers and add-ons…");
    const { response, data } = await get("packages.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) {
      packageShow("Unable to load commission packages. Please retry.", true);
      return;
    }
    packageWrite = data.write_enabled === true;
    packagePublish = data.publish_enabled === true;
    packageCatalog = data.catalog || {tiers: [],addons: []};
    const found = packageRows(packageSelected.kind).some(
      row => row.id === packageSelected.id
    );
    packageSelect(packageSelected.kind, found ? packageSelected.id : null);
    packageShow(packageWrite
      ? (packagePublish
        ? "Editing and publication available. Confirm rates before publishing."
        : "Unpublished editing available. Public publishing remains locked.")
      : "Editing locked until the private backend switch is enabled.");
  }

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
  $("package-refresh").addEventListener("click", () => packagesLoad().catch(() => {
    packageShow("Unable to refresh packages.",true);
  }));
  $("package-form").addEventListener("submit", async event => {
    event.preventDefault();
    if (!packageWrite || packageBusy) return;
    const item = packagePayload();
    if (!item) return;
    const current = packageRows(packageSelected.kind)
      .find(row => row.id === packageSelected.id);
    if (current?.is_active === true &&
        !window.confirm("This changes a published price or package immediately. Continue?")) {
      return;
    }
    packageBusy = true;
    $("package-save").disabled = true;
    packageShow("Saving package without publishing…");
    try {
      const action = packageSelected.kind === "tier" ? "save_tier" : "save_addon";
      const { response, data } = await post("packages.php", {action,item}, csrf);
      if (!response.ok || !data.ok) {
        packageShow(data.error === "duplicate_slug_or_conflict"
          ? "That slug is already used. Choose another one."
          : "Unable to save: " + (data.error || "server refused the change"), true);
        return;
      }
      packageSelected.id = data.id;
      await packagesLoad();
      packageShow("Saved. New items are unpublished until explicitly activated.");
    } catch (_) {
      packageShow("Connection problem. Save was not confirmed.", true);
    } finally {
      packageBusy = false;
      packageSelect(packageSelected.kind, packageSelected.id);
    }
  });

  $("package-visibility").addEventListener("click", async () => {
    if (!packagePublish || packageBusy || packageSelected.id === null) return;
    const row = packageRows(packageSelected.kind)
      .find(x => x.id === packageSelected.id);
    if (!row) return;
    const active = !row.is_active;
    const name = row.name || row.slug;
    if (!window.confirm(active
      ? "Publish " + name + " to the public catalog? Confirm its prices are approved."
      : "Remove " + name + " from the public catalog?")) return;
    packageBusy = true;
    $("package-visibility").disabled = true;
    try {
      const { response, data } = await post("packages.php", {
        action:"set_active",kind:packageSelected.kind,
        id:packageSelected.id,active,
      },csrf);
      if (!response.ok || !data.ok) {
        packageShow("Unable to change visibility: " +
          (data.error || "not approved"), true);
        return;
      }
      await packagesLoad();
      packageShow(active ? "Published to public catalog." : "Unpublished from catalog.");
    } catch (_) {
      packageShow("Connection problem. Visibility was not confirmed.", true);
    } finally {
      packageBusy = false;
      packageSelect(packageSelected.kind,packageSelected.id);
    }
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
    if (name === "settings") designLoad().catch(() => showError("Settings unavailable."));
    if (name === "settings") emailDiagnosticsLoad().catch(() => {
      $("mail-check-status").textContent = "Email diagnostics unavailable.";
    });
    if (name === "packages") packagesLoad().catch(() => showError("Packages unavailable."));
    if (name === "artwork") artworksLoad().catch(() => showError("Artwork unavailable."));
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
      const fresh = reason === "expired" || reason === "signed-out";
      if (fresh) {
        url.searchParams.delete("session");
        history.replaceState(null, "", url.href);
      }
      if (!fresh && await checkSession()) return;
      show("sign-in");
      if (fresh) {
        $("login-message").textContent = reason === "expired"
          ? "Your session has expired. Please sign in again." : "Signed out.";
      }
      enableCaptcha(siteKey);
    } catch (_) {
      $("closed-status").textContent =
        "Unable to verify admin availability. The private studio remains closed.";
    }
  }
  init();
})();
