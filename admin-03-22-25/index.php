<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';

require_cms_configuration();
$admin = current_admin();
if ($admin === null) {
    redirect_to(admin_path('login.php'));
}

require_once __DIR__ . '/../private/admin-layout.php';

$counts = [
    'projects' => (int) db()->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
    'published' => (int) db()->query('SELECT COUNT(*) FROM projects WHERE published = 1')->fetchColumn(),
    'messages' => (int) db()->query("SELECT COUNT(*) FROM messages WHERE status = 'new'")->fetchColumn(),
    'resume' => (int) db()->query("SELECT COUNT(*) FROM resume_requests WHERE status IN ('pending','verified','failed')")->fetchColumn(),
];
$recentMessages = db()->query(
    'SELECT id, name, opportunity_type, status, created_at
     FROM messages ORDER BY created_at DESC LIMIT 5'
)->fetchAll();
$recentRequests = db()->query(
    'SELECT id, name, email, status, created_at
     FROM resume_requests ORDER BY created_at DESC LIMIT 5'
)->fetchAll();
$recentActivity = db()->query(
    'SELECT action_name, entity_type, entity_id, created_at
     FROM audit_logs ORDER BY created_at DESC LIMIT 6'
)->fetchAll();
$failedLogins = (int) db()->query(
    'SELECT COUNT(*) FROM login_attempts
     WHERE successful = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
)->fetchColumn();
$lastLoginQuery = db()->prepare('SELECT last_login_at FROM admins WHERE id = ?');
$lastLoginQuery->execute([(int) $admin['id']]);
$lastLoginAt = $lastLoginQuery->fetchColumn();
$session = admin_session_summary();

admin_header('Dashboard', 'dashboard');
?>
<section class="command-hero">
  <div>
    <span class="command-kicker"><?php admin_icon('activity', 16); ?> Live portfolio operations</span>
    <h2>Your content and enquiries, in one protected workspace.</h2>
    <p>Review what needs attention, update a case file, or check the latest security activity.</p>
  </div>
  <div class="quick-actions" aria-label="Quick actions">
    <a class="primary" href="<?= e(app_url(admin_path('projects.php?new=1'))) ?>"><?php admin_icon('plus', 17); ?> New project</a>
    <a class="secondary" href="<?= e(app_url(admin_path('content.php'))) ?>">Edit profile <?php admin_icon('arrow', 16); ?></a>
  </div>
</section>

<section class="stats" aria-label="CMS summary">
  <a class="stat" href="<?= e(app_url(admin_path('projects.php'))) ?>">
    <span class="stat-icon"><?php admin_icon('projects'); ?></span>
    <span><small>Project library</small><strong><?= $counts['projects'] ?></strong><em><?= $counts['published'] ?> published</em></span>
  </a>
  <a class="stat" href="<?= e(app_url(admin_path('messages.php?status=new'))) ?>">
    <span class="stat-icon"><?php admin_icon('messages'); ?></span>
    <span><small>New messages</small><strong><?= $counts['messages'] ?></strong><em><?= $counts['messages'] > 0 ? 'Needs review' : 'Inbox clear' ?></em></span>
  </a>
  <a class="stat" href="<?= e(app_url(admin_path('resume-requests.php'))) ?>">
    <span class="stat-icon"><?php admin_icon('resume'); ?></span>
    <span><small>Open resume requests</small><strong><?= $counts['resume'] ?></strong><em>Verified email flow</em></span>
  </a>
  <a class="stat stat--security" href="<?= e(app_url(admin_path('settings.php#security'))) ?>">
    <span class="stat-icon"><?php admin_icon('shield'); ?></span>
    <span><small>Security state</small><strong><?= $failedLogins === 0 ? 'Clear' : $failedLogins ?></strong><em><?= $failedLogins === 0 ? 'No failed logins today' : 'Failed attempts in 24h' ?></em></span>
  </a>
</section>

<div class="dashboard-grid">
  <section class="card dashboard-card dashboard-card--wide">
    <div class="card-head">
      <div><span class="card-kicker">Inbox</span><h2>Recent messages</h2></div>
      <a href="<?= e(app_url(admin_path('messages.php'))) ?>">View all <?php admin_icon('arrow', 15); ?></a>
    </div>
    <?php if (!$recentMessages): ?>
      <div class="empty-state"><?php admin_icon('messages', 24); ?><strong>No messages yet</strong><span>New portfolio enquiries will appear here.</span></div>
    <?php else: ?>
      <div class="item-list">
        <?php foreach ($recentMessages as $message): ?>
          <a href="<?= e(app_url('admin/messages.php?id=' . $message['id'])) ?>">
            <span class="item-avatar"><?= e(strtoupper(substr((string) $message['name'], 0, 1))) ?></span>
            <span class="item-main"><strong><?= e($message['name']) ?></strong><small><?= e($message['opportunity_type']) ?> · <?= e($message['created_at']) ?></small></span>
            <em class="badge <?= e($message['status']) ?>"><?= e($message['status']) ?></em>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card dashboard-card">
    <div class="card-head">
      <div><span class="card-kicker">Delivery</span><h2>Resume requests</h2></div>
      <a href="<?= e(app_url(admin_path('resume-requests.php'))) ?>">View all</a>
    </div>
    <?php if (!$recentRequests): ?>
      <div class="empty-state"><?php admin_icon('resume', 24); ?><strong>No requests yet</strong><span>Verified requests will be tracked here.</span></div>
    <?php else: ?>
      <div class="item-list compact">
        <?php foreach ($recentRequests as $request): ?>
          <a href="<?= e(app_url('admin/resume-requests.php?id=' . $request['id'])) ?>">
            <span class="item-main"><strong><?= e($request['name']) ?></strong><small><?= e($request['created_at']) ?></small></span>
            <em class="badge <?= e($request['status']) ?>"><?= e($request['status']) ?></em>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card dashboard-card">
    <div class="card-head">
      <div><span class="card-kicker">Audit trail</span><h2>Recent activity</h2></div>
      <a href="<?= e(app_url(admin_path('settings.php#activity'))) ?>">Open log</a>
    </div>
    <?php if (!$recentActivity): ?>
      <div class="empty-state"><?php admin_icon('activity', 24); ?><strong>No changes recorded</strong><span>CMS updates will be logged here.</span></div>
    <?php else: ?>
      <ol class="activity-list">
        <?php foreach ($recentActivity as $entry): ?>
          <li><i aria-hidden="true"></i><span><strong><?= e(ucfirst(str_replace('_', ' ', $entry['action_name']))) ?></strong><small><?= e($entry['entity_type']) ?> <?= e($entry['entity_id']) ?> · <?= e($entry['created_at']) ?></small></span></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <section class="security-panel" id="security">
    <div class="security-panel__icon"><?php admin_icon('shield', 24); ?></div>
    <span class="card-kicker">Administrator security</span>
    <h2>Protected by layered controls</h2>
    <ul>
      <li><span>Session</span><strong><?= (int) $session['idle_minutes'] ?> min idle lock</strong></li>
      <li><span>Transport</span><strong><?= $session['https'] ? 'HTTPS active' : 'Local HTTP mode' ?></strong></li>
      <li><span>Failed logins</span><strong><?= $failedLogins ?> in 24 hours</strong></li>
      <li><span>Last sign-in</span><strong><?= e(is_string($lastLoginAt) ? $lastLoginAt : 'First session') ?></strong></li>
    </ul>
    <a href="<?= e(app_url(admin_path('settings.php#security'))) ?>">Review security settings <?php admin_icon('arrow', 16); ?></a>
  </section>
</div>
<?php admin_footer(); ?>
