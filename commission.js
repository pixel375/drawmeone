"use strict";
(() => {
  const $ = id => document.getElementById(id);
  const state = {
    service: null, auth: null, catalog: null, account: null, csrf: "",
    sending: false, pendingKey: null, selectedRef: null, messageBusy: false,
    uploadBusy: false,
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

  async function loadMyRequests() {
    const target = $("my-request-list");
    target.replaceChildren(renderText("p","Loading your requests…","small"));
    try {
      const {response, body} = await getJSON("./api/requests.php");
      if (!response.ok || !body.ok) throw new Error("Requests unavailable");
      target.replaceChildren();
      const rows = Array.isArray(body.requests) ? body.requests : [];
      if (!rows.length) {
        target.append(renderText("p","You don't have any commission requests yet.","small"));
        return;
      }
      for (const item of rows) {
        const card = document.createElement("article");
        card.className = "request-list-item";
        const detail = document.createElement("div");
        detail.append(
          renderText("strong",item.public_ref),
          renderText("small",(item.tier_name || labels[item.subject_type] || "Custom") +
            " · " + (item.status || "submitted")),
          renderText("small","Submitted " + (item.submitted_at || ""))
        );
        const button = document.createElement("button");
        button.type = "button";
        button.className = "text-button";
        button.textContent = "View details";
        button.addEventListener("click", () => openDetail(item.public_ref));
        card.append(detail,button);
        target.append(card);
      }
    } catch (_) {
      target.replaceChildren(
        renderText("p","Couldn't load your requests. Try refreshing.","small")
      );
    }
  }

  function showFact(id, value) {
    $(id).textContent = value === undefined || value === null || value === "" ?
      "Not specified" : String(value);
  }
  async function openDetail(ref) {
    if (typeof ref !== "string" || !/^DMO-[0-9a-f]{20}$/.test(ref)) return;
    state.selectedRef = ref;
    const panel = $("my-request-detail");
    panel.hidden = false;
    $("detail-reply-form").hidden = true;
    $("detail-reply-send").disabled = true;
    $("detail-reference-form").hidden = true;
    $("detail-reference-list").replaceChildren();
    showFact("detail-ref","Loading " + ref + "...");
    panel.scrollIntoView({behavior:"smooth",block:"start"});
    try {
      const {response,body} = await getJSON(
        "./api/request.php?ref=" + encodeURIComponent(ref)
      );
      if (!response.ok || !body.ok) throw new Error("Request unavailable");
      if (state.selectedRef !== ref) return;
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
      showFact("detail-ref",r.public_ref);
      showFact("detail-status",r.status);
      showFact("detail-type",labels[r.subject_type] || r.subject_type);
      const addons = Array.isArray(body.addons) ? body.addons : [];
      showFact("detail-options",
        [r.tier_name || "Custom",...addons].filter(Boolean).join(" · "));
      showFact("detail-contact",r.preferred_contact);
      showFact("detail-deadline",r.preferred_deadline);
      showFact("detail-brief",r.brief);
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
    } catch (_) {
      showFact("detail-ref","Unable to retrieve this request.");
    }
  }

  $("refresh-requests").addEventListener("click",loadMyRequests);
  $("detail-close").addEventListener("click",() => {
    state.selectedRef = null;
    $("my-request-detail").hidden = true;
  });



  async function deleteReference(ref, attachment_id) {
    if (!state.csrf || state.selectedRef !== ref ||
        !Number.isSafeInteger(attachment_id) || attachment_id < 1) return;
    if (!window.confirm("Remove this private reference image from the request?")) return;
    $("detail-reference-feedback").textContent = "Removing reference image…";
    try {
      const response = await fetch("./api/reference-delete.php", {
        method:"POST", credentials:"same-origin", cache:"no-store",
        headers:{"Content-Type":"application/json","X-DMO-CSRF":state.csrf},
        body:JSON.stringify({ref,attachment_id}),
      });
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
    }
  }

  $("detail-reference-form").addEventListener("submit", async event => {
    event.preventDefault();
    const ref = state.selectedRef;
    if (!ref || state.uploadBusy || !state.csrf) return;
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
    if (!ref || state.messageBusy || !$("detail-reply-form").reportValidity() ||
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
