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

  async function overview() {
    const { response, data } = await get("overview.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) return showError("Unable to load live studio counts.");
    const counters = {
      "stat-requests": data.counts?.requests_for_review,
      "stat-orders": data.counts?.active_orders,
      "stat-payments": data.counts?.payments_to_verify,
      "stat-messages": data.counts?.messages_last_seven_days,
    };
    for (const [name, value] of Object.entries(counters)) {
      $(name).textContent = Number.isSafeInteger(value) && value >= 0
        ? value.toLocaleString() : "—";
    }
  }

  async function requests() {
    const list = $("request-list");
    const { response, data } = await get("requests.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) return showError("Unable to load requests.");
    list.replaceChildren();
    if (!Array.isArray(data.requests) || data.requests.length === 0) {
      empty(list, "No commission requests yet. Submissions are still disabled.");
      return;
    }
    for (const row of data.requests) {
      record(list,
        (row.public_ref || "REQUEST") + " · " + (row.status || ""),
        row.customer_name || "Customer",
        [row.tier_name || "Custom", row.subject_type || "", row.brief_excerpt || ""]
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

  async function messages() {
    const list = $("message-list");
    const { response, data } = await get("messages.php");
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok) return showError("Unable to load messages.");
    list.replaceChildren();
    if (!Array.isArray(data.messages) || data.messages.length === 0) {
      empty(list, "No messages yet. Private customer conversations will appear here.");
      return;
    }
    for (const row of data.messages) {
      record(list,
        (row.request_ref || "MESSAGE") +
          (Number(row.is_internal) === 1 ? " · INTERNAL NOTE" : ""),
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
  }


  // Admin-only commission review. All user-controlled content is inserted with
  // textContent, never innerHTML. No public messages or quote sending here.
  let selectedRequestId = null;
  let requestWriteEnabled = false;
  let requestMessagingEnabled = false;
  let requestBusy = false;

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

  function renderQuotes(records) {
    const root = $("request-quotes");
    root.replaceChildren();
    if (!Array.isArray(records) || records.length === 0) {
      root.append(textNode("p", "No quotes prepared yet.", "small"));
      return;
    }
    for (const quote of records) {
      const card = document.createElement("article");
      card.className = "request-quote-card";
      card.append(
        textNode("strong", "Version " + quote.version_number +
          " · " + (quote.status || "draft") +
          " · " + formatQuoteMoney(quote.total_minor,quote.currency)),
        textNode("p", quote.scope_description || ""),
        textNode("small", "Revisions included: " + quote.revision_allowance +
          " · Created: " + (quote.created_at || ""))
      );
      if (quote.status === "draft") {
        const copy = document.createElement("button");
        copy.type = "button";
        copy.className = "quiet request-open";
        copy.textContent = "Use as starting point for new version";
        copy.addEventListener("click", () => {
          if (!requestWriteEnabled) return;
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

  function updateRequestButtons() {
    $("request-note-save").disabled = !requestWriteEnabled || requestBusy ||
      selectedRequestId === null;
    $("request-message-send").disabled = !requestMessagingEnabled || requestBusy ||
      selectedRequestId === null;
    $("quote-save").disabled = !requestWriteEnabled || requestBusy ||
      selectedRequestId === null;
    $("quote-add-line").disabled = !requestWriteEnabled || requestBusy;
  }

  async function openRequest(id) {
    const parsed = Number(id);
    if (!Number.isSafeInteger(parsed) || parsed < 1) return;
    selectedRequestId = parsed;
    requestWriteEnabled = false;
    requestMessagingEnabled = false;
    $("request-message-form").hidden = true;
    updateRequestButtons();
    $("request-detail").hidden = false;
    requestText("request-detail-status", "Loading complete request...");
    $("request-detail").scrollIntoView({behavior:"smooth",block:"start"});
    const {response, data} = await get("request.php?id=" + encodeURIComponent(parsed));
    if (response.status === 401) return sessionExpired();
    if (!response.ok || !data.ok || !data.detail) {
      requestText("request-detail-status", "Unable to load request: HTTP " + response.status);
      return;
    }
    if (selectedRequestId !== parsed) return;
    const item = data.detail;
    const info = item.request || {};
    requestWriteEnabled = data.admin_writes_enabled === true;
    requestMessagingEnabled = data.customer_messaging_enabled === true;
    $("request-message-form").hidden = !requestMessagingEnabled;
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
    requestText("request-detail-status", requestWriteEnabled
      ? "Admin draft editing available. Nothing is sent to the customer."
      : "Viewing only. Private note and quote creation are disabled on the server.");
    updateRequestButtons();
  }

  $("request-detail-close").addEventListener("click", () => {
    selectedRequestId = null;
    requestWriteEnabled = false;
    requestMessagingEnabled = false;
    $("request-detail").hidden = true;
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
    if (!requestWriteEnabled || requestBusy || selectedRequestId === null) return;
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
    $("artwork-upload").disabled = !artworkWriteEnabled || artworkBusy;
    $("artwork-publish-selected").disabled = !artworkWriteEnabled || artworkBusy ||
      !document.querySelector("#artwork-list .artwork-select:checked");
  }

  function artworkSelectCount() {
    return document.querySelectorAll("#artwork-list .artwork-select:checked").length;
  }

  function renderArtwork() {
    const target = $("artwork-list");
    target.replaceChildren();
    if (!artworkRecords.length) {
      const note = document.createElement("p");
      note.className = "empty-state";
      note.textContent = "No real artwork uploaded yet. Choose drawing files above.";
      target.append(note);
      artworkControls();
      return;
    }
    for (const work of artworkRecords) {
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
      select.disabled = !artworkWriteEnabled || work.visibility !== "draft";
      select.setAttribute("aria-label", "Select draft drawing " + work.id);
      select.addEventListener("change", artworkControls);
      const pick = document.createElement("label");
      pick.className = "artwork-pick";
      pick.append(select,document.createTextNode(" Select for bulk publish"));
      pick.hidden = work.visibility !== "draft";
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

  $("artwork-select-all").addEventListener("change", event => {
    document.querySelectorAll("#artwork-list .artwork-select").forEach(input => {
      if (!input.disabled) input.checked = event.target.checked;
    });
    artworkControls();
  });

  $("artwork-publish-selected").addEventListener("click", async () => {
    if (!artworkWriteEnabled || artworkBusy) return;
    const ids = [...document.querySelectorAll("#artwork-list .artwork-select:checked")]
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

  function sessionExpired() {
    csrf = "";
    show("sign-in");
    $("login-message").textContent = "Your session has expired. Please sign in again.";
    if (window.turnstile && widget !== null) window.turnstile.reset(widget);
  }

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
      const { response } = await post("logout.php", {}, csrf);
      if (!response.ok) throw new Error("logout_failed");
      csrf = "";
      show("sign-in");
      $("login-message").textContent = "Signed out.";
      if (window.turnstile && widget !== null) window.turnstile.reset(widget);
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
      if (await checkSession()) return;
      show("sign-in");
      enableCaptcha(siteKey);
    } catch (_) {
      $("closed-status").textContent =
        "Unable to verify admin availability. The private studio remains closed.";
    }
  }
  init();
})();
