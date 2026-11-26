"use strict";
// Three completely different page architectures; the Studio page is retained.
// Gallery DOM is MOVED (not cloned), preserving its events, images, and load-more state.
// The design lab swaps these locally; no routes or customer information are submitted.
(() => {
  const root=document.documentElement;
  const shell=document.querySelector(".site-shell");
  const studioHeader=shell?.querySelector(":scope > .site-header");
  const studioMain=shell?.querySelector(":scope > main");
  const studioFooter=shell?.querySelector(":scope > .footer");
  if (!shell||!studioHeader||!studioMain||!studioFooter) return;
  const studioGallery=studioMain.querySelector("#portfolio");
  const studioProcess=studioMain.querySelector("#process");
  const studioContact=studioMain.querySelector("#contact");
  const endMark=studioGallery?.querySelector(".gallery-endmark");
  if(!studioGallery||!studioProcess||!studioContact||!endMark) return;
  const feed=document.createElement("div");
  feed.className="shared-gallery-feed";
  for(const selector of [
    "#gallery-demo-notice","#portfolio-gallery","#portfolio-empty","#portfolio-error","#gallery-more","#gallery-progress","#portfolio-directory-note",".portfolio-directory-link"
  ]) {
    const node=studioGallery.querySelector(selector);
    if(!node) return;
    feed.appendChild(node);
  }
  studioGallery.insertBefore(feed,endMark);
  let active=null;
  const image=(id,extra="")=>'<img data-showcase-image="'+(Number(id)-1)+'" data-preview-src="./assets/demo/art-'+id+'.svg" alt="Temporary design illustration" '+extra+'>';
  const navigation=(className)=>`
    <nav class="${className}" aria-label="Main navigation">
      <a href="#portfolio">Work</a><a href="#process">The approach</a><a href="#contact">Enquiries</a>
    </nav>`;
  const sample='<span class="concept-sample-note" data-demo-caption>DESIGN SAMPLE · NOT ARTIST WORK</span>';
  const status='<span class="concept-status"><span class="concept-status-dot"></span> <span data-intake-label>Requests not open</span></span>';

  function atelier() {
    return `<main class="concept-page concept-atelier">
      <div class="at-frame">
        <aside class="at-rail" aria-label="Studio navigation">
          <a href="#top" class="at-mark" aria-label="Draw Me One homepage"><span class="at-monogram">D<span>·</span></span><span class="at-brand">DRAW<br>ME ONE</span></a>
          <div class="at-rail-rule"></div>
          ${navigation("at-nav")}
          <div class="at-rail-foot"><span>PERSONAL ART</span><span>WITH PURPOSE</span>${status}</div>
        </aside>
        <div class="at-content">
          <section class="at-cover" id="top" aria-labelledby="at-title">
            <div class="at-overline"><span>DRAW ME ONE / AN INDEPENDENT ART STUDIO</span><span>DESIGN CONCEPT A — ATELIER</span></div>
            <div class="at-cover-grid">
              <div class="at-statement">
                <div class="at-issue"><span class="at-cross">✳</span> ART WORTH KEEPING.</div>
                <h1 id="at-title">Made<br>for <em>you.</em><br><span>Not everyone.</span></h1>
                <p>Some things deserve more than a generic print. Discover a home for drawings shaped around your story, your people, and the details that matter.</p>
                <div class="at-actions"><a class="at-primary" href="#portfolio">Explore the work <span aria-hidden="true">↗</span></a><a class="at-secondary" href="#process">Our approach <span aria-hidden="true">→</span></a></div>
              </div>
              <div class="at-artboard">
                <div class="at-img-panel at-img-main">${image("11",'fetchpriority="high"')}</div>
                <div class="at-img-panel at-img-detail">${image("06",'loading="lazy"')}</div>
                <div class="at-design-seal" aria-hidden="true">D.<br><span>01</span></div>
                ${sample}
              </div>
            </div>
            <div class="at-cover-foot"><span>MADE AROUND YOUR BRIEF</span><span>PERSONAL PROJECTS</span><span>REVIEW THE QUOTE FIRST</span></div>
          </section>
          <section class="at-gallery" id="portfolio" tabindex="-1" aria-labelledby="at-portfolio-title">
            <div class="at-section-label"><span>01 / THE PORTFOLIO</span><span>A COLLECTION IN PROGRESS</span></div>
            <div class="at-section-heading"><h2 id="at-portfolio-title">The work <em>speaks.</em></h2><p>Good illustration needs no sales pitch over the work. Explore the full-frame collection as it takes shape.</p></div>
            <div class="concept-gallery-slot"></div>
          </section>
          <section class="at-method" id="process" aria-labelledby="at-method-title">
            <div class="at-section-label"><span>02 / THE METHOD</span><span>DESIGNED FOR PEACE OF MIND</span></div>
            <div class="at-method-grid">
              <div class="at-method-lead"><div class="at-orbit" aria-hidden="true">✳</div><h2 id="at-method-title">Personal art.<br><em>Clear process.</em></h2><p>Commissioning should feel exciting, never uncertain. Our process is designed to be straightforward with expectations agreed before payment.</p></div>
              <div class="at-method-list">
                <article><span>01</span><div><h3>Start with a conversation.</h3><p>Share the subject, inspiration, references, and budget.</p></div></article>
                <article><span>02</span><div><h3>Know the details first.</h3><p>Confirm the quote, timeline, and planned deliverables before accepting a commission.</p></div></article>
                <article><span>03</span><div><h3>Made with intention.</h3><p>Artwork is created to the agreed brief; the delivery process will be defined before launch.</p></div></article>
              </div>
            </div>
          </section>
          <section class="at-contact" id="contact" aria-labelledby="at-contact-title"><div class="at-contact-top">03 / THE NEXT CHAPTER <span>DRAW ME ONE</span></div><h2 id="at-contact-title">Your idea deserves<br><em>its own canvas.</em></h2><p data-intake-description>New commission requests are not open. General studio questions are welcome by email.</p><span class="at-contact-state" data-intake-label>Requests not open</span><div class="concept-email-actions"><a class="concept-email-link" href="mailto:support@drawmeone.com?subject=Draw%20Me%20One%20inquiry">Email the studio <span aria-hidden="true">↗</span></a><a class="concept-info-link" href="./commission.html" data-intake-cta>Commission information</a></div></section>
          <footer class="at-footer"><a href="#top">drawmeone.</a><span>MADE TO MEAN SOMETHING.</span><span>CONCEPT A / ATELIER</span></footer>
        </div>
      </div>
    </main>`;
  }

  function edition() {
    return `<main class="concept-page concept-edition">
      <header class="ed-header" id="top"><div class="ed-topmeta"><span>THE INDEPENDENT DRAWING STUDIO</span><span>ISSUE N° 01 — AN INTRODUCTION</span></div>
        <div class="ed-mast"><a href="#top">Draw Me One<span>®</span></a><div class="ed-heading-side">THE ART OF<br>BEING YOU.</div></div>
        <div class="ed-menu"><span>PORTFOLIO / COMMISSIONS / STUDIO</span>${navigation("ed-nav")}</div>
      </header>
      <section class="ed-lead" aria-labelledby="ed-title">
        <div class="ed-lead-intro"><span class="ed-chapter">01 — AN INTRODUCTION</span><span class="ed-asterisk" aria-hidden="true">✳</span><h1 id="ed-title">Not just<br>a drawing.<br><em>A feeling.</em></h1><p>Made for the stories that couldn't belong to anyone else. A new place for thoughtful, one-of-a-kind drawing commissions.</p></div>
        <div class="ed-art-exhibition">
          <figure class="ed-figure ed-figure-wide">${image("14",'fetchpriority="high"')}<figcaption data-demo-caption>FIG. 01 — TEMPORARY VISUAL STUDY</figcaption></figure>
          <figure class="ed-figure ed-figure-small">${image("21",'loading="lazy"')}<figcaption data-demo-caption>FIG. 02 — DESIGN SAMPLE ONLY</figcaption></figure>
        </div>
        <div class="ed-deck"><p><span>IN THIS EDITION</span> A considered introduction to custom commissions — from first inspiration to an agreed brief.</p><a href="#portfolio">VIEW THE COLLECTION <span aria-hidden="true">↗</span></a></div>
      </section>
      <section class="ed-gallery" id="portfolio" tabindex="-1" aria-labelledby="ed-portfolio-title">
        <div class="ed-section-head"><span>CHAPTER 02 / THE SELECTED WORK</span><span>AN EXHIBITION IN PROGRESS</span></div>
        <div class="ed-gallery-title"><h2 id="ed-portfolio-title"><em>The</em> collection<span>•</span></h2><p>A visual index with uninterrupted artwork, created for discovery and designed to grow.</p></div>
        <div class="concept-gallery-slot"></div>
      </section>
      <section class="ed-process" id="process" aria-labelledby="ed-process-title">
        <div class="ed-section-head"><span>CHAPTER 03 / HOW COMMISSIONS WILL WORK</span><span>AN EASY WAY TO BEGIN</span></div>
        <div class="ed-process-grid"><div><span class="ed-dropcap">“</span><h2 id="ed-process-title">The details make<br><em>all the difference.</em></h2></div>
          <div class="ed-process-list">
            <article><span>ONE</span><h3>Share your vision.</h3><p>Start with an idea, a story, or reference photos.</p></article>
            <article><span>TWO</span><h3>Agree on the scope.</h3><p>Receive a custom quote and confirm expectations before paying.</p></article>
            <article><span>THREE</span><h3>Make it personal.</h3><p>See your idea become a drawing after the agreed arrangements are confirmed.</p></article>
          </div>
        </div>
      </section>
      <section class="ed-lastpage" id="contact" aria-labelledby="ed-last-title"><div class="ed-last-meta">A FINAL NOTE / NO. 01</div><h2 id="ed-last-title">Something worth<br><em>waiting for.</em></h2><p data-intake-description>New commission requests are not open. General studio questions are welcome by email.</p><span data-intake-label>Requests not open</span><div class="concept-email-actions"><a class="concept-email-link" href="mailto:support@drawmeone.com?subject=Draw%20Me%20One%20inquiry">Email the studio <span aria-hidden="true">↗</span></a><a class="concept-info-link" href="./commission.html" data-intake-cta>Commission information</a></div></section>
      <footer class="ed-footer"><span>DRAW ME ONE — INDEPENDENT ART</span><a href="#top">BACK TO THE FIRST PAGE ↑</a><span>WEBSITE EDITION 01</span></footer>
    </main>`;
  }

  function signature() {
    return `<main class="concept-page concept-signature">
      <header class="sg-nav" id="top"><a href="#top" class="sg-wordmark">drawmeone<span>✳</span></a>
        ${navigation("sg-menu")}<span class="sg-studio-badge">CREATIVE STUDIO / PREVIEW</span>
      </header>
      <section class="sg-hero" aria-labelledby="sg-title">
        <div class="sg-hero-art" aria-hidden="true"><div class="sg-image-back">${image("19",'fetchpriority="high"')}</div><div class="sg-image-front">${image("15",'loading="eager"')}</div><span class="sg-stage-orbit">✳</span></div>
        <div class="sg-hero-overlay"></div>
        <div class="sg-hero-content"><div class="sg-kicker"><span class="sg-kicker-line"></span> ART AS INDIVIDUAL AS YOU</div><h1 id="sg-title">Not made<br>for <em>everyone.</em><br>Made for <i>you.</i></h1>
        <p>Ideas become keepsakes. A carefully considered home for custom drawings made personal — and designed to be remembered.</p>
        <div class="sg-hero-actions"><a href="#portfolio">Experience the gallery <span aria-hidden="true">↗</span></a><a href="#process">The commission process <span aria-hidden="true">↓</span></a></div>
        <span class="sg-proofline">A CLEAR BRIEF <span>·</span> A PERSONAL QUOTE <span>·</span> <span data-intake-label>Requests not open</span></span></div>
        <div class="sg-hero-bottom"><span>01 / DISCOVER</span><span>SCROLL TO EXPLORE ↓</span>${sample}</div>
      </section>
      <section class="sg-manifesto"><span>THE DIFFERENCE IS IN THE DETAILS.</span><h2>It starts with your story.<br><em>Then the art.</em></h2><p>Nothing mass-produced. No one-size-fits-all catalog. The commission process we're building starts with understanding what matters to you.</p><span class="sg-giant-mark" aria-hidden="true">✳</span></section>
      <section class="sg-gallery" id="portfolio" tabindex="-1" aria-labelledby="sg-portfolio-title"><div class="sg-gallery-head"><span>02 / THE WORK</span><span>FROM THE STUDIO</span></div><div class="sg-gallery-title"><h2 id="sg-portfolio-title">A world in<br><em>every line.</em></h2><p>Explore a visual collection designed to let the images take centre stage.</p></div><div class="concept-gallery-slot"></div></section>
      <section class="sg-process" id="process" aria-labelledby="sg-process-title"><div class="sg-process-head"><span>03 / BEHIND EVERY COMMISSION</span><h2 id="sg-process-title">Confidence at<br><em>every step.</em></h2><p>Our aim is a transparent request-to-delivery experience, with a confirmed quote and scope before checkout.</p></div>
        <div class="sg-steps"><article><span class="sg-step-no">01 / SHARE</span><div class="sg-step-icon" aria-hidden="true">↗</div><h3>The vision</h3><p>Tell us what the drawing should capture, and attach reference material.</p></article><article><span class="sg-step-no">02 / CONFIRM</span><div class="sg-step-icon" aria-hidden="true">◎</div><h3>The agreement</h3><p>Review the price, timing, scope, and next steps before any payment.</p></article><article><span class="sg-step-no">03 / CREATE</span><div class="sg-step-icon" aria-hidden="true">✳</div><h3>The artwork</h3><p>A personally commissioned piece, produced to the confirmed brief.</p></article></div>
      </section>
      <section class="sg-cta" id="contact" aria-labelledby="sg-cta-title"><div class="sg-cta-shape" aria-hidden="true">✳</div><span>THE NEXT CHAPTER STARTS HERE</span><h2 id="sg-cta-title">Something<br><em>unmistakably</em> yours.</h2><p data-intake-description>New commission requests are not open. General studio questions are welcome by email.</p><span class="sg-coming" data-intake-label>Requests not open</span><div class="concept-email-actions"><a class="concept-email-link" href="mailto:support@drawmeone.com?subject=Draw%20Me%20One%20inquiry">Email the studio <span aria-hidden="true">↗</span></a><a class="concept-info-link" href="./commission.html" data-intake-cta>Commission information</a></div></section>
      <footer class="sg-footer"><a class="sg-wordmark" href="#top">drawmeone<span>✳</span></a><span>AN ART STUDIO FOR THE ONE-OF-A-KIND</span><a href="#top">BACK TO TOP ↑</a></footer>
    </main>`;
  }

  const templates={archive:atelier,journal:edition,cinema:signature};
  function activate(site) {
    if(active) {
      // The real gallery is a single node; always rescue it before removing a concept.
      studioGallery.insertBefore(feed,endMark);
      active.remove();active=null;
    }
    studioGallery.id="portfolio";
    studioProcess.id="process";
    studioContact.id="contact";
    studioHeader.id="top";
    if(!templates[site]) {
      studioMain.hidden=false;studioHeader.hidden=false;studioFooter.hidden=false;
      return;
    }
    studioGallery.id="studio-portfolio";
    studioProcess.id="studio-process";
    studioContact.id="studio-contact";
    studioHeader.id="studio-top";
    const host=document.createElement("div");
    host.innerHTML=templates[site]().trim();
    active=host.firstElementChild;
    if(!active) return;
    studioMain.hidden=true;studioHeader.hidden=true;studioFooter.hidden=true;
    studioMain.insertAdjacentElement("afterend",active);
    const mount=active.querySelector(".concept-gallery-slot");
    if(mount) mount.appendChild(feed);
    const footer=active.querySelector("footer");
    if(footer)footer.insertAdjacentHTML("beforeend","<nav class=\"public-footer-links\" aria-label=\"Footer links\"><a href=\"./services.php\">Services &amp; pricing</a><a href=\"./policy.php\">Terms &amp; privacy</a><a href=\"./account.html\">My account</a></nav>");
  }
  document.addEventListener("dmo:site-change",event=>{
    activate(event.detail.site);
    if(window.location.hash && !document.getElementById(window.location.hash.slice(1))) {
      try {history.replaceState(null,"",location.pathname+location.search);} catch (_) {}
    }
    window.scrollTo(0,0);
  });
  activate(root.dataset.site);
})();
