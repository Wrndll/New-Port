<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/admin-layout.php';

require_admin();
$definitions = [
    'experiences' => [
        'label' => 'Experience',
        'fields' => ['role', 'organization', 'location', 'dates'],
        'list' => 'responsibilities',
        'title' => 'role',
    ],
    'skillGroups' => [
        'label' => 'Skill group',
        'fields' => ['name'],
        'list' => 'skills',
        'title' => 'name',
    ],
    'certifications' => [
        'label' => 'Certification',
        'fields' => ['name', 'issuer', 'issueYear', 'credentialId', 'verificationUrl'],
        'list' => null,
        'title' => 'name',
    ],
];
$type = clean_text($_GET['type'] ?? $_POST['type'] ?? 'experiences');
if (!isset($definitions[$type])) {
    $type = 'experiences';
}
$definition = $definitions[$type];
$items = content_value($type, []);
if (!is_array($items)) {
    $items = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = clean_text($_POST['action'] ?? 'save');
    $index = filter_var($_POST['index'] ?? -1, FILTER_VALIDATE_INT);
    $index = $index === false ? -1 : (int) $index;

    if ($action === 'delete' && isset($items[$index])) {
        try {
            require_current_admin_password($_POST['confirm_password'] ?? '');
            $removed = $items[$index];
            if ($type === 'certifications' && is_string($removed['badgeImage'] ?? null)) {
                delete_uploaded_asset($removed['badgeImage'], 'credentials');
            }
            array_splice($items, $index, 1);
            save_content_value($type, array_values($items));
            audit_log('deleted', $type, (string) $index, ['title' => $removed[$definition['title']] ?? '']);
            set_flash('success', $definition['label'] . ' deleted.');
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage());
        }
    } elseif (in_array($action, ['up', 'down'], true) && isset($items[$index])) {
        $target = $action === 'up' ? $index - 1 : $index + 1;
        if (isset($items[$target])) {
            [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
            save_content_value($type, array_values($items));
            audit_log('reordered', $type);
            set_flash('success', 'Display order updated.');
        }
    } else {
        $item = [];
        foreach ($definition['fields'] as $field) {
            $item[$field] = clean_text($_POST[$field] ?? '');
        }
        if ($definition['list'] !== null) {
            $item[$definition['list']] = lines_from_text($_POST[$definition['list']] ?? '');
        }
        if ($type === 'certifications') {
            $previous = $index >= 0 && isset($items[$index]) ? $items[$index] : [];
            $item['badgeImage'] = is_string($previous['badgeImage'] ?? null) ? $previous['badgeImage'] : '';
            $item['badgeAlt'] = clean_text($_POST['badgeAlt'] ?? '');
            $item['featured'] = isset($_POST['featured']);
            if ($item['verificationUrl'] !== '') {
                $scheme = strtolower((string) parse_url($item['verificationUrl'], PHP_URL_SCHEME));
                if (filter_var($item['verificationUrl'], FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                    set_flash('error', 'Verification links must be valid HTTP or HTTPS URLs.');
                    redirect_to(admin_path('collections.php?type=' . rawurlencode($type) . ($index >= 0 ? '&edit=' . $index : '')));
                }
            }
            $normalizedName = mb_strtolower(trim($item['name']));
            $normalizedIssuer = mb_strtolower(trim($item['issuer']));
            foreach ($items as $candidateIndex => $candidate) {
                if ((int) $candidateIndex === $index) {
                    continue;
                }
                $candidateName = mb_strtolower(trim((string) ($candidate['name'] ?? '')));
                $candidateIssuer = mb_strtolower(trim((string) ($candidate['issuer'] ?? '')));
                if ($normalizedName !== '' && $normalizedName === $candidateName && $normalizedIssuer === $candidateIssuer) {
                    set_flash('error', 'That certification is already published. Edit the existing record instead.');
                    redirect_to(admin_path('collections.php?type=' . rawurlencode($type) . ($index >= 0 ? '&edit=' . $index : '')));
                }
            }
            if (isset($_POST['remove_badge']) && $item['badgeImage'] !== '') {
                delete_uploaded_asset($item['badgeImage'], 'credentials');
                $item['badgeImage'] = '';
            }
            $upload = $_FILES['badge_image'] ?? null;
            if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newPath = safe_image_upload($upload, 'credentials', 'credential-' . ($item['name'] ?: 'badge'), 1200, 1200);
                if ($item['badgeImage'] !== '') {
                    delete_uploaded_asset($item['badgeImage'], 'credentials');
                }
                $item['badgeImage'] = $newPath;
            }
            if ($item['badgeImage'] !== '' && $item['badgeAlt'] === '') {
                $item['badgeAlt'] = ($item['name'] ?: 'Certification') . ' badge';
            }
        }

        if (($item[$definition['title']] ?? '') === '') {
            set_flash('error', $definition['label'] . ' name or title is required.');
        } elseif ($index >= 0 && isset($items[$index])) {
            $items[$index] = $item;
            save_content_value($type, array_values($items));
            audit_log('updated', $type, (string) $index);
            set_flash('success', $definition['label'] . ' updated.');
        } else {
            $items[] = $item;
            save_content_value($type, array_values($items));
            audit_log('created', $type, (string) (count($items) - 1));
            set_flash('success', $definition['label'] . ' added.');
        }
    }
    redirect_to(admin_path('collections.php?type=' . rawurlencode($type)));
}

