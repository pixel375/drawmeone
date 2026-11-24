<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'self'; " .
       "script-src 'self' https://challenges.cloudflare.com; " .
       "style-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; " .
       "img-src 'self' data: https://challenges.cloudflare.com; " .
       "connect-src 'self' https://challenges.cloudflare.com; " .
       "frame-src https://challenges.cloudflare.com; frame-ancestors 'none';");
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#151317">
<title>Artist's Desk · Draw Me One</title>
<link rel="stylesheet" href="./panel.css?v=26">
<script defer src="./panel.js?v=24"></script>
</head>
<body>
<a class="skip" href="#main">Skip to dashboard</a>
<header class="masthead">
  <a class="logo" href="../index.html">drawmeone<span>.</span></a>
  <span class="desk-tag">ARTIST'S DESK</span>
  <a class="return" href="../index.html">← Return to portfolio</a>
</header>
<main id="main" tabindex="-1">
  <section id="closed" class="welcome">
    <span class="overline">BACKEND / ARTIST ACCESS</span>
    <h1>Where the work<br><em>comes together.</em></h1>
    <p>We're preparing a private studio workspace. Customer requests, messages, and artwork management are not open yet.</p>
    <div class="notice" role="status" id="closed-status">Checking admin availability…</div>
  </section>

  <section id="sign-in" class="login-layout" hidden>
    <div class="welcome">
      <span class="overline">YOUR PRIVATE STUDIO</span>
      <h1>Welcome<br><em>back, artist.</em></h1>
      <p>One simple password-protected place to manage your artwork and commissions.</p>
    </div>
    <form id="login-form" class="login-card" autocomplete="on" novalidate>
      <h2>Artist sign in</h2>
      <label for="login-email">Admin email</label>
      <input id="login-email" type="email" name="email" maxlength="254" autocomplete="username" required>
      <label for="login-password">Password</label>
      <input id="login-password" type="password" name="password" maxlength="128" autocomplete="current-password" required>
      <div id="admin-captcha" class="captcha"></div>
      <button id="login-button" class="primary" type="submit" disabled>Open Artist's Desk →</button>
      <p class="small" id="login-message" role="status" aria-live="polite">The artist account is separate from customer accounts.</p>
    </form>
  </section>

  <section id="workspace" class="workspace" hidden>
    <div class="workspace-top">
      <div>
        <span class="overline">YOUR STUDIO · LIVE DATABASE</span>
        <h1>Artist's <em>Desk.</em></h1>
        <p id="welcome-admin">Private workspace. Numbers reflect actual records—not demonstration data.</p>
      </div>
      <button id="logout-button" type="button" class="quiet">Sign out</button>
    </div>
    <nav class="nav-tabs" aria-label="Admin workspace">
      <button type="button" data-view="overview" aria-current="page">Overview</button>
      <button type="button" data-view="requests">Orders & quotes</button>
      <button type="button" data-view="messages">Messages</button>
      <button type="button" data-view="artwork">Artwork</button>
      <button type="button" data-view="packages">Services & pricing</button>
      <button type="button" data-view="settings">Website settings</button>
    </nav>
    <div id="work-error" class="notice" role="status" hidden></div>

    <section data-panel="overview" class="content-panel">
      <div class="section-intro"><h2>At a glance</h2><p>Activity from the connected database.</p></div>
      <div class="stats">
        <article><span>REQUESTS TO REVIEW</span><strong id="stat-requests">—</strong><small>Submitted or requiring clarification</small></article>
        <article><span>ACTIVE ORDERS</span><strong id="stat-orders">—</strong><small>Awaiting arrangements or in progress</small></article>
        <article><span>PAYMENTS TO CHECK</span><strong id="stat-payments">—</strong><small>Require manual verification</small></article>
        <article><span>MESSAGE ACTIVITY</span><strong id="stat-messages">—</strong><small>Last seven days, not an unread count</small></article>
      </div>
      <p class="small">Activity reflects actual records. No placeholder orders, customers or payments are created.</p>
      <section class="launch-overview" aria-labelledby="launch-overview-title">
        <div class="launch-overview-heading">
          <div>
            <h3 id="launch-overview-title">Launch readiness</h3>
            <p class="small">A live summary of current configuration and remaining requirements. These indicators do not enable any feature.</p>
          </div>
          <button type="button" class="quiet" id="launch-refresh">Refresh readiness</button>
        </div>
        <p id="launch-status" class="small" role="status" aria-live="polite">Checking launch requirements…</p>
        <div id="launch-checks" class="launch-checks"></div>
      </section>
    </section>

    <section data-panel="requests" class="content-panel" hidden>
      <div class="section-intro"><h2>Orders & requests</h2><p>Review customer briefs, private artist notes, and quote drafts. Customer submissions and quote sending stay closed.</p></div>
      <form id="request-list-filters" class="request-list-filters">
        <label for="request-list-query">Find by reference or customer name
          <input id="request-list-query" type="search" maxlength="80" placeholder="Search commissions">
        </label>
        <label for="request-list-filter">Request status
          <select id="request-list-filter"><option value="all">All requests</option><option value="review">Needs review</option><option value="other">Other statuses</option></select>
        </label>
        <button type="submit" class="quiet">Search</button>
        <button type="button" class="quiet" id="request-list-refresh">Refresh</button>
      </form>
      <div id="request-list" class="record-list"><p class="small">Loading requests…</p></div>
      <div class="request-list-pagination">
        <button type="button" class="quiet" id="request-list-previous" disabled>Previous</button>
        <span id="request-list-status" role="status" aria-live="polite">Loading requests…</span>
        <button type="button" class="quiet" id="request-list-next" disabled>Next</button>
      </div>
      <section id="request-detail" class="request-detail" hidden aria-label="Selected commission request">
        <div class="request-detail-header">
          <div>
            <span class="overline" id="request-detail-ref">SELECTED REQUEST</span>
            <h3 id="request-detail-title">Commission request</h3>
          </div>
          <button type="button" class="quiet" id="request-detail-close">Close details</button>
        </div>
        <p id="request-detail-status" class="small" role="status" aria-live="polite"></p>
        <div class="request-summary">
          <div><strong>Customer</strong><span id="request-customer"></span></div>
          <div><strong>Type / tier</strong><span id="request-tier"></span></div>
          <div><strong>Contact preference</strong><span id="request-contact"></span></div>
          <div><strong>Requested deadline</strong><span id="request-deadline"></span></div>
        </div>
        <div class="request-brief">
          <strong>Customer's artwork brief</strong>
          <p id="request-brief-text"></p>
        </div>
        <section class="request-brief" aria-label="Private customer reference images">
          <strong>Customer reference images (private)</strong>
          <div id="request-references" class="admin-reference-grid">
            <p class="small">No reference images.</p>
          </div>
        </section>
        <section class="request-brief" aria-label="Accepted quote order confirmation">
          <h4>Order progress</h4>
          <p class="small">An accepted quote requires separate artist confirmation. Confirming an order does not verify or collect payment.</p>
          <div id="request-orders"><p class="small">No confirmed order.</p></div>
        </section>
        <div class="request-columns">
          <section class="request-workpanel">
            <h4>Conversation & internal notes</h4>
            <p class="small">Internal notes stay artist-only. Customer replies become available only after the separate messaging service is enabled.</p>
            <div id="request-thread" class="request-thread"></div>
            <form id="request-note-form" class="request-compose">
              <label for="request-note-body">Add an internal note</label>
              <textarea id="request-note-body" rows="4" maxlength="5000" required placeholder="Private reminder, sketch details, or next steps…"></textarea>
              <button type="submit" class="quiet" id="request-note-save" disabled>Save private note</button>
            </form>
            <form id="request-message-form" class="request-compose" hidden>
              <label for="request-message-body">Reply privately to customer</label>
              <textarea id="request-message-body" rows="4" maxlength="3000" minlength="2" required placeholder="Write a message that the customer will see in their account…"></textarea>
              <p class="small">Customer-visible message. This does not send an email, quote, or invoice.</p>
              <button type="submit" class="quiet" id="request-message-send" disabled>Send private reply</button>
            </form>
          </section>
          <section class="request-workpanel">
            <h4>Versioned quote drafts</h4>
            <p class="small">Draft quotes stay internal. Releasing a reviewed quote to the customer's private account is separately gated. No automatic payment.</p>
            <div id="request-quotes" class="request-quote-history"></div>
            <form id="request-quote-form" class="request-quote-form">
              <label for="quote-currency">Currency</label>
              <select id="quote-currency"><option value="CAD">CAD</option><option value="USD">USD</option></select>
              <label for="quote-scope">Artwork scope</label>
              <textarea id="quote-scope" rows="4" maxlength="5000" required placeholder="What will be drawn, format, delivery expectations…"></textarea>
              <label for="quote-revisions">Included revisions (0–25)</label>
              <input id="quote-revisions" type="number" min="0" max="25" step="1" value="1" required>
              <div class="request-quote-lines-title">
                <strong>Line items</strong>
                <button type="button" class="quiet" id="quote-add-line">+ Add line</button>
              </div>
              <div id="quote-lines" class="quote-lines"></div>
              <p id="quote-total" class="request-quote-total">Draft total: —</p>
              <button type="submit" class="quiet" id="quote-save" disabled>Create draft quote</button>
              <p class="small">Each save creates a new version; previous versions remain for reference.</p>
            </form>
          </section>
        </div>
      </section>
    </section>

    <section data-panel="messages" class="content-panel" hidden>
      <div class="section-intro"><h2>Messages</h2><p>Recent conversation activity, including internal notes. Open a request to read details and reply when customer messaging is enabled.</p></div>
      <div id="message-list" class="record-list"><p class="small">Loading messages…</p></div>
    </section>

    <section data-panel="artwork" class="content-panel" hidden>
      <div class="section-intro"><h2>Artwork library</h2><p>Upload your own drawings in bulk, review private previews, and publish without entering image titles or captions.</p></div>
      <div class="artwork-toolbar">
        <div>
          <strong>Upload drawings</strong>
          <p class="small">JPEG, PNG, or WebP. Up to 8 MB per image, maximum 16 megapixels. Originals are re-encoded as optimized WebP images without EXIF metadata. New images remain private drafts.</p>
          <input id="artwork-files" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-label="Choose artwork images for upload">
          <div class="artwork-toolbar-actions">
            <button type="button" class="quiet" id="artwork-upload" disabled>Upload as drafts</button>
            <button type="button" class="quiet" id="artwork-refresh">Refresh library</button>
          </div>
          <progress id="artwork-progress" value="0" max="1" hidden aria-label="Artwork upload progress"></progress>
          <p id="artwork-upload-message" role="status" aria-live="polite" class="small">Checking upload availability…</p>
          <ul id="artwork-upload-results" class="artwork-upload-results"></ul>
        </div>
        <div class="artwork-bulk">
          <strong>Artwork visibility</strong>
          <p class="small">Only published artwork appears in the public gallery. Drafts are never served through the public image API.</p>
          <label class="artwork-select-label"><input type="checkbox" id="artwork-select-all"> Select all displayed</label>
          <div class="artwork-batch-actions">
            <button type="button" class="quiet" id="artwork-publish-selected" disabled>Publish selected drafts</button>
            <button type="button" class="quiet" id="artwork-unpublish-selected" disabled>Unpublish selected drawings</button>
          </div>
          <p id="artwork-library-status" role="status" class="small"></p>
        </div>
      </div>
      <div class="artwork-library-filters">
        <label for="artwork-filter">View artworks
          <select id="artwork-filter">
            <option value="all">All drawings</option>
            <option value="draft">Private drafts</option>
            <option value="published">Published drawings</option>
          </select>
        </label>
        <span id="artwork-visible-count" class="small" role="status" aria-live="polite"></span>
      </div>
      <div id="artwork-list" class="artwork-library-grid" aria-label="Uploaded artwork">Loading artwork library…</div>
    </section>

    <section data-panel="packages" class="content-panel" hidden>
      <div class="section-intro">
        <h2>Services & commission pricing</h2>
        <p>Edit packages and optional extras. These are artist-managed estimates; an exact quote is still sent after reviewing each request.</p>
      </div>
      <div class="package-workspace">
        <aside class="package-sidebar">
          <div class="package-sidebar-heading"><strong>Commission tiers</strong><button type="button" class="quiet" id="package-new-tier">+ New</button></div>
          <div id="package-tier-list" class="package-list"><p class="small">Loading tiers…</p></div>
          <div class="package-sidebar-heading"><strong>Optional add-ons</strong><button type="button" class="quiet" id="package-new-addon">+ New</button></div>
          <div id="package-addon-list" class="package-list"><p class="small">Loading add-ons…</p></div>
        </aside>
        <form id="package-form" class="package-form" novalidate>
          <div class="package-form-head">
            <span class="overline" id="package-kind-label">NEW COMMISSION TIER</span>
            <h3 id="package-edit-title">New commission tier</h3>
            <p class="small" id="package-editor-status">Loading editor availability…</p>
          </div>
          <div class="package-fields">
            <label>Internal slug <input id="package-slug" type="text" maxlength="80" pattern="[a-z0-9][a-z0-9-]+" placeholder="simple-sketch" required></label>
            <label>Package name <input id="package-name" type="text" maxlength="120" placeholder="Simple" required></label>
            <label class="package-wide">Description <textarea id="package-description" rows="3" maxlength="2500" placeholder="Describe the drawing and what is included"></textarea></label>
            <label>Currency <select id="package-currency"><option value="CAD">CAD</option><option value="USD">USD</option></select></label>
            <label>Sort order <input id="package-order" type="number" min="-10000" max="10000" step="1" value="0"></label>
            <label>Estimated minimum ($) <input id="package-min" inputmode="decimal" type="text" maxlength="10" placeholder="Not set"></label>
            <label>Estimated maximum ($) <input id="package-max" inputmode="decimal" type="text" maxlength="10" placeholder="Not set"></label>
            <label class="package-wide" id="package-feature-wrap">Included features, one per line
              <textarea id="package-features" rows="5" maxlength="3700" placeholder="Colouring&#10;One basic revision&#10;Simple background"></textarea>
            </label>
          </div>
          <div class="package-form-actions">
            <button type="button" class="quiet" id="package-refresh">Reload</button>
            <button type="submit" class="quiet" id="package-save" disabled>Save unpublished</button>
            <button type="button" class="primary" id="package-visibility" disabled>Publish</button>
          </div>
          <p id="package-message" class="notice" role="status" aria-live="polite">Edits and publication are disabled until the admin security setup and pricing are approved.</p>
          <p class="small">New items are always created unpublished. Publishing requires a separate server-side switch and artist approval. No prices are seeded, and customers cannot submit commissions yet.</p>
        </form>
      </div>
    </section>

    <section data-panel="settings" class="content-panel" hidden>
      <div class="section-intro"><h2>Website settings</h2><p>Theme, logo, default dark mode, commission pricing and SEO will be editable here.</p></div>
      <form class="design-editor" id="design-form" novalidate>
        <div class="design-header">
          <span class="overline">ARTIST-CONTROLLED APPEARANCE</span>
          <p id="design-state" role="status">Loading private draft and published settings…</p>
        </div>
        <div class="design-fields">
          <label>Website style
            <select id="design-site" name="site_style"><option value="studio">Studio (approved)</option></select>
          </label>
          <label>Colour palette
            <select id="design-color" name="color_theme">
              <option value="noir">Noir</option><option value="canvas">Ocean</option>
              <option value="editorial">Paper</option><option value="aurora">Aurora</option>
              <option value="ember">Ember</option>
            </select>
          </label>
          <label>Gallery layout
            <select id="design-layout" name="gallery_layout">
              <option value="masonry">Masonry</option><option value="mosaic">Mosaic</option>
              <option value="rows">Scroll / rows</option>
            </select>
          </label>
          <label>Default appearance
            <select id="design-mode" name="default_mode">
              <option value="dark">Dark</option><option value="light">Light</option>
            </select>
          </label>
          <label>Website logo
            <select id="design-logo" name="logo">
              <option value="original">Original wordmark</option>
              <option value="01-minimal-pencil">Minimal pencil</option>
              <option value="02-signature">Signature</option>
              <option value="03-dmo-monogram">DMO monogram</option>
              <option value="04-portrait-seal">Portrait seal</option>
              <option value="05-editorial">Editorial</option>
              <option value="06-brushstroke">Brushstroke</option>
              <option value="07-portrait">Portrait</option>
              <option value="08-paintbrush-badge">Brush badge</option>
              <option value="09-pen-lettering">Pen lettering</option>
              <option value="10-creative-palette">Creative palette</option>
            </select>
          </label>
        </div>
        <div class="design-actions">
          <button type="button" class="quiet" id="design-reload">Reload draft</button>
          <button type="submit" class="quiet" id="design-save" disabled>Save draft</button>
          <button type="button" class="primary" id="design-publish" disabled>Publish draft</button>
        </div>
        <p class="small">Drafts never affect the public site. Publishing requires an authenticated administrator and a second, explicitly enabled server-side write switch. The old Design Lab remains a development preview until the public site is converted to admin-controlled themes.</p>
      </form>
      <section class="mail-check" aria-labelledby="mail-check-heading">
        <div class="mail-check-head">
          <div>
            <span class="overline">CUSTOMER ACCOUNT LAUNCH PREPARATION</span>
            <h3 id="mail-check-heading">Transactional email diagnostics</h3>
            <p class="small">Test verification and password-recovery mail before opening customer registration. Nothing is enabled automatically.</p>
          </div>
          <button type="button" class="quiet" id="mail-check-refresh">Check configuration</button>
        </div>
        <div class="mail-check-status" id="mail-check-status" role="status" aria-live="polite">Checking mail transport…</div>
        <p class="small" id="mail-check-detail">Tests are sent exclusively to the address on your administrator account.</p>
        <button type="button" class="primary" id="mail-check-send" disabled>Send a test email to my admin address</button>
        <p id="mail-test-feedback" class="small" role="status" aria-live="polite"></p>
        <p class="small">Before opening commission requests, <a href="https://github.com/pixel375/drawmeone/blob/main/docs/CUSTOMER_POLICIES_DRAFT.md" rel="noopener noreferrer" target="_blank">review the draft Terms and Privacy Policy</a>. The drafts are not approved or binding.</p>
      </section>
    </section>
  </section>
  <footer>Draw Me One · Secure artist dashboard · No MFA, password and CAPTCHA protected when enabled</footer>
</main>
</body>
</html>
