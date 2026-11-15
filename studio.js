"use strict";
(() => {
  const root = document.documentElement;
  const key = "dmo-preview-settings";
  const allowed = {
    design: ["noir", "canvas", "editorial"],
    layout: ["masonry", "mosaic", "rows"],
    mode: ["dark", "light"]
  };
  const suggestedLayout = { noir: "masonry", canvas: "rows", editorial: "mosaic" };
  const modeIcon = document.getElementById("header-mode-icon");
  const modeShortcut = document.getElementById("header-mode");
  const lab = document.getElementById("design-lab");
  const collapse = document.getElementById("lab-collapse");

  function save() {
    try {
      localStorage.setItem(key, JSON.stringify({
        design: root.dataset.design,
        layout: root.dataset.layout,
        mode: root.dataset.mode
      }));
    } catch (_) { /* Preview still works without browser storage. */ }
  }
  function reflect() {
    for (const name of Object.keys(allowed)) {
      document.querySelectorAll("[data-" + name + "-choice]").forEach((button) => {
        const selected = button.getAttribute("data-" + name + "-choice") === root.dataset[name];
        button.setAttribute("aria-pressed", String(selected));
      });
    }
    if (modeIcon) modeIcon.textContent = root.dataset.mode === "dark" ? "☀" : "☾";
    if (modeShortcut) modeShortcut.setAttribute("aria-label", root.dataset.mode === "dark" ? "Switch to light mode" : "Switch to dark mode");
    const themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor) themeColor.setAttribute("content", getComputedStyle(document.body).backgroundColor || (root.dataset.mode === "dark" ? "#0e0f12" : "#f7f7f5"));
  }
  function choose(name, value) {
    if (!allowed[name] || !allowed[name].includes(value)) return;
    root.dataset[name] = value;
    if (name === "design") root.dataset.layout = suggestedLayout[value];
    reflect();
    save();
  }
  for (const name of Object.keys(allowed)) {
    document.querySelectorAll("[data-" + name + "-choice]").forEach((button) => {
      button.addEventListener("click", () => choose(name, button.getAttribute("data-" + name + "-choice")));
    });
  }
  if (modeShortcut) modeShortcut.addEventListener("click", () => {
    choose("mode", root.dataset.mode === "dark" ? "light" : "dark");
  });
  if (lab && collapse) {
    collapse.addEventListener("click", () => {
      const minimized = lab.classList.toggle("is-collapsed");
      collapse.setAttribute("aria-expanded", String(!minimized));
      collapse.innerHTML = minimized ? 'Expand <span aria-hidden="true">⌃</span>' : 'Minimize <span aria-hidden="true">⌄</span>';
    });
  }
  reflect();
})();
