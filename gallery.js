"use strict";
// The portfolio never requires a title or description. Demo photography is
// isolated from real artwork, so its presence cannot contaminate portfolio data.
(() => {
  const gallery = document.getElementById("portfolio-gallery");
  const notice = document.getElementById("gallery-demo-notice");
  const empty = document.getElementById("portfolio-empty");
  const error = document.getElementById("portfolio-error");
  const dialog = document.getElementById("portfolio-lightbox");
  const big = document.getElementById("lightbox-image");
  const count = document.getElementById("lightbox-counter");
  if (!gallery || !notice || !empty || !error || !dialog || !big || !count) return;

  const realImage = /^assets\/portfolio\/[a-z0-9][a-z0-9/_-]*\.(avif|webp|png|jpe?g|svg)$/i;
  const demoImage = /^https:\/\/picsum\.photos\/seed\/dmo-[a-z0-9-]+\/[0-9]{2,4}\/[0-9]{2,4}$/;
  const fallback = "./assets/demo/placeholder.svg";
  let entries = [];
  let current = 0;
  let lastFocused = null;
  let gestureStartX = null;

  const isValid = (item, demo) => !!item &&
    typeof item.src === "string" &&
    (demo ? demoImage.test(item.src) : realImage.test(item.src)) &&
    (item.alt == null || (typeof item.alt === "string" && item.alt.length <= 260));
  function altText(item, demo) {
    return typeof item.alt === "string" && item.alt.trim()
      ? item.alt.trim()
      : demo ? "Sample photograph for this design preview" : "Original drawing by Draw Me One";
  }
  function withFallback(img) {
    img.addEventListener("error", () => {
      if (!img.dataset.fallback) {
        img.dataset.fallback = "1";
        img.src = fallback;
      }
    });
  }
  function displayImage(position) {
    if (!entries.length) return;
    current = (position + entries.length) % entries.length;
    const item = entries[current];
    big.src = item.src;
    big.alt = altText(item, notice.dataset.demo === "true");
    delete big.dataset.fallback;
    count.textContent = (current + 1) + " / " + entries.length;
  }
  function openViewer(position, trigger) {
    lastFocused = trigger;
    displayImage(position);
    if (typeof dialog.showModal === "function") {
      dialog.showModal();
      dialog.querySelector("[data-gallery-close]").focus();
    } else {
      window.open(entries[position].src, "_blank", "noopener,noreferrer");
    }
  }
  const closeButton = dialog.querySelector("[data-gallery-close]");
  const prevButton = dialog.querySelector("[data-gallery-prev]");
  const nextButton = dialog.querySelector("[data-gallery-next]");
  closeButton.addEventListener("click", () => dialog.close());
  prevButton.addEventListener("click", () => displayImage(current - 1));
  nextButton.addEventListener("click", () => displayImage(current + 1));
  dialog.addEventListener("click", event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener("close", () => {
    big.removeAttribute("src");
    if (lastFocused && lastFocused.isConnected) lastFocused.focus();
  });
  dialog.addEventListener("keydown", event => {
    if (event.key === "ArrowLeft") { event.preventDefault(); displayImage(current - 1); }
    if (event.key === "ArrowRight") { event.preventDefault(); displayImage(current + 1); }
  });
  dialog.querySelector(".lightbox-stage").addEventListener("touchstart", event => {
    if (event.touches.length === 1) gestureStartX = event.touches[0].clientX;
  }, {passive:true});
  dialog.querySelector(".lightbox-stage").addEventListener("touchend", event => {
    if (gestureStartX == null || event.changedTouches.length !== 1) return;
    const distance = event.changedTouches[0].clientX - gestureStartX;
    gestureStartX = null;
    if (Math.abs(distance) > 65) displayImage(current + (distance < 0 ? 1 : -1));
  }, {passive:true});
  withFallback(big);

  async function readManifest(path) {
    const response = await fetch(path, {cache:"no-cache"});
    if (!response.ok) throw new Error(path + " returned HTTP " + response.status);
    const data = await response.json();
    if (!data || !Array.isArray(data.works)) throw new Error("Invalid portfolio manifest " + path);
    return data.works;
  }
  async function load() {
    let real = await readManifest("./data/portfolio.json");
    real = real.filter(item => isValid(item, false));
    const demo = real.length === 0;
    const items = demo ? (await readManifest("./data/demo-portfolio.json")).filter(item => isValid(item, true)) : real;
    if (!items.length) { empty.hidden = false; return; }
    entries = items;
    notice.hidden = !demo;
    notice.dataset.demo = String(demo);

    const fragment = document.createDocumentFragment();
    items.forEach((item, index) => {
      const tile = document.createElement("button");
      tile.type = "button";
      tile.className = "art-tile";
      tile.setAttribute("aria-label", "View image " + (index + 1) + " of " + items.length + ": " + altText(item,demo));
      const img = document.createElement("img");
      img.src = item.src;
      img.alt = altText(item, demo);
      img.loading = index < 3 ? "eager" : "lazy";
      img.decoding = "async";
      if (Number.isInteger(item.width) && item.width > 0) img.width = item.width;
      if (Number.isInteger(item.height) && item.height > 0) img.height = item.height;
      withFallback(img);
      tile.appendChild(img);
      tile.addEventListener("click", () => openViewer(index, tile));
      fragment.appendChild(tile);
    });
    gallery.replaceChildren(fragment);
    gallery.classList.add("is-loaded");
    gallery.setAttribute("aria-label", demo ? "Sample photos used to preview the gallery layout, not the artist's drawings" : "Original artworks by Draw Me One");
  }
  load().catch(problem => {
    console.warn("Draw Me One gallery loading problem:", problem);
    error.hidden = false;
  });
})();
