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
