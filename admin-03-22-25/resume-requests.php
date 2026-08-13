<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/admin-layout.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $action = clean_text($_POST['action'] ?? '');
    if ($id !== false && $id !== null && $action === 'delete') {
        try {
            require_current_admin_password($_POST['confirm_password'] ?? '');
            $query = db()->prepare('DELETE FROM resume_requests WHERE id = ?');
            $query->execute([(int) $id]);
            audit_log('deleted', 'resume_request', (string) $id);
            set_flash('success', 'Resume request permanently deleted.');
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage());
        }
        redirect_to(admin_path('resume-requests.php'));
    }
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$request = null;
if ($id !== false && $id !== null) {
    $query = db()->prepare('SELECT * FROM resume_requests WHERE id = ?');
    $query->execute([(int) $id]);
    $request = $query->fetch() ?: null;
}
$status = clean_text($_GET['status'] ?? '');
$allowedStatuses = ['pending', 'verified', 'sent', 'failed'];
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
    $where[] = '(name LIKE ? OR email LIKE ? OR company LIKE ? OR purpose LIKE ?)';
    $needle = '%' . $search . '%';
    array_push($parameters, $needle, $needle, $needle, $needle);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countQuery = db()->prepare('SELECT COUNT(*) FROM resume_requests' . $whereSql);
$countQuery->execute($parameters);
$totalRequests = (int) $countQuery->fetchColumn();
$totalPages = max(1, (int) ceil($totalRequests / $perPage));
$page = min($page, $totalPages);
$listQuery = db()->prepare(
    'SELECT * FROM resume_requests' . $whereSql . ' ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$parameterIndex = 1;
foreach ($parameters as $parameter) {
    $listQuery->bindValue($parameterIndex, $parameter, PDO::PARAM_STR);
    $parameterIndex += 1;
}
$listQuery->bindValue($parameterIndex, $perPage, PDO::PARAM_INT);
$listQuery->bindValue($parameterIndex + 1, ($page - 1) * $perPage, PDO::PARAM_INT);
$listQuery->execute();
$requests = $listQuery->fetchAll();
$querySuffix = ($status !== '' ? '&status=' . rawurlencode($status) : '')
    . ($search !== '' ? '&q=' . rawurlencode($search) : '');

admin_header('Resume requests', 'resume');
?>
<section class="list-toolbar">
  <nav class="tabs" aria-label="Resume request status">
    <a class="<?= $status === '' ? 'active' : '' ?>" href="<?= e(app_url(admin_path('resume-requests.php'))) ?>">All</a>
    <?php foreach ($allowedStatuses as $filter): ?><a class="<?= $status === $filter ? 'active' : '' ?>" href="<?= e(app_url('admin/resume-requests.php?status=' . $filter)) ?>"><?= e(ucfirst($filter)) ?></a><?php endforeach; ?>
  </nav>
  <form class="search-form" method="get" role="search">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <label class="sr-only" for="resume-search">Search resume requests</label>
    <input id="resume-search" type="search" maxlength="100" name="q" value="<?= e($search) ?>" placeholder="Search requester, company, or purpose">
    <button class="secondary" type="submit">Search</button>
  </form>
</section>
<p class="results-summary"><?= $totalRequests ?> request<?= $totalRequests === 1 ? '' : 's' ?> in this view. The resume remains outside the public web directory.</p>

<?php if ($request): ?>
<section class="card detail-card">
  <div class="card-head"><h2><?= e($request['name']) ?></h2><a href="<?= e(app_url(admin_path('resume-requests.php'))) ?>">Close detail</a></div>
  <dl class="detail-grid">
    <dt>Email</dt><dd><a class="text-link" href="mailto:<?= e($request['email']) ?>"><?= e($request['email']) ?></a></dd>
    <dt>Company</dt><dd><?= e($request['company'] ?: 'Not provided') ?></dd>
    <dt>Status</dt><dd><span class="badge <?= e($request['status']) ?>"><?= e($request['status']) ?></span></dd>
    <dt>Requested</dt><dd><?= e($request['created_at']) ?></dd>
    <dt>Link expiry</dt><dd><?= e($request['expires_at']) ?></dd>
    <dt>Verified</dt><dd><?= e($request['verified_at'] ?: 'Not yet') ?></dd>
    <dt>Sent</dt><dd><?= e($request['sent_at'] ?: 'Not yet') ?></dd>
    <dt>Purpose</dt><dd><?= e($request['purpose']) ?></dd>
  </dl>
  <div class="actions action-row">
    <a class="primary" href="mailto:<?= e($request['email']) ?>">Contact requester</a>
    <form method="post" data-secure-confirm="Permanently delete this resume request record?"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>"><button class="danger-button" name="action" value="delete" type="submit">Delete permanently</button></form>
  </div>
</section>
<?php endif; ?>

<section class="card table-wrap">
  <table class="data-table">
    <thead><tr><th>Requester</th><th>Company</th><th>Requested</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php if (!$requests): ?><tr><td colspan="5" class="empty">No resume requests in this view.</td></tr><?php endif; ?>
      <?php foreach ($requests as $item): ?>
        <tr>
          <td><strong><?= e($item['name']) ?></strong><br><span><?= e($item['email']) ?></span></td>
          <td><?= e($item['company'] ?: 'Not provided') ?></td>
          <td><?= e($item['created_at']) ?></td>
          <td><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td>
          <td><a class="secondary" href="<?= e(app_url('admin/resume-requests.php?id=' . $item['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Resume request pages">
    <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(app_url('admin/resume-requests.php?page=' . max(1, $page - 1) . $querySuffix)) ?>" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>← Previous</a>
    <span>Page <?= $page ?> of <?= $totalPages ?></span>
    <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(app_url('admin/resume-requests.php?page=' . min($totalPages, $page + 1) . $querySuffix)) ?>" <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>>Next →</a>
  </nav>
<?php endif; ?>
<?php admin_footer(); ?>
