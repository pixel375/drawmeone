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
    }
  }

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
    if (name === "packages") packagesLoad().catch(() => showError("Packages unavailable."));
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
      const { response } = await post("login.php", {
        email: $("login-email").value,
        password: $("login-password").value,
        turnstile_token: token,
      });
      $("login-password").value = "";
      window.turnstile.reset(widget);
      if (response.ok && await checkSession()) return;
      $("login-message").textContent =
        response.status === 429 ? "Too many attempts. Please try later."
          : "Sign-in not accepted. Please check your details.";
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
