(() => {
  const body = document.body;

  body.classList.add("admin-motion");
  requestAnimationFrame(() => body.classList.add("admin-ready"));

  const showSessionTransition = (form) => {
    if (document.querySelector(".admin-transition")) return;
    const overlay = document.createElement("div");
    overlay.className = "admin-transition";
    overlay.setAttribute("role", "status");
    overlay.setAttribute("aria-live", "polite");
    overlay.innerHTML = '<span class="admin-transition__logo" aria-hidden="true"></span><strong>Securing your session</strong><small>Please wait while HelloWrandell loads safely.</small><i aria-hidden="true"></i>';
    document.body.append(overlay);
    window.setTimeout(() => form.submit(), 7000);
  };
  document.querySelectorAll("form").forEach((form) => {
    const isLogin = body.classList.contains("auth-page")
      && form.querySelector('input[name="password"]')
      && !form.querySelector('input[name="password_confirmation"]');
    const isLogout = (form.getAttribute("action") || "").toLowerCase().includes("logout.php");
    if (!isLogin && !isLogout) return;
    form.addEventListener("submit", (event) => {
      if (form.dataset.transitionStarted === "true") return;
      event.preventDefault();
      form.dataset.transitionStarted = "true";
      showSessionTransition(form);
    });
  });

  const revealTargets = Array.from(document.querySelectorAll(".page-head, .command-hero, .stat, .card, .editor-shell, .security-overview, .admin-project-card, .table-wrap"));
  revealTargets.forEach((element, index) => {
    element.classList.add("admin-reveal");
    element.style.setProperty("--admin-delay", `${Math.min((index % 6) * 55, 275)}ms`);
  });
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("admin-reveal--in");
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: 0.08, rootMargin: "0px 0px -4%" });
  revealTargets.forEach((element) => revealObserver.observe(element));
  const menuButton = document.querySelector("[data-menu]");
  const menuPanel = document.querySelector("[data-menu-panel]");
  const menuScrim = document.querySelector(".mobile-scrim");
  const menuCloseButtons = Array.from(document.querySelectorAll("[data-menu-close]"));
  const adminWorkspace = document.querySelector(".admin-workspace");
  const mobileMenuQuery = window.matchMedia("(max-width: 980px)");
  const menuFocusableSelector = [
    "a[href]",
    "button:not([disabled])",
    "input:not([disabled])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    "[tabindex]:not([tabindex='-1'])",
  ].join(",");
  let menuReturnFocus = null;

  const setElementInert = (element, inert) => {
    if (!element) return;
    element.inert = inert;
    if (inert) element.setAttribute("inert", "");
    else element.removeAttribute("inert");
  };

  const menuFocusables = () => menuPanel
    ? Array.from(menuPanel.querySelectorAll(menuFocusableSelector)).filter((element) => element.getClientRects().length > 0)
    : [];

  const setMenu = (requestedOpen, { restoreFocus = true } = {}) => {
    const open = Boolean(requestedOpen && mobileMenuQuery.matches);
    const wasOpen = body.classList.contains("menu-open");

    if (open && !wasOpen) menuReturnFocus = document.activeElement;
    body.classList.toggle("menu-open", open);
    menuButton?.setAttribute("aria-expanded", String(open));
    menuButton?.setAttribute("aria-label", open ? "Close CMS navigation" : "Open CMS navigation");

    if (mobileMenuQuery.matches) {
      menuPanel?.setAttribute("aria-hidden", String(!open));
      setElementInert(menuPanel, !open);
    } else {
      menuPanel?.removeAttribute("aria-hidden");
      setElementInert(menuPanel, false);
    }

    setElementInert(adminWorkspace, open);
    menuScrim?.setAttribute("aria-hidden", String(!open));
    if (menuScrim) menuScrim.tabIndex = open ? 0 : -1;

    if (open) {
      window.setTimeout(() => {
        const preferredTarget = menuPanel?.querySelector(".sidebar-close, nav a.active");
        (preferredTarget || menuFocusables()[0])?.focus({ preventScroll: true });
      }, 60);
    } else if (wasOpen && restoreFocus && menuReturnFocus instanceof HTMLElement && document.contains(menuReturnFocus)) {
      menuReturnFocus.focus({ preventScroll: true });
      menuReturnFocus = null;
    }
  };

  const syncResponsiveMenu = () => setMenu(false, { restoreFocus: false });

  menuButton?.addEventListener("click", () => setMenu(!body.classList.contains("menu-open")));
  menuCloseButtons.forEach((control) => control.addEventListener("click", () => setMenu(false)));
  document.querySelectorAll(".sidebar nav a").forEach((link) => {
    link.addEventListener("click", () => setMenu(false, { restoreFocus: false }));
  });
  mobileMenuQuery.addEventListener?.("change", syncResponsiveMenu);
  syncResponsiveMenu();

  document.querySelectorAll(".data-table").forEach((table) => {
    const labels = Array.from(table.querySelectorAll("thead th")).map((heading) => heading.textContent?.trim() || "Field");
    table.querySelectorAll("tbody tr").forEach((row) => {
      Array.from(row.children).forEach((cell, index) => {
        if (cell instanceof HTMLTableCellElement && !cell.hasAttribute("colspan")) {
          cell.dataset.label = labels[index] || "Field";
        }
      });
    });
  });

  const secureDialog = document.querySelector("[data-secure-dialog]");
  const secureDialogForm = secureDialog?.querySelector("form");
  const secureMessage = secureDialog?.querySelector("[data-secure-message]");
  const securePassword = secureDialog?.querySelector("[data-secure-password]");
  const secureError = secureDialog?.querySelector("[data-secure-error]");
  let pendingForm = null;
  let pendingSubmitter = null;

  document.querySelectorAll("form[data-secure-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (form.dataset.secureConfirmed === "true") {
        delete form.dataset.secureConfirmed;
        return;
      }
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter;
      if (!secureDialog || typeof secureDialog.showModal !== "function") {
        const password = window.prompt("Enter your current administrator password to continue.");
        if (!password) return;
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "confirm_password";
        input.value = password;
        form.append(input);
        form.dataset.secureConfirmed = "true";
        form.requestSubmit(pendingSubmitter);
        return;
      }
      secureMessage.textContent = form.dataset.secureConfirm || "Confirm this permanent action.";
      securePassword.value = "";
      secureError.textContent = "";
      secureDialog.showModal();
      window.setTimeout(() => securePassword.focus(), 40);
    });
  });

  secureDialogForm?.addEventListener("submit", (event) => {
    const submitter = event.submitter;
    if (submitter?.value !== "confirm") {
      pendingForm = null;
      pendingSubmitter = null;
      return;
    }
    event.preventDefault();
    if (!pendingForm || !securePassword.value) {
      secureError.textContent = "Enter your current administrator password.";
      securePassword.focus();
      return;
    }
    pendingForm.querySelector('input[name="confirm_password"]')?.remove();
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "confirm_password";
    input.value = securePassword.value;
    pendingForm.append(input);
    const form = pendingForm;
    const submitButton = pendingSubmitter;
    pendingForm = null;
    pendingSubmitter = null;
    securePassword.value = "";
    secureDialog.close();
    form.dataset.secureConfirmed = "true";
    form.requestSubmit(submitButton);
  });

  const dirtyForms = Array.from(document.querySelectorAll("form[data-dirty-form]"));
  const saveIndicator = document.querySelector("[data-save-indicator]");
  let dirty = false;

  const setDirty = (value) => {
    dirty = value;
    saveIndicator?.classList.toggle("visible", value);
  };

  dirtyForms.forEach((form) => {
    form.addEventListener("input", () => setDirty(true));
    form.addEventListener("change", () => setDirty(true));
    form.addEventListener("submit", () => setDirty(false));
  });

  document.querySelectorAll("form").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (event.defaultPrevented) return;
      form.classList.add("is-submitting");
      const submitter = event.submitter;
      if (submitter instanceof HTMLButtonElement) {
        if (submitter.name) {
          const submittedAction = document.createElement("input");
          submittedAction.type = "hidden";
          submittedAction.name = submitter.name;
          submittedAction.value = submitter.value;
          form.append(submittedAction);
        }
        submitter.dataset.originalText = submitter.textContent || "";
        submitter.textContent = "Working…";
        submitter.disabled = true;
      }
    });
  });

  const previewInput = document.querySelector('input[type="file"][name="preview_image"]');
  previewInput?.addEventListener("change", () => {
    const file = previewInput.files?.[0];
    if (!file || !file.type.startsWith("image/")) return;
    let image = document.querySelector(".media-card .preview");
    const placeholder = document.querySelector(".media-card .upload-placeholder");
    if (!image) {
      image = document.createElement("img");
      image.className = "preview";
      image.alt = "Selected project preview";
      placeholder?.replaceWith(image);
    }
    image.src = URL.createObjectURL(file);
  });


  document.querySelectorAll("[data-preview-input]").forEach((input) => {
    input.addEventListener("change", () => {
      const file = input.files?.[0];
      const preview = input.closest("form")?.querySelector("[data-image-preview]");
      if (!file || !file.type.startsWith("image/") || !preview) return;
      let image = preview.querySelector("img");
      if (!image) {
        image = document.createElement("img");
        image.alt = "Selected credential badge preview";
        preview.replaceChildren(image);
      }
      image.src = URL.createObjectURL(file);
    });
  });

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  window.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && body.classList.contains("menu-open")) {
      event.preventDefault();
      setMenu(false);
      return;
    }

    if (event.key !== "Tab" || !body.classList.contains("menu-open")) return;
    const focusable = menuFocusables();
    if (!focusable.length) {
      event.preventDefault();
      menuPanel?.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  window.addEventListener("resize", () => {
    if (!mobileMenuQuery.matches) setMenu(false, { restoreFocus: false });
  });
})();
