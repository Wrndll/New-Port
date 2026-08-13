(() => {
  "use strict";

  const base = document.documentElement.dataset.base || "/HelloWrandell";
  const reduceMotion = false;
  document.documentElement.dataset.motion = "full";
  const finePointer = matchMedia("(pointer: fine)").matches;
  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];

  const projectsSection = qs("#projects");
  const experienceSection = qs("#experience");
  if (
    projectsSection
    && experienceSection
    && (experienceSection.compareDocumentPosition(projectsSection) & Node.DOCUMENT_POSITION_PRECEDING)
  ) {
    projectsSection.before(experienceSection);
  }

  const footerLogo = qs(".footer-brand img");
  if (footerLogo) {
    const footerMark = document.createElement("span");
    footerMark.className = "brand-mark";
    footerMark.setAttribute("aria-hidden", "true");
    footerLogo.replaceWith(footerMark);
  }
  const footerLinks = qs(".footer-links");
  const footerExperience = qs('a[href="#experience"]', footerLinks || document);
  const footerProjects = qs('a[href="#projects"]', footerLinks || document);
  if (footerLinks && footerExperience && footerProjects) {
    footerLinks.insertBefore(footerExperience, footerProjects);
  }

  let lastFocusedElement = null;
  let projectData = [];

  const revealInterface = () => {
    if (document.body.classList.contains("ready")) return;
    qs(".loader")?.classList.add("hide");
    document.body.classList.add("ready");
  };
  const heroImage = qs(".portrait img");
  const interfaceDeadline = window.setTimeout(revealInterface, reduceMotion ? 40 : 920);
  const startInterface = async () => {
    try {
      if (heroImage instanceof HTMLImageElement && !heroImage.complete && typeof heroImage.decode === "function") {
        await Promise.race([
          heroImage.decode(),
          new Promise((resolve) => window.setTimeout(resolve, 520)),
        ]);
      }
    } catch {
      // The static page remains usable even when an image decoder rejects.
    } finally {
      window.clearTimeout(interfaceDeadline);
      window.setTimeout(revealInterface, reduceMotion ? 0 : 80);
    }
  };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startInterface, { once: true });
  } else {
    startInterface();
  }

  const nav = qs(".site-nav");
  const progress = qs(".progress");
  const hero = qs(".hero");
  const navLinks = qsa('.nav-links a[href^="#"], .mobile-menu a[href^="#"]');
  const sectionIds = navLinks.map((link) => link.getAttribute("href")?.slice(1)).filter(Boolean);
  const sections = [...new Set(sectionIds)].map((id) => document.getElementById(id)).filter(Boolean);

  const updateScrollState = () => {
    const y = window.scrollY;
    nav?.classList.toggle("scrolled", y > 18);
    const height = document.documentElement.scrollHeight - innerHeight;
    if (progress) progress.style.width = `${height > 0 ? Math.min(100, (y / height) * 100) : 0}%`;
    if (hero) {
      const desktopMotion = !reduceMotion;
      const heroHeight = Math.max(hero.offsetHeight - innerHeight * 0.32, 1);
      const ratio = desktopMotion ? Math.min(1, Math.max(0, y / heroHeight)) : 0;
      // Keep the portrait at a steady scale while the copy and visual layers
      // settle independently. The fixed scale avoids the unnatural punch-in
      // during the handoff to the next section.
      const zoom = 1;
      hero.style.setProperty("--hero-scroll", ratio.toFixed(3));
      hero.style.setProperty("--hero-mobile-shift", `${(-ratio * 18).toFixed(1)}px`);
      hero.style.setProperty("--hero-mobile-rotation", `${(ratio * 1.2).toFixed(2)}deg`);
      hero.style.setProperty("--hero-zoom", zoom.toFixed(3));
      hero.style.setProperty("--hero-copy-opacity", Math.max(0.18, 1 - ratio * .95).toFixed(3));
      hero.style.setProperty("--hero-copy-shift", `${(ratio * 58).toFixed(1)}px`);
      hero.style.setProperty("--hero-copy-blur", `${(ratio * 5).toFixed(1)}px`);
      hero.style.setProperty("--hero-visual-shift-x", `${(-ratio * 48).toFixed(1)}px`);
      hero.style.setProperty("--hero-visual-shift-y", `${(-ratio * 34).toFixed(1)}px`);
      hero.style.setProperty("--hero-visual-opacity", Math.max(0.74, 1 - ratio * .22).toFixed(3));
    }
  };
  let scrollStateFrame = 0;
  const scheduleScrollState = () => {
    if (scrollStateFrame) return;
    scrollStateFrame = requestAnimationFrame(() => {
      scrollStateFrame = 0;
      updateScrollState();
    });
  };
  updateScrollState();
  window.addEventListener("scroll", scheduleScrollState, { passive: true });
  window.addEventListener("resize", scheduleScrollState, { passive: true });

  const menuButton = qs(".menu");
  const mobileMenu = qs(".mobile-menu");
  const setMenuOpen = (open) => {
    if (!mobileMenu) return;
    if (!open && mobileMenu.contains(document.activeElement)) {
      menuButton?.focus({ preventScroll: true });
    }
    mobileMenu.toggleAttribute("inert", !open);
    mobileMenu.classList.toggle("open", open);
    mobileMenu.setAttribute("aria-hidden", String(!open));
    menuButton?.setAttribute("aria-expanded", String(open));
    menuButton?.setAttribute("aria-label", open ? "Close navigation" : "Open navigation");
    document.body.classList.toggle("menu-open", open);
  };
  menuButton?.setAttribute("aria-expanded", "false");
  menuButton?.addEventListener("click", () => {
    const opening = !mobileMenu?.classList.contains("open");
    setMenuOpen(opening);
    if (opening) window.setTimeout(() => qs(".mobile-menu__close")?.focus(), reduceMotion ? 0 : 40);
  });
  qs(".mobile-menu__close")?.addEventListener("click", () => setMenuOpen(false));
  mobileMenu?.addEventListener("click", (event) => {
    if (event.target === mobileMenu) setMenuOpen(false);
  });
  qsa(".mobile-menu a").forEach((link) => link.addEventListener("click", () => setMenuOpen(false)));

  const contactSection = qs("#contact");
  const contactModal = qs("#contact-modal");
  const contactModalBody = qs("[data-contact-modal-body]", contactModal || document);
  const contactGrid = qs(".contact-grid", contactSection || document);
  const openContact = (trigger, updateHistory = true) => {
    if (!contactModal) return;
    if (updateHistory) {
      if (location.hash !== "#contact") history.pushState(null, "", "#contact");
      else history.replaceState(null, "", "#contact");
    }
    openModal(contactModal, trigger);
  };

  if (contactSection && contactGrid && contactModalBody) {
    contactGrid.classList.remove("shell");
    contactGrid.classList.add("contact-modal-grid");
    contactModalBody.append(contactGrid);

    const launchShell = document.createElement("div");
    const launchCard = document.createElement("div");
    const launchCopy = document.createElement("div");
    const launchEyebrow = document.createElement("div");
    const launchTitle = document.createElement("h2");
    const launchDescription = document.createElement("p");
    const launchAction = document.createElement("button");
    const launchMeta = document.createElement("div");
    const launchStatus = document.createElement("span");
    const launchReply = document.createElement("span");

    launchShell.className = "shell contact-launch";
    launchCard.className = "contact-launch__card reveal";
    launchCopy.className = "contact-launch__copy";
    launchEyebrow.className = "eyebrow";
    launchEyebrow.textContent = "Direct contact";
    launchTitle.textContent = "One clear conversation can move a technical problem forward.";
    launchDescription.textContent = "Open a focused contact form for opportunities, support needs, or project enquiries.";
    launchAction.className = "btn-primary contact-launch__action";
    launchAction.type = "button";
    launchAction.textContent = "Start a conversation";
    launchAction.addEventListener("click", () => openContact(launchAction));
    launchMeta.className = "contact-launch__meta";
    launchStatus.textContent = "Available for opportunities";
    launchReply.textContent = "Private CMS inbox";
    launchMeta.append(launchStatus, launchReply);
    launchCopy.append(launchEyebrow, launchTitle, launchDescription);
    launchCard.append(launchCopy, launchAction, launchMeta);
    launchShell.append(launchCard);
    contactSection.append(launchShell);
  }

  const contentModal = qs("#content-modal");
  const contentModalTitle = qs("[data-content-modal-title]", contentModal || document);
  const contentModalBody = qs("[data-content-modal-body]", contentModal || document);
  const compactSections = [
    { id: "about", title: "About and the problems I solve", label: "View full profile", selectors: [".about-grid"] },
    { id: "experience", title: "Professional experience", label: "View all experience", selectors: [".timeline"] },
    { id: "projects", title: "Selected project case studies", label: "View all projects", selectors: [".projects"] },
    { id: "skills", title: "Technical toolkit", label: "View all skills", selectors: [".skill-list"] },
    { id: "certifications", title: "Certifications and credentials", label: "View all credentials", selectors: [".cert-grid", ".education"] },
    { id: "approach", title: "My support and delivery approach", label: "View full approach", selectors: [".process", ".strengths"] },
  ];

  const openSectionDetails = (config, trigger) => {
    if (!contentModal || !contentModalBody || !contentModalTitle) return;
    const sourceSection = document.getElementById(config.id);
    const fragment = document.createDocumentFragment();
    config.selectors.forEach((selector) => {
      const source = qs(selector, sourceSection || document);
      if (!source) return;
      const clone = source.cloneNode(true);
      clone.classList.add("content-modal__clone");
      clone.removeAttribute("id");
      qsa("[id]", clone).forEach((element) => element.removeAttribute("id"));
      qsa("[hidden]", clone).forEach((element) => element.removeAttribute("hidden"));
      qsa(".reveal", clone).forEach((element) => element.classList.add("in"));
      qsa("[data-project]", clone).forEach((projectButton) => {
        projectButton.addEventListener("click", () => {
          const original = qsa("#projects [data-project]").find((candidate) => candidate.dataset.project === projectButton.dataset.project);
          closeModal(contentModal);
          window.setTimeout(() => original?.click(), 220);
        });
      });
      fragment.append(clone);
    });
    contentModalTitle.textContent = config.title;
    contentModalBody.replaceChildren(fragment);
    openModal(contentModal, trigger);
  };

  compactSections.forEach((config) => {
    const section = document.getElementById(config.id);
    const shell = qs(":scope > .shell", section || document);
    if (!section || !shell) return;
    const more = document.createElement("div");
    const button = document.createElement("button");
    more.className = "section-more";
    button.className = "btn-secondary";
    button.type = "button";
    button.textContent = config.label;
    button.setAttribute("aria-haspopup", "dialog");
    button.addEventListener("click", () => openSectionDetails(config, button));
    more.append(button);
    shell.append(more);
  });

  if (location.hash === "#contact") {
    window.setTimeout(() => openContact(null, false), 120);
  }
  window.addEventListener("popstate", () => {
    if (location.hash === "#contact") openContact(null, false);
    else if (contactModal?.classList.contains("open")) closeModal(contactModal);
  });


  const easeCinematic = (value) => value < 0.5
    ? 4 * value * value * value
    : 1 - Math.pow(-2 * value + 2, 3) / 2;

  const scrollToSection = (target) => {
    if (!target) return 0;
    if (reduceMotion) {
      target.scrollIntoView({ block: "start" });
      return 0;
    }
    const start = window.scrollY;
    const navOffset = nav?.offsetHeight || 84;
    const destination = Math.max(0, target.getBoundingClientRect().top + start - navOffset + 1);
    const distance = destination - start;
    const duration = Math.min(1100, Math.max(620, Math.abs(distance) * 0.42));
    const startedAt = performance.now();
    const frame = (now) => {
      const progressValue = Math.min(1, (now - startedAt) / duration);
      window.scrollTo(0, start + distance * easeCinematic(progressValue));
      if (progressValue < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
    return duration;
  };

  qsa('a[href^="#"]').forEach((link) => link.addEventListener("click", (event) => {
    const href = link.getAttribute("href");
    if (!href || href === "#") return;
    const target = document.getElementById(href.slice(1));
    if (!target) return;
    event.preventDefault();
    setMenuOpen(false);
    if (href === "#contact") {
      openContact(link);
      return;
    }
    document.body.classList.remove("is-navigating");
    void document.body.offsetWidth;
    document.body.classList.add("is-navigating");
    link.classList.remove("route-pressed");
    void link.offsetWidth;
    link.classList.add("route-pressed");
    target.classList.add("transition-target");
    const travelDuration = scrollToSection(target);
    if (location.hash !== href) history.pushState(null, "", href);
    else history.replaceState(null, "", href);
    window.setTimeout(() => {
      document.body.classList.remove("is-navigating");
      target.classList.remove("transition-target");
      link.classList.remove("route-pressed");
      target.classList.remove("nav-arrived");
      void target.offsetWidth;
      target.classList.add("nav-arrived");
      if (!target.hasAttribute("tabindex")) target.setAttribute("tabindex", "-1");
      target.focus({ preventScroll: true });
      window.setTimeout(() => target.classList.remove("nav-arrived"), reduceMotion ? 40 : 900);
    }, reduceMotion ? 40 : Math.max(520, travelDuration));
  }));

  if (sections.length) {
    const sectionObserver = new IntersectionObserver((entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (!visible) return;
      navLinks.forEach((link) => {
        const active = link.getAttribute("href") === `#${visible.target.id}`;
        link.classList.toggle("active", active);
        if (active) link.setAttribute("aria-current", "page");
        else link.removeAttribute("aria-current");
      });
    }, { rootMargin: "-25% 0px -62% 0px", threshold: [0, .15, .35, .6] });
    sections.forEach((section) => sectionObserver.observe(section));
  }

  const themeSections = qsa("section[data-nav-theme]");
  const updateNavigationTheme = () => {
    if (!nav) return;
    const probeY = Math.min(innerHeight * .18, (nav.offsetHeight || 74) + 34);
    const current = [...themeSections].reverse().find((section) => {
      const bounds = section.getBoundingClientRect();
      return bounds.top <= probeY && bounds.bottom > probeY;
    });
    nav.dataset.theme = current?.dataset.navTheme === "dark" ? "dark" : "light";
  };
  updateNavigationTheme();
  window.addEventListener("scroll", updateNavigationTheme, { passive: true });
  window.addEventListener("resize", updateNavigationTheme, { passive: true });

  qsa(".proof article").forEach((item) => item.classList.add("reveal"));
  const reveals = qsa(".reveal");
  reveals.forEach((item, index) => item.style.setProperty("--reveal-delay", `${Math.min((index % 5) * 70, 280)}ms`));
  if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver((entries) => entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add("in");
      entry.target.closest(".section")?.classList.add("section-in");
      revealObserver.unobserve(entry.target);
    }), { threshold: .12, rootMargin: "0px 0px -6%" });
    reveals.forEach((item) => revealObserver.observe(item));
  } else {
    reveals.forEach((item) => item.classList.add("in"));
  }

  const process = qs(".process");
  if (process) {
    const processObserver = new IntersectionObserver(([entry]) => {
      if (entry?.isIntersecting) {
        process.classList.add("in-view");
        processObserver.disconnect();
      }
    }, { threshold: .2 });
    processObserver.observe(process);
  }

  const roles = [
    "Technical Support Engineer",
    "Web Systems Support",
    "Cloud Support Professional",
    "Systems Problem Solver",
  ];
  let roleIndex = 0;
  const role = qs("[data-role]");
  if (role && !reduceMotion) {
    window.setInterval(() => {
      role.classList.add("changing");
      window.setTimeout(() => {
        roleIndex = (roleIndex + 1) % roles.length;
        role.textContent = roles[roleIndex];
        role.classList.remove("changing");
      }, 230);
    }, 3100);
  }

  const portrait = qs(".portrait-parallax");
  const portraitFrame = qs(".portrait-offset");
  const visual = qs(".visual");
  if (portrait && visual && finePointer && !reduceMotion) {
    visual.addEventListener("pointermove", (event) => {
      const bounds = visual.getBoundingClientRect();
      const x = ((event.clientX - bounds.left) / bounds.width - .5) * 12;
      const y = ((event.clientY - bounds.top) / bounds.height - .5) * 9;
      portrait.style.setProperty("--portrait-x", `${x}px`);
      portrait.style.setProperty("--portrait-y", `${y}px`);
      portraitFrame?.style.setProperty("--frame-x", `${-x * .55}px`);
      portraitFrame?.style.setProperty("--frame-y", `${-y * .55}px`);
      hero?.style.setProperty("--mouse-x", `${x * .45}px`);
      hero?.style.setProperty("--mouse-y", `${y * .45}px`);
    });
    visual.addEventListener("pointerleave", () => {
      portrait.style.setProperty("--portrait-x", "0px");
      portrait.style.setProperty("--portrait-y", "0px");
      portraitFrame?.style.setProperty("--frame-x", "0px");
      portraitFrame?.style.setProperty("--frame-y", "0px");
      hero?.style.setProperty("--mouse-x", "0px");
      hero?.style.setProperty("--mouse-y", "0px");
    });
  }

  const projects = qsa(".project");
  let showingAll = false;
  const showMore = qs("[data-show-more]");
  const animateProjects = (items) => {
    if (reduceMotion) return;
    items.forEach((project, index) => {
      project.classList.remove("project-enter");
      void project.offsetWidth;
      project.style.animationDelay = `${index * 65}ms`;
      project.classList.add("project-enter");
    });
  };
  const applyProjectLimit = () => {
    const changed = [];
    projects.forEach((project, index) => {
      const shouldHide = !showingAll && index > 2;
      if (project.hidden !== shouldHide && !shouldHide) changed.push(project);
      project.hidden = shouldHide;
    });
    if (showMore) {
      showMore.textContent = showingAll ? "Show featured only" : `Show ${Math.max(0, projects.length - 3)} more projects`;
      showMore.setAttribute("aria-expanded", String(showingAll));
    }
    animateProjects(changed);
  };
  applyProjectLimit();
  showMore?.addEventListener("click", () => {
    showingAll = !showingAll;
    applyProjectLimit();
  });

  qsa("[data-filter]").forEach((button) => button.addEventListener("click", () => {
    qsa("[data-filter]").forEach((item) => item.classList.remove("active"));
    button.classList.add("active");
    const filter = button.dataset.filter;
    const visible = [];
    projects.forEach((project) => {
      project.hidden = filter !== "All Projects" && project.dataset.category !== filter;
      if (!project.hidden) visible.push(project);
    });
    if (showMore) showMore.hidden = filter !== "All Projects";
    if (filter === "All Projects") applyProjectLimit();
    else animateProjects(visible);
  }));

  fetch(`${base}/assets/projects.json`, { headers: { Accept: "application/json" }, cache: "no-store" })
    .then((response) => response.ok ? response.json() : [])
    .then((payload) => { projectData = Array.isArray(payload) ? payload : []; })
    .catch(() => { projectData = []; });

  const projectModal = qs("#project-modal");
  qsa("[data-project]").forEach((button) => button.addEventListener("click", () => {
    const project = projectData.find((item) => item.id === button.dataset.project);
    if (!project || !projectModal) return;
    qs("[data-project-title]", projectModal).textContent = project.title;
    qs("[data-project-summary]", projectModal).textContent = project.summary;
    qs("[data-project-status]", projectModal).textContent = project.isConcept
      ? "This is a concept preview, not a completed client-system screenshot."
      : "Only verified case-study information is shown.";
    const details = qs("[data-project-details]", projectModal);
    details.innerHTML = "";
    const labels = {
      overview: "Situation",
      problem: "Problem",
      objectives: "Objectives",
      role: "My role",
      requirements: "Requirements",
      planning: "Investigation and planning",
      solution: "Solution",
      implementation: "Implementation",
      testing: "Validation and testing",
      security: "Security",
      challenges: "Challenges",
      results: "Outcome",
      lessons: "Lessons learned",
    };
    Object.entries(labels).forEach(([key, label]) => {
      if (!project[key]) return;
      const container = document.createElement("div");
      const heading = document.createElement("b");
      const paragraph = document.createElement("p");
      heading.textContent = label;
      paragraph.textContent = String(project[key]);
      container.append(heading, paragraph);
      details.append(container);
    });
    if (!details.children.length) {
      const container = document.createElement("div");
      const heading = document.createElement("b");
      const paragraph = document.createElement("p");
      heading.textContent = "Current status";
      paragraph.textContent = "Additional implementation details have not yet been added to the CMS. Empty fields remain hidden.";
      container.append(heading, paragraph);
      details.append(container);
    }
    openModal(projectModal, button);
  }));

  qsa("[data-close]").forEach((button) => button.addEventListener("click", () => closeModal(button.closest(".modal"))));
  qsa(".modal").forEach((modal) => modal.addEventListener("mousedown", (event) => {
    if (event.target === modal) closeModal(modal);
  }));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      qsa(".modal.open").forEach(closeModal);
      setMenuOpen(false);
    }
    if (event.key === "Tab") trapModalFocus(event);
  });

  qs("[data-resume-open]")?.addEventListener("click", (event) => openModal(qs("#resume-modal"), event.currentTarget));
  qsa("[data-resume-open-secondary]").forEach((button) => button.addEventListener("click", () => openModal(qs("#resume-modal"), button)));

  const enhanceSelect = (select) => {
    if (!(select instanceof HTMLSelectElement) || select.dataset.enhanced === "true") return;
    select.dataset.enhanced = "true";
    select.classList.add("custom-select__native");
    const root = document.createElement("div");
    const trigger = document.createElement("button");
    const value = document.createElement("span");
    const chevron = document.createElement("span");
    const menu = document.createElement("div");
    const options = [...select.options];
    const menuId = `${select.id || select.name}-menu`;
    root.className = "custom-select";
    trigger.className = "custom-select__trigger";
    trigger.type = "button";
    trigger.setAttribute("aria-haspopup", "listbox");
    trigger.setAttribute("aria-controls", menuId);
    trigger.setAttribute("aria-expanded", "false");
    chevron.className = "custom-select__chevron";
    chevron.setAttribute("aria-hidden", "true");
    menu.className = "custom-select__menu";
    menu.id = menuId;
    menu.setAttribute("role", "listbox");
    menu.setAttribute("aria-label", select.labels?.[0]?.textContent?.trim() || "Choose an option");
    const sync = () => {
      const selected = select.options[select.selectedIndex] || options[0];
      value.textContent = selected?.textContent || "Select one";
      root.classList.toggle("has-value", Boolean(select.value));
      qsa(".custom-select__option", menu).forEach((option) => {
        const active = option.dataset.value === select.value;
        option.classList.toggle("selected", active);
        option.setAttribute("aria-selected", String(active));
      });
    };
    const setOpen = (open) => {
      root.classList.toggle("open", open);
      trigger.setAttribute("aria-expanded", String(open));
      if (open) window.setTimeout(() => (qs(".custom-select__option.selected", menu) || qs(".custom-select__option", menu))?.focus(), 0);
    };
    options.forEach((nativeOption) => {
      const option = document.createElement("button");
      option.className = "custom-select__option";
      option.type = "button";
      option.dataset.value = nativeOption.value;
      option.setAttribute("role", "option");
      option.textContent = nativeOption.textContent;
      option.addEventListener("click", () => {
        select.value = nativeOption.value;
        select.dispatchEvent(new Event("change", { bubbles: true }));
        sync();
        setOpen(false);
        trigger.focus();
      });
      option.addEventListener("keydown", (event) => {
        const choices = qsa(".custom-select__option", menu);
        const index = choices.indexOf(option);
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
          event.preventDefault();
          choices[(index + (event.key === "ArrowDown" ? 1 : -1) + choices.length) % choices.length]?.focus();
        } else if (event.key === "Escape") {
          setOpen(false);
          trigger.focus();
        }
      });
      menu.append(option);
    });
    trigger.append(value, chevron);
    root.append(trigger, menu);
    select.insertAdjacentElement("afterend", root);
    trigger.addEventListener("click", () => setOpen(!root.classList.contains("open")));
    trigger.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        setOpen(true);
      } else if (event.key === "Escape") {
        setOpen(false);
      }
    });
    select.addEventListener("change", sync);
    select.form?.addEventListener("reset", () => window.setTimeout(sync, 0));
    document.addEventListener("click", (event) => { if (!root.contains(event.target)) setOpen(false); });
    sync();
  };
  qsa("select").forEach(enhanceSelect);

  const captchaStates = new WeakMap();
  let googleCaptchaLoader = null;
  const loadGoogleCaptcha = (siteKey, version) => {
    if (window.grecaptcha) return Promise.resolve(window.grecaptcha);
    if (googleCaptchaLoader) return googleCaptchaLoader;
    const loader = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.async = true;
      script.defer = true;
      script.src = version === "v3"
        ? `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`
        : "https://www.google.com/recaptcha/api.js?render=explicit";
      script.addEventListener("load", () => resolve(window.grecaptcha), { once: true });
      script.addEventListener("error", () => reject(new Error("Google reCAPTCHA could not be loaded.")), { once: true });
      document.head.append(script);
    });
    googleCaptchaLoader = loader.catch((error) => {
      googleCaptchaLoader = null;
      throw error;
    });
    return googleCaptchaLoader;
  };

  const createCaptchaField = (form, context) => {
    const grid = qs(".form-grid", form);
    if (!grid) return null;
    const field = document.createElement("div");
    const card = document.createElement("div");
    const copy = document.createElement("div");
    const label = document.createElement("span");
    const question = document.createElement("strong");
    const controls = document.createElement("div");
    const answerLabel = document.createElement("label");
    const answer = document.createElement("input");
    const refresh = document.createElement("button");
    const token = document.createElement("input");
    const googleResponse = document.createElement("input");
    const googleHost = document.createElement("div");
    const status = document.createElement("small");
    const answerId = `${context}-captcha-answer`;

    field.className = "field full captcha-field";
    field.dataset.captchaContext = context;
    card.className = "captcha-card is-loading";
    copy.className = "captcha-card__copy";
    label.className = "captcha-card__label";
    label.textContent = "Security check";
    question.dataset.captchaQuestion = "";
    question.textContent = "Preparing a question...";
    controls.className = "captcha-card__controls";
    answerLabel.htmlFor = answerId;
    answerLabel.textContent = "Answer";
    answer.id = answerId;
    answer.name = "captchaAnswer";
    answer.inputMode = "numeric";
    answer.autocomplete = "off";
    answer.maxLength = 8;
    answer.pattern = "-?[0-9]+";
    answer.required = true;
    answer.setAttribute("aria-describedby", `${answerId}-status`);
    refresh.className = "captcha-refresh";
    refresh.type = "button";
    refresh.textContent = "New question";
    token.type = "hidden";
    token.name = "captchaToken";
    googleResponse.type = "hidden";
    googleResponse.name = "captchaResponse";
    googleHost.className = "captcha-google";
    googleHost.hidden = true;
    status.id = `${answerId}-status`;
    status.className = "captcha-status";
    status.dataset.captchaStatus = "";
    status.setAttribute("aria-live", "polite");
    copy.append(label, question);
    controls.append(answerLabel, answer, refresh);
    card.append(copy, controls, token, googleResponse, googleHost);
    field.append(card, status);
    grid.insertBefore(field, qs(".honeypot", grid));

    const state = { context, field, card, question, controls, answer, refresh, token, googleResponse, googleHost, status, config: null, expiresAt: 0, widgetId: null, loading: false };
    captchaStates.set(form, state);
    refresh.addEventListener("click", () => fetchCaptcha(form));
    return state;
  };

  const renderCaptcha = async (form, captcha) => {
    const state = captchaStates.get(form);
    if (!state || !captcha?.provider) return;
    state.config = captcha;
    state.card.classList.remove("is-loading", "has-error");
    state.card.dataset.provider = captcha.provider;
    state.token.value = "";
    state.googleResponse.value = "";
    state.status.textContent = "";
    state.googleHost.replaceChildren();
    state.googleHost.hidden = true;
    state.controls.hidden = false;
    state.answer.disabled = false;
    state.answer.required = true;
    state.answer.value = "";

    if (captcha.provider === "math") {
      state.question.textContent = captcha.question || "Answer the security question.";
      state.token.value = captcha.token || "";
      state.expiresAt = Date.now() + Math.max(30, Number(captcha.expiresIn) || 300) * 1000;
      state.status.textContent = "This one-time question expires in five minutes.";
      return;
    }

    state.controls.hidden = true;
    state.answer.disabled = true;
    state.answer.required = false;
    state.googleHost.hidden = false;
    state.question.textContent = "Protected by Google reCAPTCHA";
    state.status.textContent = "Google verifies this request before it is sent.";
    const grecaptcha = await loadGoogleCaptcha(captcha.siteKey, captcha.version);
    if (captcha.version === "v2_checkbox") {
      state.widgetId = grecaptcha.render(state.googleHost, {
        sitekey: captcha.siteKey,
        callback: (value) => { state.googleResponse.value = value; state.status.textContent = "Security check complete."; },
        "expired-callback": () => { state.googleResponse.value = ""; state.status.textContent = "The security check expired. Complete it again."; },
        "error-callback": () => { state.googleResponse.value = ""; state.status.textContent = "The security check could not be completed."; },
      });
    }
  };

  async function fetchCaptcha(form) {
    const state = captchaStates.get(form);
    if (!state || state.loading) return;
    state.loading = true;
    state.config = null;
    state.expiresAt = 0;
    state.token.value = "";
    state.googleResponse.value = "";
    state.googleHost.replaceChildren();
    state.googleHost.hidden = true;
    state.card.classList.add("is-loading");
    state.card.classList.remove("has-error");
    state.question.textContent = "Preparing a question...";
    state.status.textContent = "";
    state.refresh.disabled = true;
    try {
      const response = await fetch(`${base}/api/captcha.php?context=${encodeURIComponent(state.context)}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store",
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.success || !payload?.captcha) {
        throw new Error(payload?.message || "The security question could not be loaded.");
      }
      await renderCaptcha(form, payload.captcha);
    } catch (error) {
      state.config = null;
      state.card.classList.remove("is-loading");
      state.card.classList.add("has-error");
      state.question.textContent = "Security check unavailable";
      state.status.textContent = error instanceof Error ? error.message : "Refresh the security question and try again.";
    } finally {
      state.loading = false;
      state.refresh.disabled = false;
    }
  }

  async function prepareCaptcha(form) {
    const state = captchaStates.get(form);
    if (!state?.config) throw new Error("Wait for the security question to finish loading.");
    if (state.config.provider === "math") {
      if (Date.now() >= state.expiresAt) {
        await fetchCaptcha(form);
        throw new Error("The security question expired. Answer the new question and submit again.");
      }
      if (!state.token.value || !state.answer.value.trim()) throw new Error("Answer the security question before sending.");
      return;
    }
    const grecaptcha = await loadGoogleCaptcha(state.config.siteKey, state.config.version);
    if (state.config.version === "v3") {
      await new Promise((resolve) => grecaptcha.ready(resolve));
      state.googleResponse.value = await grecaptcha.execute(state.config.siteKey, { action: state.config.action });
    }
    if (!state.googleResponse.value) throw new Error("Complete the Google reCAPTCHA check before sending.");
  }

  const contactForm = qs("#contact-form");
  const resumeForm = qs("#resume-form");
  if (contactForm) createCaptchaField(contactForm, "contact");
  if (resumeForm) createCaptchaField(resumeForm, "resume");

  let contactStarted = Date.now();
  contactForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const notice = qs(".notice", form);
    const button = qs('button[type="submit"]', form);
    try {
      await prepareCaptcha(form);
    } catch (error) {
      notice.className = "notice error";
      notice.textContent = error instanceof Error ? error.message : "Complete the security check before sending.";
      return;
    }
    const data = Object.fromEntries(new FormData(form));
    data.startedAt = contactStarted;
    await submitJson(`${base}/api/contact.php`, data, notice, button, "Message sent successfully.", () => form.reset());
    fetchCaptcha(form);
  });

  let resumeStarted = Date.now();
  resumeForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const notice = qs(".notice", form);
    const button = qs('button[type="submit"]', form);
    try {
      await prepareCaptcha(form);
    } catch (error) {
      notice.className = "notice error";
      notice.textContent = error instanceof Error ? error.message : "Complete the security check before sending.";
      return;
    }
    const data = Object.fromEntries(new FormData(form));
    data.startedAt = resumeStarted;
    await submitJson(`${base}/api/resume-request.php`, data, notice, button, "Verification email sent. Please check your inbox.", () => form.reset());
    fetchCaptcha(form);
  });

  fetch(`${base}/api/content.php`, { headers: { Accept: "application/json" }, cache: "no-store" })
    .then((response) => response.ok ? response.json() : null)
    .then((payload) => {
      if (!payload?.success) return;
      const profile = payload.content?.profile;
      if (profile) {
        qsa("[data-profile-name]").forEach((element) => { element.textContent = profile.name || element.textContent; });
        qsa("[data-profile-title]").forEach((element) => { element.textContent = profile.title || element.textContent; });
        qsa("[data-profile-location]").forEach((element) => { element.textContent = profile.location || element.textContent; });
        qsa("[data-profile-email]").forEach((element) => {
          element.textContent = profile.email || element.textContent;
          if (element.tagName === "A") element.href = `mailto:${profile.email}`;
        });
      }
      const certifications = Array.isArray(payload.content?.certifications) ? payload.content.certifications : [];
      const featured = certifications.filter((item) => item?.featured !== false).slice(0, 3);
      const rail = qs("[data-featured-credentials]");
      if (rail && featured.length) {
        rail.replaceChildren(...featured.map((certification) => {
          const article = document.createElement("article");
          article.className = "credential-card reveal in";
          const emblem = document.createElement("div");
          emblem.className = "credential-emblem";
          if (certification.badgeImage) {
            const image = document.createElement("img");
            image.src = `${base}${certification.badgeImage}`;
            image.alt = certification.badgeAlt || `${certification.name} badge`;
            image.loading = "eager";
            image.decoding = "async";
            emblem.append(image);
          } else {
            emblem.textContent = certification.issuer?.includes("Amazon") ? "AWS" : (certification.issuer || "C").slice(0, 2).toUpperCase();
          }
          const copy = document.createElement("div");
          const issuer = document.createElement("span");
          const name = document.createElement("strong");
          issuer.textContent = certification.issuer || "Credential";
          name.textContent = certification.name || "Certification";
          copy.append(issuer, name);
          article.append(emblem, copy);
          return article;
        }));
      }
      const certificateGrid = qs("[data-cert-grid]");
      if (certificateGrid && certifications.length) {
        certificateGrid.replaceChildren(...certifications.map((certification) => {
          const article = document.createElement("article");
          article.className = "cert card reveal in";
          const badge = document.createElement("div");
          badge.className = "cert-badge";
          if (certification.badgeImage) {
            const image = document.createElement("img");
            image.src = `${base}${certification.badgeImage}`;
            image.alt = certification.badgeAlt || `${certification.name} badge`;
            image.loading = "lazy";
            image.decoding = "async";
            badge.append(image);
          } else {
            const fallback = document.createElement("span");
            fallback.textContent = certification.issuer?.includes("Amazon") ? "AWS" : (certification.issuer || "C").slice(0, 2).toUpperCase();
            badge.append(fallback);
          }
          const issuer = document.createElement("div");
          issuer.className = "issuer";
          issuer.textContent = certification.issuer || "Credential";
          const name = document.createElement("h3");
          name.textContent = certification.name || "Certification";
          article.append(badge, issuer, name);
          if (certification.issueYear) {
            const year = document.createElement("span");
            year.className = "card-number";
            year.textContent = certification.issueYear;
            article.append(year);
          }
          if (certification.verificationUrl) {
            const verify = document.createElement("a");
            verify.className = "cert-verify";
            verify.href = certification.verificationUrl;
            verify.target = "_blank";
            verify.rel = "noopener noreferrer";
            verify.textContent = "Verify credential ↗";
            article.append(verify);
          }
          return article;
        }));
      }
    })
    .catch(() => {});

  function openModal(modal, trigger = null) {
    if (!modal) return;
    lastFocusedElement = trigger || document.activeElement;
    if (modal.id === "resume-modal") resumeStarted = Date.now();
    if (modal.id === "contact-modal") contactStarted = Date.now();
    modal.removeAttribute("inert");
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("modal-open");
    const protectedForm = qs("form", modal);
    const captchaState = protectedForm ? captchaStates.get(protectedForm) : null;
    if (protectedForm && captchaState && !captchaState.loading) {
      const mathExpired = captchaState.config?.provider === "math" && Date.now() >= captchaState.expiresAt;
      if (!captchaState.config || mathExpired) fetchCaptcha(protectedForm);
    }
    window.setTimeout(() => qs(".close", modal)?.focus(), 30);
  }

  function closeModal(modal) {
    if (!modal) return;
    const focusTarget = lastFocusedElement instanceof HTMLElement ? lastFocusedElement : menuButton;
    focusTarget?.focus({ preventScroll: true });
    modal.classList.remove("open");
    modal.setAttribute("inert", "");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("modal-open");
    lastFocusedElement = null;
    if (modal.id === "contact-modal" && location.hash === "#contact") {
      history.replaceState(null, "", `${location.pathname}${location.search}`);
    }
  }

  function trapModalFocus(event) {
    const modal = qs(".modal.open");
    if (!modal) return;
    const focusable = qsa('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', modal)
      .filter((element) => !element.hidden && element.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  async function submitJson(url, data, notice, button, fallback, onSuccess) {
    if (!notice || !button) return;
    notice.className = "notice";
    notice.textContent = "Sending…";
    button.disabled = true;
    try {
      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(data),
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.success) {
        throw new Error(payload?.message || "The request could not be completed.");
      }
      notice.className = "notice ok";
      notice.textContent = payload.message || fallback;
      onSuccess?.();
    } catch (error) {
      notice.className = "notice error";
      notice.textContent = error instanceof Error ? error.message : "The request could not be completed.";
    } finally {
      button.disabled = false;
    }
  }
})();
