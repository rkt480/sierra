(() => {
  if (window.__crmNavigationReady) {
    return;
  }

  window.__crmNavigationReady = true;

  const overlay = document.createElement("div");
  overlay.className = "crm-navigation-overlay";
  overlay.setAttribute("aria-hidden", "true");
  overlay.innerHTML = '<span class="crm-navigation-spinner" role="status" aria-label="Carregando"></span>';
  document.body.appendChild(overlay);
  let navigationTimer = null;

  const hideNavigationState = () => {
    if (navigationTimer !== null) {
      window.clearTimeout(navigationTimer);
      navigationTimer = null;
    }

    document.body.classList.remove("is-navigating");
    document.body.removeAttribute("aria-busy");
    overlay.setAttribute("aria-hidden", "true");
  };

  const showNavigationState = () => {
    // Fast page changes should feel immediate. Only show the loading state
    // when the browser is still waiting after a short grace period.
    navigationTimer = window.setTimeout(() => {
      document.body.classList.add("is-navigating");
      document.body.setAttribute("aria-busy", "true");
      overlay.setAttribute("aria-hidden", "false");
      navigationTimer = null;
    }, 140);
  };

  const prefetchedUrls = new Set();
  const prefetchLink = (link) => {
    if (!link || link.dataset.noNavigationPrefetch !== undefined) {
      return;
    }

    const href = link.getAttribute("href") || "";

    if (!href || href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:")) {
      return;
    }

    const destination = new URL(link.href, window.location.href);

    if (destination.origin !== window.location.origin
      || destination.pathname.includes("/api/")
      || destination.pathname.endsWith("/logout.php")
      || destination.pathname.endsWith("/export.php")) {
      return;
    }

    if (prefetchedUrls.has(destination.href)) {
      return;
    }

    prefetchedUrls.add(destination.href);
    const hint = document.createElement("link");
    hint.rel = "prefetch";
    hint.as = "document";
    hint.href = destination.href;
    document.head.appendChild(hint);
  };

  const isNavigableInternalLink = (link, event) => {
    if (!link || event.defaultPrevented || event.button !== 0) {
      return false;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }

    if (link.target && link.target !== "_self") {
      return false;
    }

    if (link.hasAttribute("download") || link.dataset.noNavigationTransition !== undefined) {
      return false;
    }

    const href = link.getAttribute("href") || "";

    if (href === "" || href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:")) {
      return false;
    }

    const destination = new URL(link.href, window.location.href);

    if (destination.origin !== window.location.origin) {
      return false;
    }

    return destination.href !== window.location.href;
  };

  document.addEventListener("click", (event) => {
    const link = event.target.closest?.("a[href]");

    if (isNavigableInternalLink(link, event)) {
      showNavigationState();
    }
  }, true);

  const warmNavigationTarget = (event) => {
    // A touchstart happens immediately before the real click navigation. On
    // mobile, prefetching here starts a second request for the same PHP
    // session and can make the destination wait for the prefetch to finish.
    // Keep prefetching for mouse hover and keyboard focus, where it has time
    // to warm a page before the user activates the link.
    if (event.type === "pointerover" && event.pointerType !== "mouse") {
      return;
    }

    const link = event.target.closest?.("a[href]");
    prefetchLink(link);
  };

  document.addEventListener("pointerover", warmNavigationTarget, { passive: true });
  document.addEventListener("focusin", warmNavigationTarget);

  window.addEventListener("pageshow", hideNavigationState);
  window.addEventListener("pagehide", () => {
    window.setTimeout(hideNavigationState, 0);
  });
})();
