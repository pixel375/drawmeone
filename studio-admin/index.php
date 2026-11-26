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
<link rel="stylesheet" href="./panel.css?v=53">
<script defer src="./panel.js?v=92"></script>
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
      <button type="button" data-view="finance">Payments</button>
      <button type="button" data-view="customers">Customers</button>
      <button type="button" data-view="artwork">Artwork</button>
      <button type="button" data-view="packages">Services & pricing</button>
      <button type="button" data-view="settings">Website settings</button>
    </nav>
    <div id="work-error" class="notice" role="status" hidden></div>

    <section data-panel="overview" class="content-panel">
      <div class="section-intro"><h2>At a glance</h2><p>Activity from the connected database.</p></div>
      <div class="overview-controls"><label><input type="checkbox" id="overview-hide-empty"> Hide empty counters</label><p id="overview-counter-status" class="small" role="status"></p></div>
      <section class="overview-group" aria-labelledby="overview-group-0"><h3 id="overview-group-0">Daily work</h3><div class="stats"><article><span>REQUESTS TO REVIEW</span><strong id="stat-requests">—</strong><small>Submitted or requiring clarification</small><button type="button" class="quiet stat-shortcut" data-request-queue="review">Review requests</button></article>
<article><span>REPLIES NEEDED</span><strong id="stat-reply-due">—</strong><small>Latest public message is from the customer</small><button type="button" class="quiet stat-shortcut" data-request-queue="reply_due">View conversations</button></article>
<article><span>ACCEPTED QUOTES</span><strong id="stat-accepted">—</strong><small>Accepted quote without an order</small><button type="button" class="quiet stat-shortcut" data-request-queue="accepted">Review accepted quotes</button></article>
<article><span>QUOTE FOLLOW-UP</span><strong id="stat-quote-followup">—</strong><small>Expired, declined or inactive offers</small><button type="button" class="quiet stat-shortcut" data-request-queue="quote_followup">Review quotes</button></article>
<article><span>OVERDUE ORDERS</span><strong id="stat-overdue">—</strong><small>Agreed date has passed</small><button type="button" class="quiet stat-shortcut" data-request-queue="overdue">Review overdue</button></article>
<article><span>REVISIONS REQUESTED</span><strong id="stat-revision-due">—</strong><small>Latest artwork review requests a revision</small><button type="button" class="quiet stat-shortcut" data-request-queue="revision_due">Review artwork</button></article>
<article><span>PAID TO START</span><strong id="stat-paid-to-start">—</strong><small>Paid status, production not started</small><button type="button" class="quiet stat-shortcut" data-request-queue="paid_to_start">Review production</button></article>
<article><span>READY TO DELIVER</span><strong id="stat-ready-to-deliver">—</strong><small>Paid orders marked ready; release checks still apply</small><button type="button" class="quiet stat-shortcut" data-request-queue="ready_to_deliver">Review delivery</button></article></div><p class="small overview-empty" hidden>No non-empty counters in this group.</p></section>
      <section class="overview-group" aria-labelledby="overview-group-1"><h3 id="overview-group-1">Production & planning</h3><div class="stats"><article><span>ACTIVE ORDERS</span><strong id="stat-orders">—</strong><small>Awaiting arrangements or in progress</small><button type="button" class="quiet stat-shortcut" data-request-queue="orders">Open order queue</button></article>
<article><span>UPCOMING DEADLINES</span><strong id="stat-upcoming">—</strong><small>Today through the next seven days</small><button type="button" class="quiet stat-shortcut" data-request-queue="upcoming">View upcoming</button></article>
<article><span>NEEDS SCHEDULE</span><strong id="stat-needs-schedule">—</strong><small>Active orders without an agreed date</small><button type="button" class="quiet stat-shortcut" data-request-queue="needs_schedule">Plan dates</button></article>
<article><span>PROOFS AWAITING REVIEW</span><strong id="stat-proof-waiting">—</strong><small>Latest artwork review pending</small><button type="button" class="quiet stat-shortcut" data-request-queue="proof_waiting">View pending proofs</button></article>
<article><span>ORDERS DUE SOON</span><strong id="stat-due">—</strong><small>Agreed date within seven days or overdue</small><button type="button" class="quiet stat-shortcut" data-request-queue="due">View deadlines</button></article></div><p class="small overview-empty" hidden>No non-empty counters in this group.</p></section>
      <section class="overview-group" aria-labelledby="overview-group-2"><h3 id="overview-group-2">Administration</h3><div class="stats"><article><span>PAYMENTS TO CHECK</span><strong id="stat-payments">—</strong><small>Require manual verification</small><button type="button" class="quiet stat-shortcut" data-request-queue="payment_review">Review payments</button></article>
