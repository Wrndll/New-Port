<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function admin_icon(string $name, int $size = 18): void
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'content' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'collections' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/><circle cx="8" cy="6" r="1"/><circle cx="16" cy="12" r="1"/><circle cx="10" cy="18" r="1"/>',
        'projects' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 15 5-5 4 4 3-3 6 6"/><circle cx="16" cy="9" r="1.5"/>',
        'messages' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/><path d="M8 9h8M8 13h5"/>',
        'resume' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 8.94 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3.09 14H3v-4h.09A1.7 1.7 0 0 0 4.6 8.94a1.7 1.7 0 0 0-.34-1.88L4.2 7l2.83-2.83.06.06A1.7 1.7 0 0 0 8.97 4.6 1.7 1.7 0 0 0 10 3.09V3h4v.09a1.7 1.7 0 0 0 1.03 1.52 1.7 1.7 0 0 0 1.88-.34l.06-.06L19.8 7l-.06.06a1.7 1.7 0 0 0-.34 1.88A1.7 1.7 0 0 0 20.91 10H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z"/>',
        'external' => '<path d="M15 3h6v6M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3Z"/><path d="m9 12 2 2 4-4"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'activity' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];
    $body = $paths[$name] ?? $paths['activity'];
    ?><svg aria-hidden="true" width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $body ?></svg><?php
}

