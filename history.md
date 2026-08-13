# HelloWrandell Change History

This file records the production changes made to the public portfolio and private CMS. It intentionally excludes passwords, tokens, encryption material, and other secrets.

## 2026-07-27 — Responsive portfolio and CMS modernization

### Public portfolio experience

- Rebuilt the hero as a premium charcoal, ivory, and copper composition with animated ambient light, portrait movement, monogram motion, highlight sweep, and supporting capability badges.
- Removed the former stacked square portrait frames and replaced them with a cleaner portrait treatment.
- Made the capability badges (`01 Support`, `02 Systems`, and `03 Cloud`) move more smoothly and increased their distance from the portrait.
- Reworked the mobile hamburger control into a clear three-line animated button.
- Replaced the oversized mobile navigation with a compact card-based drawer.
- Centered the final Contact navigation tile and kept the navigation order consistent.
- Corrected the page sequence so Experience is rendered before Projects.
- Added animated navigation transitions, destination feedback, focus management, and fixed-header scroll offsets.
- Removed the manual motion preference button and kept the requested visual motion active.
- Added a custom accessible opportunity selector instead of relying on the browser’s dated native dropdown presentation.
- Shortened long phone and tablet pages by showing focused previews with “View all” dialogs for About, Experience, Projects, Skills, Certifications, and Approach.
- Moved the public contact form into a focused premium dialog.
- Restored the footer monogram and aligned footer navigation with the primary navigation sequence.

### Contact, Resume, and email delivery

- Added automatic owner notification for new contact messages.
- Added a branded HTML thank-you email for the sender.
- Added automatic Resume attachment delivery after a successful contact submission.
- Retained the separate verified-email Resume request workflow with expiring one-time verification links.
- Improved SMTP error logging and surfaced delivery health in CMS Settings & security.
- Added validation for complete 16-character Google App Passwords.
- Preserved contact messages in the private CMS even when email delivery is temporarily unavailable.

### Content and certificates

- Made certification records editable from the existing CMS collections workflow.
- Added support for issuer, title, issue year, featured state, verification URL, badge image, and badge alternative text.
- Changed public certificate rendering to replace fallback content from one CMS source, preventing duplicate certificates.
- Added duplicate-name and issuer validation in the CMS so the same certification cannot be published twice.
- Confirmed that the current three certificate records are unique.

### CMS behavior and security

- Corrected configured-CMS routing so unauthenticated visitors go to Login rather than Setup.
- Restricted Setup to genuine first-run installations and redirected configured installations back to Login.
- Preserved administrator session protection, CSRF validation, step-up password checks, rate limiting, audit logging, secure upload validation, and private operational logs.
- Completed the mobile CMS redesign with a compact sticky toolbar, an 18-rem off-canvas drawer, a visible scrim and close control, scroll locking, focus containment, responsive record cards, touch-friendly forms, and sticky mobile save actions.
- Added configurable CAPTCHA settings with a session-bound, single-use, five-minute math challenge as the default and optional Google reCAPTCHA v2 or v3 using an encrypted secret.
- CAPTCHA challenges now load only when a protected dialog opens, renew after every submission attempt, and recover from transient provider-loading failures.
- Added tokenized administrator password recovery by email using 256-bit random tokens, SHA-256 hashes at rest, 30-minute expiry, one-time consumption, CSRF protection, non-enumerating responses, refresh-safe redirects, and delivery-aware link replacement.
- Password changes and recovery now revoke every older CMS session through a password-bound session fingerprint and clear account login throttles after a successful reset.
- Made request-rate checks concurrency-safe and added IP-wide contact and resume delivery limits in addition to address-specific limits.
- Removed an unsupported Permissions Policy directive that caused the browser console warning shown during the original audit.

### Responsive validation

- Tested the public interface at 390 px phone, 768 px tablet, and 1440 px desktop widths.
- Verified that the navigation remains available after scrolling.
- Verified Experience and Projects destination order and focus behavior.
- Verified compact-content and contact dialogs, custom selector behavior, animation activity, and footer logo rendering.
- Validated PHP syntax across 26 files, JavaScript syntax, CSS structure, live CMS redirects, content and CAPTCHA API health, password-recovery redirect behavior, SMTP delivery, and browser console output.

### Configuration note

- Gmail SMTP is configured through the encrypted local settings store with TLS on port 587. The supplied 16-character App Password passed a live delivery test to the configured notification recipient.
- The built-in math challenge remains the default for local use. Google reCAPTCHA can be enabled later from **CMS → Settings & security → Human verification** without editing source files.

### Final implementation and validation update

- Added password-hash session fingerprints so all older CMS sessions are rejected after a password change or password reset; reset completion also clears the account login throttle.
- Made CAPTCHA challenges lazy-load when Contact or Resume is opened, clear stale Google widgets on refresh, and refresh every consumed challenge after an attempted submission.
- Removed the unsupported browsing-topics Permissions-Policy directive that caused the browser console warning.
- Moved Experience before Projects in the source document, pushed desktop capability badges outward from the portrait, and replaced the CAPTCHA loading mojibake with a clean loading message.
- Completed the compact mobile CMS shell with a narrow off-canvas drawer, scrim, focus restoration/trapping, inert and ARIA state, body scroll lock, compact forms/cards/tables/action bars, and readable dashboard status text.
- Bumped public and CMS asset cache versions so existing visitors receive the responsive, motion, CAPTCHA, and CMS fixes.
- Verified PHP and JavaScript syntax, route redirects, security headers, CAPTCHA rejection behavior, no-CAPTCHA API blocking, responsive navigation geometry, no horizontal overflow, and zero browser console errors at phone, tablet, and desktop widths.

Security note: because the SMTP App Password was pasted into this chat, rotate it in Google after this session and replace it in CMS Settings & security.

### 2026-07-27 visual refinement follow-up

- Reduced navigation icon-to-label spacing and kept the centered Contact tile balanced.
- Reworked mobile detail dialogs into centered, shorter cards with compact headers, readable timelines, and better content spacing.
- Removed the decorative circular hero rings and stopped the portrait from zooming during scroll.
- Added a symmetric bottom fade/blur so the hero handoff feels smoother in either scroll direction.
- Ensured CMS login and recovery screens show the HelloWrandell monogram with the current cache-busted stylesheet.
- Kept live preview behavior for project and certification image uploads and bumped the asset cache to v2.9.
- Follow-up audit restored the mobile Projects “Show more” action so compact previews still expose the complete project list through the focused details flow.
- Applied the requested dark full-hero composition, removed the Support/Systems/Cloud profile badges, renamed all public resume actions to My Resume, and added a hover/focus disclosure explaining verified-email sharing.
- Added a seven-second branded HelloWrandell transition for CMS login and logout submissions with the monogram and progress indicator.