<article><span>PAYMENT HOLDS</span><strong id="stat-holds">—</strong><small>Payment exceptions requiring attention</small><button type="button" class="quiet stat-shortcut" data-request-queue="payment_hold">Review holds</button></article>
<article><span>INVOICE FOLLOW-UP</span><strong id="stat-checkout">—</strong><small>Expired or suspended local links needing external closure review</small><button type="button" class="quiet stat-shortcut" data-request-queue="checkout_followup">Review invoices</button></article>
<article><span>OPEN PRIVACY REQUESTS</span><strong id="stat-privacy">—</strong><small>Require individual review</small></article>
<article><span>MESSAGE ACTIVITY</span><strong id="stat-messages">—</strong><small>Last seven days, not an unread count</small></article></div><p class="small overview-empty" hidden>No non-empty counters in this group.</p></section>
      <p class="small">Activity reflects actual records. No placeholder orders, customers or payments are created.</p>
      <section class="launch-overview" aria-labelledby="intake-title">
        <h3 id="intake-title">Commission availability</h3>
        <p class="small" id="intake-status" role="status">Checking intake…</p>
        <form id="intake-form" class="intake-form" hidden>
          <label for="intake-mode">New requests</label>
          <select id="intake-mode"><option value="paused">Paused</option><option value="open">Accept when capacity and launch gates allow</option></select>
          <label for="intake-capacity">Maximum simultaneous active requests</label>
          <input id="intake-capacity" type="number" min="1" max="100" step="1" required>
          <p class="small">The limit counts requests that are not closed. Existing orders and customer access remain available when new requests are paused.</p>
          <button class="quiet" type="submit" id="intake-save">Save intake settings</button>
        </form>
        <p class="small">This setting cannot enable customer registration, policy approval or commercial launch.</p>
      </section>
      <section class="launch-overview" aria-labelledby="privacy-queue-title">
        <h3 id="privacy-queue-title">Privacy requests</h3>
        <p class="small">Review requests for additional access, corrections or deletion. These controls record decisions only; they do not change or delete customer data. Respond through a verified channel if the customer cannot sign in.</p>
        <label for="privacy-queue-view">Show <select id="privacy-queue-view"><option value="open">Open requests</option><option value="closed">Resolved, declined and withdrawn</option></select></label>
        <button type="button" class="quiet" id="privacy-queue-refresh">Load privacy requests</button>
        <p id="privacy-queue-status" class="small" role="status"></p>
        <div id="privacy-queue" class="record-list"></div>
        <button type="button" class="quiet" id="privacy-queue-next" hidden>Load older requests</button>
      </section>
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
        <details class="integrity-details"><summary>Controlled launch stages and evidence</summary>
          <p id="launch-evidence-status" class="small" role="status">Not checked yet.</p>
          <p class="small">Configuration indicators cannot verify owner approval, real customer tests, an external payment provider or independent recovery. Complete the separate evidence requirements before opening.</p>
          <div id="launch-evidence-stages" class="launch-checks"></div>
          <button id="launch-evidence-export" type="button" class="quiet" disabled>Export configuration report</button>
        </details>
        <details class="integrity-details"><summary>Private configuration review (read-only)</summary>
          <p id="configuration-status" class="small" role="status">Not checked yet.</p>
          <p class="small">Review these warnings before enabling affected features. Intentionally staged settings may produce warnings. No secrets or configuration values are displayed.</p>
          <ul id="configuration-issues"></ul>
        </details>
        <details class="integrity-details"><summary>Retention review (read-only)</summary>
          <p id="retention-status" class="small" role="status">Not checked yet.</p>
          <p class="small">These are operational review thresholds, not approved deletion periods or legal deadlines. No records are removed by this report. Older privacy requests are measured from the original submission.</p>
          <div id="retention-checks" class="launch-checks"></div>
        </details>
        <details class="integrity-details"><summary>Order and payment consistency details</summary><p class="small" id="integrity-status" role="status">Not checked yet.</p><div id="integrity-checks" class="launch-checks"></div><p class="small">Database checks only. Daily private-file integrity diagnostics are separate; a clean result is not a complete recovery guarantee.</p></details>
      </section>
    </section>

    <section data-panel="requests" class="content-panel" hidden>
      <div class="section-intro"><h2>Orders & requests</h2><p>Review customer briefs, private artist notes, and quote drafts. Customer submissions and quote sending stay closed.</p></div>
      <div id="request-customer-scope" hidden><p id="request-customer-label" class="small"></p><button id="request-customer-clear" type="button" class="quiet">Show all customers</button></div>
      <form id="request-list-filters" class="request-list-filters">
        <label for="request-list-query">Find commissions
          <input id="request-list-query" type="search" maxlength="80" placeholder="Search commissions">
        </label>
        <label for="request-list-scope">Search in<select id="request-list-scope"><option value="identity">Reference or customer name</option><option value="brief">Private commission brief</option><option value="package">Drawing type or package</option></select></label>
        <label for="request-list-sort">Order by<select id="request-list-sort"><option value="queue">Queue priority</option><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="deadline">Agreed delivery date</option></select></label>
        <label for="request-list-filter">Request status
          <select id="request-list-filter"><option value="all">All requests</option><option value="review">Needs review</option><option value="quote_waiting">Awaiting quote decision</option><option value="quote_followup">Quotes needing follow-up</option><option value="accepted">Accepted quote, no order</option><option value="orders">Active orders</option><option value="payment_review">Payments to reconcile</option><option value="payment_hold">Payment holds</option><option value="checkout_followup">Invoice follow-up</option><option value="due">Due soon or overdue</option><option value="reply_due">Customer reply needed</option><option value="overdue">Overdue</option><option value="upcoming">Due in next seven days</option><option value="revision_due">Revision requested</option><option value="proof_waiting">Proof awaiting customer</option><option value="scheduled">Agreed delivery agenda</option><option value="requested_dates">Requested dates (not agreed)</option><option value="needs_schedule">Active orders without agreed date</option><option value="paid_to_start">Paid orders awaiting production</option><option value="ready_to_deliver">Ready for delivery (paid)</option><option value="other">Other statuses</option></select>
        </label>
        <div id="request-schedule-range" class="request-schedule-range" hidden><label for="request-schedule-from">From date<input type="date" id="request-schedule-from" min="2000-01-01" max="2099-12-31"></label><label for="request-schedule-to">Through date<input type="date" id="request-schedule-to" min="2000-01-01" max="2099-12-31"></label><p class="small">Calendar dates, inclusive. Requested dates are not commitments.</p></div>
        <button type="button" id="request-agenda-export" class="quiet" hidden disabled>Export agreed dates (.ics)</button>
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
          <div class="request-detail-actions"><button type="button" class="quiet" id="request-detail-refresh">Refresh details</button> <button type="button" class="quiet" id="request-detail-close">Close details</button></div>
        </div>
        <p id="request-detail-status" class="small" role="status" aria-live="polite"></p>
        <p id="request-drafts-warning" class="small" role="status" hidden></p>
        <p id="request-policy-status" class="small"></p>
        <section id="request-at-a-glance" class="request-at-a-glance" aria-labelledby="request-at-a-glance-title" hidden>
          <h4 id="request-at-a-glance-title">Commission at a glance</h4>
          <dl id="request-milestones" class="request-milestones"></dl>
          <p id="request-next-step" class="small"></p>
          <nav id="request-section-links" class="request-section-links" aria-label="Commission detail sections"></nav>
        </section>
        <div class="request-summary">
          <div><strong>Customer</strong><span id="request-customer"></span></div>
          <div><strong>Type / tier</strong><span id="request-tier"></span></div>
          <div><strong>Contact preference</strong><span id="request-contact"></span></div>
          <div><strong>Requested deadline</strong><span id="request-deadline"></span></div>
        </div>
        <section class="request-brief" id="request-review-actions" tabindex="-1" hidden aria-label="Request review status">
          <h4>Review decision</h4>
          <p class="small">Update the customer's review status. This does not send a message, create an order or collect payment.</p>
          <label for="request-review-status">New status</label>
          <select id="request-review-status"></select>
          <button type="button" id="request-review-save" class="quiet" disabled>Update status</button>
        </section>
        <div class="request-brief" id="request-brief-section" tabindex="-1">
          <strong>Customer's artwork brief</strong>
          <p id="request-brief-text"></p>
        </div>
        <section class="request-brief" id="request-reference-section" tabindex="-1" aria-label="Private customer reference images">
          <strong>Customer reference images (private)</strong>
          <div id="request-references" class="admin-reference-grid">
            <p class="small">No reference images.</p>
          </div>
        </section>
        <section class="request-brief" id="request-order-section" tabindex="-1" aria-label="Accepted quote order confirmation">
          <h4>Order progress</h4>
          <p class="small">An accepted quote requires separate artist confirmation. Confirming an order does not verify or collect payment.</p>
          <div id="request-orders"><p class="small">No confirmed order.</p></div>
        </section>
        <section class="request-brief" id="request-payment-section" tabindex="-1" aria-label="Manual payment reconciliation">
          <h4>Payment reconciliation</h4>
          <p class="small">Record an external transaction, then verify it against the receiving account. No money is moved through this website.</p>
          <div id="request-payment-records"></div>
        </section>
        <section class="request-brief" id="request-artwork-section" tabindex="-1" aria-label="Private staged artwork">
          <h4>Private artwork staging</h4>
          <p class="small">Stage artwork privately, track production after payment reconciliation, then explicitly release the final drawing when eligible.</p>
          <div id="request-delivery-drafts"></div>
        </section>
        <section class="request-brief" id="request-notice-section" tabindex="-1" aria-label="Optional customer email notices">
          <h4>Customer email notices</h4>
          <p class="small">Optional account-update notices for verified customers who prefer email. No brief, quote price, attachment or payment link is included.</p>
          <div id="request-notifications"><p class="small">No available notices.</p></div>
        </section>
        <details class="request-brief request-activity" id="request-activity-panel" tabindex="-1">
          <summary>Commission activity history</summary>
          <p class="small">Most recent recorded workflow events. Artist-only; no message text or payment details.</p>
          <ol id="request-activity-list" class="request-activity-list"></ol>
          <p id="request-activity-limit" class="small" hidden>Older recorded events are available.</p><button id="request-activity-older" type="button" class="quiet" hidden>Load older activity</button><p id="request-activity-feedback" class="small" role="status"></p>
        </details>
        <div class="request-columns">
          <section class="request-workpanel" id="request-conversation-section" tabindex="-1" aria-label="Conversation and internal notes">
            <h4>Conversation & internal notes</h4>
            <p class="small">Internal notes stay artist-only. Customer replies become available only after the separate messaging service is enabled.</p>
            <p id="request-thread-count" class="small" role="status"></p>
            <p id="request-conversation-full" class="small" role="status" hidden>Conversation capacity reached. No new notes or replies can be saved. Unchanged interrupted operations can still be retried; the existing thread remains readable.</p>
            <button type="button" id="request-thread-full" class="quiet" hidden>Show earlier notes and messages</button>
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
          <section class="request-workpanel" id="request-quote-section" tabindex="-1" aria-label="Versioned quote drafts">
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
      <div class="request-list-filters">
        <label for="message-list-filter">Message type
          <select id="message-list-filter"><option value="all">All activity</option><option value="customer">Customer messages</option><option value="reply_due">Conversations awaiting artist reply</option><option value="artist">Artist replies</option><option value="internal">Private notes</option></select>
        </label>
        <button type="button" class="quiet" id="message-list-refresh">Refresh</button>
      </div>
      <div id="message-list" class="record-list"><p class="small">Loading messages…</p></div>
      <div class="request-list-pagination">
        <button type="button" class="quiet" id="message-list-previous" disabled>Previous</button>
        <span id="message-list-status" role="status" aria-live="polite">Loading messages…</span>
        <button type="button" class="quiet" id="message-list-next" disabled>Next</button>
      </div>
    </section>

    <section data-panel="customers" class="content-panel" hidden>
      <div class="section-intro"><h2>Customers</h2><p>Private customer lookup for commission support. Account status and request history are read-only; no account impersonation or deletion.</p></div>
      <form id="customer-filters" class="request-list-filters">
        <label for="customer-query">Name, email or commission reference<input id="customer-query" type="search" maxlength="160" autocomplete="off"></label>
        <label for="customer-state">Account status<select id="customer-state"><option value="all">All customers</option><option value="verified">Active and verified</option><option value="unverified">Awaiting email verification</option><option value="inactive">Inactive accounts</option></select></label>
        <button class="quiet" type="submit">Search customers</button>
      </form>
      <p id="customer-directory-status" class="small" role="status">Select Customers to load records.</p>
      <div id="customer-directory-list" class="record-list"></div>
      <button id="customer-directory-next" class="quiet" type="button" hidden>Load older customers</button>
    </section>

    <section data-panel="finance" class="content-panel" hidden>
      <div class="section-intro"><h2>Payment records</h2><p>Local reconciliation evidence only. No provider balances, accounting profit, fees, taxes or automatic payment verification. Amounts are never combined across currencies.</p></div>
      <p class="small">All-time recorded evidence by current state. Refunded, held, pending, rejected and inconsistent amounts are separate from reconciled records. Totals are not a bank statement.</p>
      <div id="finance-summary" class="launch-checks"></div>
      <div class="request-list-filters">
        <label for="finance-state">Record state<select id="finance-state"><option value="all">All records</option><option value="pending">Awaiting verification</option><option value="reconciled">Reconciled</option><option value="held">On hold</option><option value="refunded">Full refund recorded</option><option value="rejected">Rejected evidence</option><option value="review">Needs review</option></select></label>
        <label for="finance-currency">Currency<select id="finance-currency"><option value="all">All currencies</option><option value="CAD">CAD</option><option value="USD">USD</option></select></label>
        <button type="button" class="quiet" id="finance-refresh">Refresh records</button>
      </div>
      <p id="finance-status" class="small" role="status">Select Payments to load records.</p>
      <div id="finance-records" class="record-list"></div>
      <div class="request-list-pagination"><button type="button" class="quiet" id="finance-next" hidden>Load older records</button><button type="button" class="quiet" id="finance-export" disabled>Export loaded records (JSON)</button></div>
    </section>

    <section data-panel="artwork" class="content-panel" hidden>
      <div class="section-intro"><h2>Artwork library</h2><p>Upload your own drawings in bulk, review private previews, and publish without entering image titles or captions.</p></div>
      <div class="artwork-toolbar">
        <div>
          <strong>Upload drawings</strong>
          <p class="small">JPEG, PNG, or WebP. Up to 8 MB per image, maximum 16 megapixels. Originals are re-encoded as optimized WebP images without EXIF metadata. New images remain private drafts.</p>
          <input id="artwork-files" type="file" accept="image/jpeg,image/png,image/webp" multiple aria-label="Choose artwork images for upload">
          <label class="artwork-select-label"><input id="artwork-skip-identical" type="checkbox" checked> Skip exact files already in the library</label><p class="small">Matches previously recorded original-file bytes only; edited or older unrecorded images may not match.</p>
          <div class="artwork-toolbar-actions">
            <button type="button" class="quiet" id="artwork-upload" disabled>Upload as drafts</button>
            <button type="button" class="quiet" id="artwork-upload-stop" hidden>Stop after current file</button>
            <button type="button" class="quiet" id="artwork-upload-resume" hidden>Upload remaining files</button>
            <button type="button" class="quiet" id="artwork-upload-discard" hidden>Discard remaining selection</button>
            <button type="button" class="quiet" id="artwork-refresh">Refresh library</button>
          </div>
          <progress id="artwork-progress" value="0" max="1" hidden aria-label="Artwork upload progress"></progress>
          <p id="artwork-upload-message" role="status" aria-live="polite" class="small">Checking upload availability…</p>
          <ul id="artwork-upload-results" class="artwork-upload-results"></ul>
        </div>
        <div class="artwork-bulk">
          <strong>Artwork visibility &amp; featured status</strong>
          <p class="small">Only published artwork appears in the public gallery. Drafts are never served through the public image API. Selected drawings are published or unpublished together; a failed validation prevents the entire batch.</p>
          <label class="artwork-select-label"><input type="checkbox" id="artwork-select-all"> Select all displayed on this page</label>
          <p id="artwork-selection-count" class="small" role="status">0 selected on this page</p>
          <div class="artwork-batch-actions">
            <button type="button" class="quiet" id="artwork-publish-selected" disabled>Publish selected drafts</button>
            <button type="button" class="quiet" id="artwork-unpublish-selected" disabled>Unpublish selected drawings</button>
            <button type="button" class="quiet" id="artwork-feature-selected" disabled>Feature selected drawings</button>
            <button type="button" class="quiet" id="artwork-unfeature-selected" disabled>Remove selected featured status</button>
          </div>
          <p id="artwork-library-status" role="status" class="small"></p>
        </div>
      </div>
      <div class="artwork-library-filters">
        <label for="artwork-filter">View artworks
          <select id="artwork-filter">
            <option value="all">All drawings</option>
            <option value="draft">Private drafts</option>
            <option value="published">Published drawings</option><option value="featured">Featured drawings</option><option value="missing_alt">Without accessibility description</option>
          </select>
        </label>
        <label for="artwork-orientation">Image shape<select id="artwork-orientation"><option value="all">All shapes</option><option value="portrait">Portrait</option><option value="landscape">Landscape</option><option value="square">Square</option><option value="unknown">Unknown dimensions</option></select></label>
        <label class="artwork-search-field" for="artwork-search">Find drawing
          <input id="artwork-search" type="search" maxlength="240" placeholder="Drawing ID or description" autocomplete="off">
        </label>
        <label for="artwork-sort-mode">Order by<select id="artwork-sort-mode"><option value="recent">Recently uploaded</option><option value="gallery">Public gallery order (move drawings)</option><option value="oldest">Oldest first</option><option value="largest">Largest optimized files</option></select></label>
        <span id="artwork-visible-count" class="small" role="status" aria-live="polite"></span>
      </div>
      <div id="artwork-unsaved" hidden><p id="artwork-unsaved-count" class="small" role="status"></p><button type="button" id="artwork-save-edits" class="quiet" disabled>Save unsaved artwork edits</button> <button type="button" id="artwork-discard-edits" class="quiet">Discard unsaved artwork edits</button></div>
      <div id="artwork-list" class="artwork-library-grid" aria-label="Uploaded artwork">Loading artwork library…</div>
      <div class="request-list-pagination"><button id="artwork-prev" type="button" class="quiet" disabled>Previous</button><span id="artwork-page" class="small" role="status"></span><button id="artwork-next" type="button" class="quiet" disabled>Next</button></div>
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
            <button type="button" class="quiet" id="package-refresh">Reload</button><button type="button" class="quiet" id="package-duplicate" disabled>Copy into new draft</button>
            <p id="package-unsaved" class="small" role="status" hidden></p><button type="button" class="quiet" id="package-revert" disabled>Discard unsaved changes</button>
          <button type="submit" class="quiet" id="package-save" disabled>Save unpublished</button>
            <button type="button" class="primary" id="package-visibility" disabled>Publish</button>
          </div>
          <fieldset id="package-compatibility" class="package-compatibility" hidden>
            <legend>Available optional extras</legend>
            <p class="small">Choose all extras or only selected extras. Unpublished extras never appear to customers. New extras are excluded from a restricted package until selected.</p>
            <label for="package-compatibility-mode">Availability</label>
            <select id="package-compatibility-mode"><option value="all">All current and future extras</option><option value="selected">Only selected extras</option></select>
            <div id="package-compatibility-options" class="package-compatibility-options"></div>
            <button type="button" class="quiet" id="package-compatibility-save" disabled>Save extra availability</button>
            <p id="package-compatibility-status" class="small" role="status"></p>
          </fieldset>
          <details class="package-preview" id="package-preview"><summary>Preview customer-facing package</summary><p class="small">Private preview of the editor values. It does not publish or change anything.</p><article class="package-preview-card"><h3 id="package-preview-name"></h3><p id="package-preview-price" class="package-preview-price"></p><p id="package-preview-description"></p><ul id="package-preview-features"></ul><p id="package-preview-extras" class="small"></p><p id="package-preview-check" class="small" role="status"></p></article></details>
          <p id="package-message" class="notice" role="status" aria-live="polite">Edits and publication are disabled until the admin security setup and pricing are approved.</p>
          <p class="small">New items are always created unpublished. Publishing requires a separate server-side switch and artist approval. No prices are seeded. New request availability is controlled separately.</p>
        </form>
      </div>
    </section>

    <section data-panel="settings" class="content-panel" hidden>
      <div class="section-intro"><h2>Website settings</h2><p>Theme, logo, default dark mode, commission pricing and SEO will be editable here.</p></div>
      <details class="integrity-details" id="admin-security">
        <summary>Administrator account security</summary>
        <p id="admin-security-status" class="small" role="status">Open to check active sessions and recent sign-in activity.</p>
        <button type="button" class="quiet" id="admin-security-refresh">Refresh security information</button>
        <ul id="admin-security-activity"></ul>
        <form id="admin-security-form" class="design-editor">
          <label for="admin-security-action">Action</label><select id="admin-security-action"><option value="revoke_other_sessions">Sign out other devices</option><option value="change_password">Change administrator password</option></select>
          <label for="admin-security-current">Current password</label><input id="admin-security-current" type="password" autocomplete="current-password" maxlength="128" required>
          <div id="admin-security-new-fields" hidden>
            <label for="admin-security-new">New password (16–72 UTF-8 bytes)</label><input id="admin-security-new" type="password" autocomplete="new-password" minlength="16" maxlength="72">
            <label for="admin-security-confirm">Confirm new password</label><input id="admin-security-confirm" type="password" autocomplete="new-password" maxlength="72">
          </div>
          <button type="submit" id="admin-security-submit" class="quiet" disabled>Confirm security change</button>
          <p class="small" id="admin-security-feedback" role="status"></p>
        </form>
        <p class="small">Password changes sign out every administrator session for your account, including this device. Other-device sign-out preserves this session.</p>
      </details>
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
        <p id="design-unsaved" class="small" role="status" hidden>Unsaved appearance changes. Save the draft before publishing.</p>
        <div class="design-actions">
          <button type="button" class="quiet" id="design-reload">Reload draft</button>
          <button type="submit" class="quiet" id="design-save" disabled>Save draft</button>
          <button type="button" class="primary" id="design-publish" disabled>Publish draft</button>
        </div>
        <p class="small">Drafts never affect the public site. Publishing requires an authenticated administrator and a server-side write switch. Public presentation is controlled separately.</p>
        <label>Previous published design
          <select id="design-history" disabled><option value="">No previous versions</option></select>
        </label>
        <button type="button" class="quiet" id="design-restore" disabled>Restore selected version to draft</button>
        <p class="small">Restoring replaces the saved draft only. Review and publish separately to change the public design.</p>
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
