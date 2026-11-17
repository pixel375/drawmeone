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
<link rel="stylesheet" href="./panel.css?v=13">
<script defer src="./panel.js?v=13"></script>
</head>
<body>
<a class="skip" href="#main">Skip to dashboard</a>
<header class="masthead">
  <a class="logo" href="../index.html">drawmeone<span>.</span></a>
  <span class="desk-tag">ARTIST'S DESK</span>
  <a class="return" href="../index.html">← Return to portfolio</a>
</header>
<main id="main">
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
      <button type="button" data-view="settings">Website settings</button>
    </nav>
    <div id="work-error" class="notice" role="status" hidden></div>

    <section data-panel="overview" class="content-panel">
      <div class="section-intro"><h2>At a glance</h2><p>Activity from the connected database.</p></div>
      <div class="stats">
        <article><span>REQUESTS TO REVIEW</span><strong id="stat-requests">—</strong><small>Submitted or requiring clarification</small></article>
        <article><span>ACTIVE ORDERS</span><strong id="stat-orders">—</strong><small>Currently in the drawing workflow</small></article>
        <article><span>PAYMENTS TO CHECK</span><strong id="stat-payments">—</strong><small>Require manual verification</small></article>
        <article><span>MESSAGE ACTIVITY</span><strong id="stat-messages">—</strong><small>Last seven days, not an unread count</small></article>
      </div>
      <p class="small">Commission submission and payment processing are still disabled. The empty workspace is normal until real orders are accepted.</p>
    </section>

    <section data-panel="requests" class="content-panel" hidden>
      <div class="section-intro"><h2>Orders & requests</h2><p>Latest forty customer requests, read-only for this phase.</p></div>
      <div id="request-list" class="record-list"><p class="small">Loading requests…</p></div>
    </section>

    <section data-panel="messages" class="content-panel" hidden>
      <div class="section-intro"><h2>Messages</h2><p>Recent conversation activity, including internal notes. Read-only for this phase.</p></div>
      <div id="message-list" class="record-list"><p class="small">Loading messages…</p></div>
    </section>

    <section data-panel="artwork" class="content-panel" hidden>
      <div class="section-intro"><h2>Artwork library</h2><p>Bulk upload will let you add drawings without filling in a form for every image.</p></div>
      <div class="empty-state">Artwork upload is not connected yet. Private customer reference images will be stored separately.</div>
    </section>

    <section data-panel="settings" class="content-panel" hidden>
      <div class="section-intro"><h2>Website settings</h2><p>Theme, logo, default dark mode, commission pricing and SEO will be editable here.</p></div>
      <div class="empty-state">Settings editing and publishing will be enabled after the secure administrator workflow is tested. Visitors will only control light/dark mode.</div>
    </section>
  </section>
  <footer>Draw Me One · Secure artist dashboard · No MFA, password and CAPTCHA protected when enabled</footer>
</main>
</body>
</html>
