<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/admin-layout.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $action = clean_text($_POST['action'] ?? '');
    if ($id !== false && $id !== null) {
        if ($action === 'delete') {
            try {
                require_current_admin_password($_POST['confirm_password'] ?? '');
                $query = db()->prepare('DELETE FROM messages WHERE id = ?');
                $query->execute([(int) $id]);
                audit_log('deleted', 'message', (string) $id);
                set_flash('success', 'Message permanently deleted.');
            } catch (Throwable $exception) {
                set_flash('error', $exception->getMessage());
            }
            redirect_to(admin_path('messages.php'));
        }
        $statuses = ['new', 'read', 'archived'];
        if (in_array($action, $statuses, true)) {
            $query = db()->prepare('UPDATE messages SET status = ? WHERE id = ?');
            $query->execute([$action, (int) $id]);
            audit_log('status_changed', 'message', (string) $id, ['status' => $action]);
            set_flash('success', 'Message status updated.');
            redirect_to('admin/messages.php?id=' . (int) $id);
        }
    }
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$message = null;
if ($id !== false && $id !== null) {
    $query = db()->prepare('SELECT * FROM messages WHERE id = ?');
    $query->execute([(int) $id]);
    $message = $query->fetch() ?: null;
    if (is_array($message) && $message['status'] === 'new') {
        $read = db()->prepare('UPDATE messages SET status = "read" WHERE id = ?');
        $read->execute([(int) $id]);
        $message['status'] = 'read';
    }
}
$status = clean_text($_GET['status'] ?? '');
$allowedStatuses = ['new', 'read', 'archived'];
$search = clean_text($_GET['q'] ?? '');
if (text_length($search) > 100) {
    $search = '';
}
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false ? 1 : max(1, (int) $page);
$perPage = 15;
$where = [];
$parameters = [];
if (in_array($status, $allowedStatuses, true)) {
    $where[] = 'status = ?';
    $parameters[] = $status;
}
if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR company LIKE ? OR opportunity_type LIKE ? OR message LIKE ?)';
    $needle = '%' . $search . '%';
    array_push($parameters, $needle, $needle, $needle, $needle, $needle);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countQuery = db()->prepare('SELECT COUNT(*) FROM messages' . $whereSql);
$countQuery->execute($parameters);
$totalMessages = (int) $countQuery->fetchColumn();
$totalPages = max(1, (int) ceil($totalMessages / $perPage));
$page = min($page, $totalPages);
$listQuery = db()->prepare(
    'SELECT * FROM messages' . $whereSql . ' ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$parameterIndex = 1;
foreach ($parameters as $parameter) {
    $listQuery->bindValue($parameterIndex, $parameter, PDO::PARAM_STR);
    $parameterIndex += 1;
}
$listQuery->bindValue($parameterIndex, $perPage, PDO::PARAM_INT);
$listQuery->bindValue($parameterIndex + 1, ($page - 1) * $perPage, PDO::PARAM_INT);
$listQuery->execute();
$messages = $listQuery->fetchAll();
$querySuffix = ($status !== '' ? '&status=' . rawurlencode($status) : '')
    . ($search !== '' ? '&q=' . rawurlencode($search) : '');

admin_header('Messages', 'messages');
?>
<section class="list-toolbar">
  <nav class="tabs" aria-label="Message status">
    <a class="<?= $status === '' ? 'active' : '' ?>" href="<?= e(app_url(admin_path('messages.php'))) ?>">All</a>
    <?php foreach ($allowedStatuses as $filter): ?><a class="<?= $status === $filter ? 'active' : '' ?>" href="<?= e(app_url('admin/messages.php?status=' . $filter)) ?>"><?= e(ucfirst($filter)) ?></a><?php endforeach; ?>
  </nav>
  <form class="search-form" method="get" role="search">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <label class="sr-only" for="message-search">Search messages</label>
    <input id="message-search" type="search" maxlength="100" name="q" value="<?= e($search) ?>" placeholder="Search sender, company, or message">
    <button class="secondary" type="submit">Search</button>
  </form>
</section>
<p class="results-summary"><?= $totalMessages ?> message<?= $totalMessages === 1 ? '' : 's' ?> in this view. Records remain private until explicitly deleted.</p>

<?php if ($message): ?>
<section class="card detail-card">
  <div class="card-head"><h2><?= e($message['name']) ?></h2><a href="<?= e(app_url(admin_path('messages.php'))) ?>">Close detail</a></div>
  <dl class="detail-grid">
    <dt>Email</dt><dd><a class="text-link" href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a></dd>
    <dt>Company</dt><dd><?= e($message['company'] ?: 'Not provided') ?></dd>
    <dt>Opportunity</dt><dd><?= e($message['opportunity_type']) ?></dd>
    <dt>Received</dt><dd><?= e($message['created_at']) ?></dd>
    <dt>Notification</dt><dd><?= (int) $message['notification_sent'] === 1 ? 'Email notification sent' : 'Stored in CMS only' ?></dd>
    <dt>Message</dt><dd><?= e($message['message']) ?></dd>
  </dl>
  <div class="actions action-row">
    <a class="primary" href="mailto:<?= e($message['email']) ?>?subject=Re:%20<?= e(rawurlencode($message['opportunity_type'])) ?>">Reply by email</a>
    <?php foreach ($allowedStatuses as $nextStatus): if ($nextStatus !== $message['status']): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $message['id'] ?>"><button class="secondary" name="action" value="<?= e($nextStatus) ?>" type="submit">Mark <?= e($nextStatus) ?></button></form><?php endif; endforeach; ?>
    <form method="post" data-secure-confirm="Permanently delete this message? This cannot be undone."><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $message['id'] ?>"><button class="danger-button" name="action" value="delete" type="submit">Delete permanently</button></form>
  </div>
</section>
<?php endif; ?>

<section class="card table-wrap">
  <table class="data-table">
    <thead><tr><th>Sender</th><th>Opportunity</th><th>Received</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$messages): ?><tr><td colspan="5" class="empty">No messages in this view.</td></tr><?php endif; ?>
      <?php foreach ($messages as $item): ?>
        <tr>
          <td><strong><?= e($item['name']) ?></strong><br><span><?= e($item['email']) ?></span></td>
          <td><?= e($item['opportunity_type']) ?><br><span><?= e($item['company'] ?: 'No company') ?></span></td>
          <td><?= e($item['created_at']) ?></td>
          <td><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td>
          <td><a class="secondary" href="<?= e(app_url('admin/messages.php?id=' . $item['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Message pages">
    <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(app_url('admin/messages.php?page=' . max(1, $page - 1) . $querySuffix)) ?>" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>← Previous</a>
    <span>Page <?= $page ?> of <?= $totalPages ?></span>
    <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(app_url('admin/messages.php?page=' . min($totalPages, $page + 1) . $querySuffix)) ?>" <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>>Next →</a>
  </nav>
<?php endif; ?>
<?php admin_footer(); ?>
