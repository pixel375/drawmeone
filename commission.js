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
  function customerSessionExpired() {
    if (leavingSession) return;
    leavingSession = true;
    state.account = null;
    state.csrf = "";
    state.selectedRef = null;
    state.requests = [];
    ++requestsFetchSequence;
    ++detailRequestSequence;
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
  function clearDetailDrafts() {
    $("detail-reply-body").value = "";
    $("detail-reference-files").value = "";
    $("detail-reference-feedback").textContent = "";
    $("detail-reply-feedback").textContent = "";
    $("detail-reference-progress").hidden = true;
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
    const describeTier = () => {
      const tier = tiers.find(x => String(x.id) === tierSelect.value);
      $("tier-note").textContent = tier ?
        (tier.description || "Estimated price is subject to an individual quote.")
        : "Choose a package or describe a custom idea.";
      // If tier/add-on compatibility is supplied, hide incompatible add-ons.
      const supported = Array.isArray(tier?.available_addon_ids)
        ? new Set(tier.available_addon_ids.map(String)) : null;
      for (const checkbox of addonWrap.querySelectorAll('input[type="checkbox"]')) {
        const available = !supported || supported.has(checkbox.value);
        checkbox.disabled = !available;
        if (!available) checkbox.checked = false;
        checkbox.closest(".addon-entry").hidden = !available;
      }
    };
    tierSelect.addEventListener("change", describeTier);
    describeTier();
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
  function requestKey() {
    if (state.pendingKey) return state.pendingKey;
    let stored = null;
    try { stored = sessionStorage.getItem("dmo_request_pending_key"); } catch (_) {}
    const valid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    state.pendingKey = stored && valid.test(stored) ? stored : newKey();
    try { sessionStorage.setItem("dmo_request_pending_key",state.pendingKey); } catch (_) {}
    return state.pendingKey;
  }

  const closedRequestStatuses = new Set([
    "cancelled","canceled","rejected","declined","completed",
    "closed","archived","refunded",
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
    const matches = rows.filter(item => {
      const closed = closedRequestStatuses.has(item.workflow_stage || item.status);
      if (filter === "closed" && !closed) return false;
      if (filter === "active" && closed) return false;
      return !query || [
        item.public_ref, item.tier_name, labels[item.subject_type],
        readableRequestStatus(item.workflow_stage || item.status),
      ].some(value => String(value || "").toLocaleLowerCase().includes(query));
    });
    target.replaceChildren();
    $("request-history-count").textContent = rows.length
      ? "Showing " + matches.length + " of " + rows.length + (historyHasMore ? " loaded requests · Older requests available" : " requests")
      : "No requests yet";
    if (!rows.length) {
      target.append(renderText("p",
        "You don't have any commission requests yet.","small"));
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

  let requestsFetchSequence = 0;
  let historyPage = 0;
  let historyHasMore = false;
  let historyBusy = false;
  function syncHistoryControls() {
    $("request-history-older").hidden = !historyHasMore;
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
      target.replaceChildren(renderText("p","Loading your requests…","small"));
    }
    syncHistoryControls();
    $("request-history-feedback").textContent = "";
    $("request-history-count").textContent = append ? "Loading older requests…" : "Refreshing request history…";
    try {
      const {response, body} = await getJSON("./api/requests.php?page=" + page);
      if (response.status === 401) return customerSessionExpired();
      if (sequence !== requestsFetchSequence) return;
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
  $("request-history-search").addEventListener("input", renderRequestHistory);
  $("request-history-filter").addEventListener("change", renderRequestHistory);


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
      await openDetail(ref);await loadMyRequests();
    }catch(_){status.textContent="Decision not confirmed. Refresh before retrying.";}
    finally{state.quoteBusy=false;for(const field of box.querySelectorAll("button,textarea"))field.disabled=false;}
  }

  function renderCustomerOrders(orders,deliveries=[],reviews=[]) {
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
          (labels[order.status] || "Under review")),
        renderText("small","Quote total: " +
          (money(order.total_minor,order.currency) || "Not available")),
        renderText("small","Payment status: " +
          (order.payment_status || "Not verified")),
        renderText("small","Order recorded: " + (order.created_at || "Not available"))
      );
      card.append(renderText("small","Agreed delivery date: "+(order.agreed_deadline||"Not agreed yet")));
      if (order.payment_status === "paid") card.append(renderText("p","The artist has recorded receipt after manual reconciliation. This is not an automated provider receipt.","small"));
      if (order.payment_status === "unverified") {
        card.append(renderText("p",
          "Payment has not been verified. Please use the agreed contact method " +
          "with the artist; this page is not a payment request.", "small"));
      }
      const review=reviews.find(r=>r.order_id===order.id);
      if(review?.latest){
        const latest=review.latest;
        const box=document.createElement("section");box.className="customer-proof";
        box.append(renderText("strong","Artwork review · Proof "+latest.round),
          renderText("p","Included revisions used: "+review.revisions_used+" / "+review.revision_allowance,"small"),
          renderText("p",latest.status==="pending"?"Please review the proof.":latest.status==="approved"?"You approved this proof.":"Revision requested.","small"));
        if(latest.feedback)box.append(renderText("p",latest.feedback,"small"));
        if(latest.status==="pending"&&Number.isSafeInteger(latest.id)&&latest.id>0){
          const img=document.createElement("img");img.src="./api/artwork-review.php?id="+latest.id;img.alt="Watermarked artwork proof";img.loading="lazy";
          box.append(img,renderText("p","This is a reduced watermarked proof, not your final download.","small"));
          const approve=renderText("button","Approve proof","text-button");approve.type="button";
          const status=renderText("p","","small");status.setAttribute("role","status");
          approve.addEventListener("click",()=>respondArtworkReview(latest.id,"approve","",status,box));
          box.append(approve);
          if(review.revisions_used<review.revision_allowance){
            const form=document.createElement("form");const label=renderText("label","What should the artist change?");
            const note=document.createElement("textarea");note.required=true;note.minLength=3;note.maxLength=500;label.append(note);
            const revise=renderText("button","Request included revision","text-button");revise.type="submit";
            form.append(label,revise);form.addEventListener("submit",event=>{event.preventDefault();respondArtworkReview(latest.id,"revise",note.value.trim(),status,box);});
            box.append(form);
          }else box.append(renderText("p","Included revisions are exhausted. Contact the artist separately about additional changes.","small"));
          box.append(status);
        }
        card.append(box);
      }
      for(const delivery of deliveries.filter(d=>d.order_id===order.id)){
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
  async function openDetail(ref) {
    if (typeof ref !== "string" || !/^DMO-[0-9a-f]{20}$/.test(ref)) return;
    if (leavingSession || (detailMutationBusy() && state.selectedRef !== ref)) return;
    const sequence = ++detailRequestSequence;
    if (state.selectedRef !== ref) { clearDetailDrafts(); detailFullThread=false; }
    $("detail-thread-full").hidden = true;
    state.selectedRef = ref;
    const panel = $("my-request-detail");
    panel.hidden = false;
    panel.setAttribute("aria-busy","true");
    $("detail-content").hidden = true;
    for (const id of ["detail-status","detail-type","detail-options","detail-contact","detail-deadline","detail-brief"]) {
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
    panel.scrollIntoView({behavior:"smooth",block:"start"});
    try {
      const {response,body} = await getJSON(
        "./api/request.php?ref=" + encodeURIComponent(ref) + (detailFullThread ? "&thread=full" : "")
      );
      if (response.status === 401) return customerSessionExpired();
      if (sequence !== detailRequestSequence || state.selectedRef !== ref) return;
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
      const r = body.request || {};
      renderCustomerQuotes(body.quotes || [], ref, r.policy_version || "");
      renderCustomerOrders(body.orders || [],Array.isArray(body.deliveries)?body.deliveries:[],Array.isArray(body.artwork_reviews)?body.artwork_reviews:[]);
      showFact("detail-ref",r.public_ref);
      showFact("detail-status",readableRequestStatus(r.workflow_stage || r.status));
      showFact("detail-type",labels[r.subject_type] || r.subject_type);
      const addons = Array.isArray(body.addons) ? body.addons : [];
      showFact("detail-options",
        [r.tier_name || "Custom",...addons].filter(Boolean).join(" · "));
      showFact("detail-contact",r.preferred_contact);
      showFact("detail-deadline",r.preferred_deadline);
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
      $("detail-content").hidden = false;
      $("detail-load-status").hidden = true;
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
    if (detailMutationBusy()) return;
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

  $("detail-reference-form").addEventListener("submit", async event => {
    event.preventDefault();
    const ref = state.selectedRef;
    if (!ref || detailMutationBusy() || !state.csrf) return;
    const files = Array.from($("detail-reference-files").files || []);
    if (!files.length || files.length > 8 ||
        files.some(file => file.size > 4 * 1024 * 1024 || file.size < 1 ||
          !["image/jpeg","image/png","image/webp"].includes(file.type))) {
      $("detail-reference-feedback").textContent =
        "Choose up to eight JPEG, PNG or WebP images, maximum 4 MB each.";
      return;
    }
    const progress = $("detail-reference-progress");
    state.uploadBusy = true;
    $("detail-reference-upload").disabled = true;
    progress.hidden = false;
    progress.max = files.length; progress.value = 0;
    try {
      for (const file of files) {
        const payload = new FormData();
        payload.append("ref", ref);
        payload.append("reference", file);
        const response = await fetch("./api/request-attachment.php", {
          method:"POST", credentials:"same-origin", cache:"no-store",
          headers:{"X-DMO-CSRF":state.csrf},body:payload,
        });
        if (response.status === 401) return customerSessionExpired();
        const data = await response.json();
        if (!response.ok || !data.ok) {
          $("detail-reference-feedback").textContent =
            "Upload stopped (" + (data.error || response.status) +
            "). Earlier images may already have uploaded. Refresh to check before retrying.";
          await openDetail(ref);
          return;
        }
        progress.value += 1;
        $("detail-reference-feedback").textContent =
          "Uploaded " + progress.value + " of " + files.length + " reference images.";
      }
      $("detail-reference-files").value = "";
      await openDetail(ref);
      $("detail-reference-feedback").textContent =
        "Private reference images saved. They are visible only to you and the artist.";
    } catch (_) {
      $("detail-reference-feedback").textContent =
        "Connection lost. Check the reference list before retrying.";
    } finally {
      state.uploadBusy = false;
      progress.hidden = true;
      $("detail-reference-upload").disabled = $("detail-reference-form").hidden;
    }
  });

  $("detail-reply-form").addEventListener("submit", async event => {
    event.preventDefault();
    const ref = state.selectedRef;
    if (!ref || detailMutationBusy() || !$("detail-reply-form").reportValidity() ||
        !state.csrf) return;
    const body = $("detail-reply-body").value.trim();
    if (body.length < 2 || body.length > 3000) return;
    state.messageBusy = true;
    $("detail-reply-send").disabled = true;
    $("detail-reply-feedback").textContent = "Sending private message…";
    try {
      const response = await fetch("./api/request-message.php",{
        method:"POST",credentials:"same-origin",cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify({ref,body}),
      });
      if (response.status === 401) return customerSessionExpired();
      const data = await response.json();
      if (!response.ok || !data.ok) {
        $("detail-reply-feedback").textContent = response.status === 429
          ? "Too many messages. Please retry later."
          : (data.error === "conversation_closed"
            ? "This request is no longer accepting messages."
            : "Message could not be sent. Please try again.");
        return;
      }
      $("detail-reply-body").value = "";
      await openDetail(ref);
      $("detail-reply-feedback").textContent =
        "Message saved privately for the artist. No email was sent.";
    } catch (_) {
      $("detail-reply-feedback").textContent =
        "Connection interrupted. Refresh the conversation before retrying.";
    } finally {
      state.messageBusy = false;
      $("detail-reply-send").disabled = $("detail-reply-form").hidden;
    }
  });

  $("request-form").addEventListener("submit",async event => {
    event.preventDefault();
    if (state.sending || !state.service?.requests_open || !state.csrf) return;
    const form = $("request-form");
    if (!form.reportValidity()) return;
    const brief = $("request-brief").value.trim();
    const addon_ids = [...$("addons-options").querySelectorAll("input:checked")]
      .map(node => Number(node.value));
    if (brief.length < 30 || brief.length > 6000 || addon_ids.length > 8) {
      feedback("Please describe your idea in 30–6000 characters and choose no more than eight add-ons.",true);
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
      acknowledge_review:$("request-ack").checked === true,
    };
    const button = $("request-submit");
    state.sending = true;
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
        try { sessionStorage.removeItem("dmo_request_pending_key"); } catch (_) {}
        state.pendingKey = null;
        form.reset();
        feedback("Request " + body.public_ref +
          " received for artist review. No order or charge has been created.");
        await loadMyRequests();
        return;
      }
      const errors = {
        commissions_closed:"New commission submissions are closed.",
        too_many_requests:"Too many attempts. Please try again later.",
        daily_request_limit:"Maximum three new requests per 24 hours.",
        customer_login_required:"Please sign in again to submit a request.",
        invalid_deadline:"Choose a valid future deadline.",
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
      state.sending = false;
      button.disabled = !state.service?.requests_open;
    }
  });

  async function init() {
    $("request-deadline").min = localTomorrow();
    try {
      const [s,c,a] = await Promise.all([
        getJSON("./api/request-status.php"),
        getJSON("./api/catalog.php"),
        getJSON("./api/auth/status.php"),
      ]);
      if (!s.response.ok || !s.body.ok ||
          !c.response.ok || !c.body.ok ||
          !a.response.ok || !a.body.ok) {
        throw new Error("Site status unavailable");
      }
      state.service = s.body;
      state.catalog = c.body;
      state.auth = a.body;

      if (state.auth.auth_available) {
        const me = await getJSON("./api/auth/me.php");
        if (me.response.ok && me.body.authenticated &&
            me.body.user?.role === "customer" &&
            typeof me.body.csrf_token === "string") {
          state.account = me.body.user;
          state.csrf = me.body.csrf_token;
        }
      }

      if (state.account) {
        $("customer-workspace").hidden = false;
        $("request-create-panel").hidden = !state.service.requests_open;
        $("request-closed").hidden = true;
        $("request-signin").hidden = true;
        if (state.service.requests_open) {
          renderCatalog();
          $("request-submit").disabled = false;
          displayStatus("Signed in · New commission requests are open for artist review.");
        } else {
          displayStatus("New commissions are closed. You can still review your existing requests.");
        }
        loadMyRequests();
      } else if (state.service.requests_open) {
        $("request-signin").hidden = false;
        $("request-closed").hidden = true;
        displayStatus("Customer sign-in is required before submitting an idea.");
      } else {
        $("request-closed").hidden = false;
        $("request-signin").hidden = true;
        displayStatus("Commission intake is closed while the artist prepares the service.");
      }
    } catch (_) {
      $("request-closed").hidden = false;
      $("request-signin").hidden = true;
      displayStatus("The commission service is currently unavailable. Please return later.",true);
    }
  }
  init();
})();