function admin_header(string $title, string $active = ''): void
{
    admin_security_headers();
    $admin = require_admin();
    $flash = take_flash();
    $session = admin_session_summary();
    $navigation = [
        'dashboard' => ['Dashboard', admin_path()],
        'content' => ['Profile & content', admin_path('content.php')],
        'collections' => ['Experience, skills & certificates', admin_path('collections.php')],
        'projects' => ['Projects', admin_path('projects.php')],
        'messages' => ['Messages', admin_path('messages.php')],
        'resume' => ['Resume requests', admin_path('resume-requests.php')],
        'settings' => ['Settings & security', admin_path('settings.php')],
    ];
    $descriptions = [
        'dashboard' => 'Monitor portfolio activity, incoming opportunities, and account security.',
        'content' => 'Shape the public story, profile details, and portfolio positioning.',
        'collections' => 'Maintain experience, skills, certifications, and supporting collections.',
        'projects' => 'Build visual case files, control publishing, and manage project media.',
        'messages' => 'Review, organize, and safely respond to portfolio enquiries.',
        'resume' => 'Track verified resume requests without exposing a public download.',
        'settings' => 'Manage administrator access, trusted URLs, mail delivery, and audit history.',
    ];
    $messageCount = (int) db()->query("SELECT COUNT(*) FROM messages WHERE status = 'new'")->fetchColumn();
    $resumeCount = (int) db()->query("SELECT COUNT(*) FROM resume_requests WHERE status IN ('pending','verified','failed')")->fetchColumn();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="color-scheme" content="light">
  <title><?= e($title) ?> · HelloWrandell CMS</title>
  <link rel="icon" href="<?= e(app_url('images/brand/favicon-v24-32.png')) ?>" sizes="32x32" type="image/png">
  <link rel="stylesheet" href="<?= e(app_url(admin_path('assets/admin.css?v=2.10.0'))) ?>">
</head>
<body>
  <a class="skip" href="#main">Skip to content</a>
  <button class="mobile-scrim" type="button" data-menu-close aria-label="Close CMS navigation" aria-hidden="true" tabindex="-1"></button>
  <div class="admin-shell">
    <aside id="cms-navigation" class="sidebar" data-menu-panel aria-label="CMS sidebar">
      <div class="sidebar-head">
        <a class="brand brand-with-mark" href="<?= e(app_url(admin_path(''))) ?>">
          <span class="adaptive-logo" aria-hidden="true"></span>
          <span><strong>HelloWrandell</strong><small>Secure CMS</small></span>
        </a>
        <button class="sidebar-close" type="button" data-menu-close aria-label="Close CMS navigation">
          <span aria-hidden="true"></span>
          <span aria-hidden="true"></span>
        </button>
      </div>

      <p class="nav-label">Workspace</p>
      <nav aria-label="CMS navigation">
        <?php foreach ($navigation as $key => [$label, $path]): ?>
          <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e(app_url($path)) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
            <span class="nav-icon"><?php admin_icon($key); ?></span>
            <span><?= e($label) ?></span>
            <?php if ($key === 'messages' && $messageCount > 0): ?><em><?= $messageCount ?></em><?php endif; ?>
            <?php if ($key === 'resume' && $resumeCount > 0): ?><em><?= $resumeCount ?></em><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <section class="security-mini" aria-label="Session security">
        <div class="security-mini__head">
          <span><?php admin_icon('shield', 16); ?> Protected session</span>
          <i aria-hidden="true"></i>
        </div>
        <p>Auto-lock in <?= (int) $session['idle_minutes'] ?> minutes of inactivity.</p>
        <span class="transport-state"><?= $session['https'] ? 'HTTPS transport active' : 'Local HTTP · use HTTPS when public' ?></span>
      </section>

      <div class="sidebar-foot">
        <div class="admin-identity">
          <span class="admin-avatar"><?= e(strtoupper(substr((string) $admin['email'], 0, 1))) ?></span>
          <span><strong>Administrator</strong><small><?= e($admin['email']) ?></small></span>
        </div>
        <div class="sidebar-actions">
          <a href="<?= e(app_url('')) ?>" target="_blank" rel="noopener"><?php admin_icon('external', 15); ?> View portfolio</a>
          <form method="post" action="<?= e(app_url(admin_path('logout.php'))) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="link-button" type="submit">Sign out</button>
          </form>
        </div>
      </div>
    </aside>

    <div class="admin-workspace">
      <header class="topbar">
        <button class="menu-button" type="button" data-menu aria-expanded="false" aria-controls="cms-navigation" aria-label="Open CMS navigation">
          <span class="menu-button__lines" aria-hidden="true"><i></i><i></i><i></i></span>
        </button>
        <div class="topbar-context"><span>Portfolio control center</span><strong><?= e($title) ?></strong></div>
        <div class="topbar-actions">
          <span class="session-pill"><?php admin_icon('lock', 14); ?> Session protected</span>
          <a class="topbar-link" href="<?= e(app_url('')) ?>" target="_blank" rel="noopener">Open portfolio <?php admin_icon('external', 15); ?></a>
        </div>
      </header>

      <main id="main" class="main">
        <header class="page-head">
          <div>
            <p class="eyebrow">Portfolio control center</p>
            <h1><?= e($title) ?></h1>
            <p><?= e($descriptions[$active] ?? 'Manage your private portfolio workspace.') ?></p>
          </div>
          <span class="page-status"><i aria-hidden="true"></i> System ready</span>
        </header>
        <?php if ($flash): ?>
          <div class="notice <?= e($flash['type'] ?? '') ?>" role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>">
            <?= e($flash['message'] ?? '') ?>
          </div>
        <?php endif; ?>
<?php
}

function admin_footer(): void
{
    ?>
      </main>
    </div>
  </div>

  <dialog class="secure-dialog" data-secure-dialog aria-labelledby="secure-dialog-title">
    <form method="dialog" class="secure-dialog__panel">
      <div class="secure-dialog__icon"><?php admin_icon('shield', 22); ?></div>
      <p class="eyebrow">Protected action</p>
      <h2 id="secure-dialog-title">Confirm this permanent change</h2>
      <p class="muted" data-secure-message>This action requires your current administrator password.</p>
      <label>Administrator password
        <input type="password" autocomplete="current-password" data-secure-password>
      </label>
      <p class="form-error" data-secure-error role="alert"></p>
      <div class="actions">
        <button class="secondary" value="cancel" type="submit">Cancel</button>
        <button class="danger-button" value="confirm" type="submit">Confirm permanent action</button>
      </div>
    </form>
  </dialog>

  <div class="save-indicator" data-save-indicator role="status" aria-live="polite">Unsaved changes</div>
  <script src="<?= e(app_url(admin_path('assets/admin.js?v=2.10.0'))) ?>" defer></script>
</body>
</html>
<?php
}

function csrf_field(): void
{
    ?><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?php
}