$editIndex = filter_var($_GET['edit'] ?? -1, FILTER_VALIDATE_INT);
$editIndex = $editIndex === false ? -1 : (int) $editIndex;
$editing = isset($items[$editIndex]) ? $items[$editIndex] : null;

admin_header('Experience & skills', 'collections');
?>
<p class="lead">Add, edit, reorder, or remove the repeating professional-content sections.</p>
<nav class="tabs" aria-label="Collection type">
  <?php foreach ($definitions as $key => $details): ?>
    <a class="<?= $type === $key ? 'active' : '' ?>" href="<?= e(app_url(admin_path('collections.php?type=' . $key))) ?>"><?= e($details['label']) ?></a>
  <?php endforeach; ?>
</nav>

<div class="split">
  <section class="card">
    <div class="card-head"><h2><?= $editing ? 'Edit' : 'Add' ?> <?= e(strtolower($definition['label'])) ?></h2><?php if ($editing): ?><a href="<?= e(app_url(admin_path('collections.php?type=' . $type))) ?>">Cancel</a><?php endif; ?></div>
    <form method="post" <?= $type === 'certifications' ? 'enctype="multipart/form-data"' : '' ?> class="stack" data-dirty-form>
      <?php csrf_field(); ?>
      <input type="hidden" name="type" value="<?= e($type) ?>">
      <input type="hidden" name="index" value="<?= $editing ? $editIndex : -1 ?>">
      <input type="hidden" name="action" value="save">
      <?php foreach ($definition['fields'] as $field): ?>
        <label><?= e(ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $field) ?? $field)) ?>
          <input <?= $field === $definition['title'] ? 'required' : '' ?> <?= $field === 'verificationUrl' ? 'type="url"' : '' ?> maxlength="500" name="<?= e($field) ?>" value="<?= e($editing[$field] ?? '') ?>">
        </label>
      <?php endforeach; ?>
      <?php if ($definition['list'] !== null): ?>
        <label><?= e(ucfirst($definition['list'])) ?>
          <textarea name="<?= e($definition['list']) ?>" rows="9"><?= e(implode("\n", $editing[$definition['list']] ?? [])) ?></textarea>
          <small>One item per line.</small>
        </label>
      <?php endif; ?>
      <?php if ($type === 'certifications'): ?>
        <div class="credential-editor">
          <div class="credential-preview" data-image-preview>
            <?php if (!empty($editing['badgeImage'])): ?><img src="<?= e(app_url(ltrim((string) $editing['badgeImage'], '/'))) ?>" alt="<?= e($editing['badgeAlt'] ?? '') ?>"><?php else: ?><span>Badge preview</span><?php endif; ?>
          </div>
          <label>Badge image<input type="file" name="badge_image" accept="image/jpeg,image/png,image/webp" data-preview-input><small>JPEG, PNG, or WebP up to 5 MB. Images are resized and converted to WebP when PHP GD is available.</small></label>
          <label>Badge alternative text<input maxlength="220" name="badgeAlt" value="<?= e($editing['badgeAlt'] ?? '') ?>"></label>
          <?php if (!empty($editing['badgeImage'])): ?><label class="choice-row"><input type="checkbox" name="remove_badge" value="1"><span><strong>Remove current badge</strong><small>The file will be deleted after saving.</small></span></label><?php endif; ?>
          <label class="choice-row"><input type="checkbox" name="featured" value="1" <?= !isset($editing['featured']) || $editing['featured'] ? 'checked' : '' ?>><span><strong>Feature near the hero</strong><small>Show this credential in the first-scroll credential rail.</small></span></label>
        </div>
      <?php endif; ?>
      <button class="primary" type="submit"><?= $editing ? 'Save changes' : 'Add item' ?></button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2>Published order</h2><span class="badge"><?= count($items) ?> items</span></div>
    <?php if (!$items): ?><p class="empty">No items in this section.</p><?php endif; ?>
    <?php foreach ($items as $index => $item): ?>
      <article class="list-row <?= $type === 'certifications' ? 'list-row--credential' : '' ?>">
        <?php if ($type === 'certifications'): ?><div class="credential-thumb"><?php if (!empty($item['badgeImage'])): ?><img src="<?= e(app_url(ltrim((string) $item['badgeImage'], '/'))) ?>" alt=""><?php else: ?><span><?= e(strtoupper(substr((string) ($item['issuer'] ?? 'C'), 0, 2))) ?></span><?php endif; ?></div><?php endif; ?>
        <div class="list-row__copy"><strong><?= e($item[$definition['title']] ?? 'Untitled') ?></strong><span><?= e($item['organization'] ?? $item['issuer'] ?? implode(', ', array_slice($item['skills'] ?? [], 0, 3))) ?></span><?php if ($type === 'certifications' && !empty($item['featured'])): ?><small class="featured-label">Featured near hero</small><?php endif; ?></div>
        <div class="actions">
          <a class="secondary" href="<?= e(app_url(admin_path('collections.php?type=' . $type . '&edit=' . $index))) ?>">Edit</a>
          <form method="post"><?php csrf_field(); ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="index" value="<?= $index ?>"><button class="secondary" name="action" value="up" type="submit" <?= $index === 0 ? 'disabled' : '' ?>>↑</button><button class="secondary" name="action" value="down" type="submit" <?= $index === count($items) - 1 ? 'disabled' : '' ?>>↓</button></form>
          <form method="post" data-secure-confirm="Delete this item permanently? This change will be written to the audit log."><?php csrf_field(); ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="index" value="<?= $index ?>"><button class="danger-button" name="action" value="delete" type="submit">Delete</button></form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
</div>
<?php admin_footer(); ?>
