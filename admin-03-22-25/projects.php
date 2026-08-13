<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/admin-layout.php';

require_admin();

function project_slug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-') ?: 'project-' . bin2hex(random_bytes(4));
}

function safe_external_url(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Project links must be valid URLs.');
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Project links must use HTTP or HTTPS.');
    }
    return $value;
}

function delete_uploaded_preview(string $path): void
{
    delete_uploaded_asset($path, 'projects');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = clean_text($_POST['action'] ?? 'save');
    $originalId = clean_text($_POST['original_id'] ?? '');
    if ($originalId !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $originalId)) {
        set_flash('error', 'The project identifier is invalid.');
        redirect_to(admin_path('projects.php'));
    }

    if ($action === 'delete' && $originalId !== '') {
        try {
            require_current_admin_password($_POST['confirm_password'] ?? '');
            $lookup = db()->prepare('SELECT preview_image FROM projects WHERE id = ?');
            $lookup->execute([$originalId]);
            $preview = $lookup->fetchColumn();
            $delete = db()->prepare('DELETE FROM projects WHERE id = ?');
            $delete->execute([$originalId]);
            if (is_string($preview)) {
                delete_uploaded_preview($preview);
            }
            audit_log('deleted', 'project', $originalId);
            set_flash('success', 'Project deleted.');
        } catch (Throwable $exception) {
            set_flash('error', $exception->getMessage());
        }
        redirect_to(admin_path('projects.php'));
    }

    $newUploadedPreview = '';
    try {
        $title = clean_text($_POST['title'] ?? '');
        if ($title === '' || text_length($title) > 180) {
            throw new RuntimeException('Project title is required and must stay under 180 characters.');
        }
        $id = $originalId !== '' ? $originalId : project_slug($title);
        $previousPreviewImage = '';
        if ($originalId === '') {
            $baseId = $id;
            $suffix = 2;
            $check = db()->prepare('SELECT COUNT(*) FROM projects WHERE id = ?');
            do {
                $check->execute([$id]);
                if ((int) $check->fetchColumn() === 0) {
                    break;
                }
                $id = $baseId . '-' . $suffix;
                $suffix += 1;
            } while ($suffix < 100);
        } else {
            $existingQuery = db()->prepare('SELECT preview_image FROM projects WHERE id = ? LIMIT 1');
            $existingQuery->execute([$originalId]);
            $existingPreview = $existingQuery->fetchColumn();
            if (!is_string($existingPreview)) {
                throw new RuntimeException('The project could not be found. Refresh the project list and try again.');
            }
            $previousPreviewImage = $existingPreview;
        }

        $previewImage = $previousPreviewImage;
        $upload = $_FILES['preview_image'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $previewImage = safe_image_upload($upload, 'projects', $id, 2400, 1600);
            $newUploadedPreview = $previewImage;
        }
        if ($previewImage === '') {
            throw new RuntimeException('Choose a preview image.');
        }

        $textFields = [
            'category', 'summary', 'overview', 'problem', 'objectives', 'role',
            'targetUsers', 'requirements', 'planning', 'solution',
            'implementation', 'testing', 'security', 'challenges', 'results',
            'lessons', 'previewAlt', 'previewLabel',
        ];
        $values = [];
        foreach ($textFields as $field) {
            $values[$field] = clean_text($_POST[$field] ?? '');
        }
        if (
            $values['summary'] === ''
            || $values['previewAlt'] === ''
            || text_length($values['summary']) > 1600
            || text_length($values['previewAlt']) > 300
        ) {
            throw new RuntimeException('Summary and preview alternative text are required.');
        }
        foreach ($values as $field => $value) {
            $maximum = in_array($field, ['category', 'previewLabel'], true) ? 120 : 6000;
            if (text_length($value) > $maximum) {
                throw new RuntimeException('One or more project fields exceed the allowed length.');
            }
        }
        $repositoryUrl = safe_external_url(clean_text($_POST['repositoryUrl'] ?? ''));
        $liveUrl = safe_external_url(clean_text($_POST['liveUrl'] ?? ''));
        $technologies = lines_from_text($_POST['technologies'] ?? '');
        $sortOrder = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        $sortOrder = $sortOrder === false ? 0 : (int) $sortOrder;

        $query = db()->prepare(
            'INSERT INTO projects (
                id, title, category, summary, technologies_json, overview, problem,
                objectives, role_text, target_users, requirements_text, planning,
                solution, implementation, testing, security_text, challenges,
                results_text, lessons, repository_url, live_url, preview_image,
                preview_alt, preview_label, is_concept, published, sort_order
             ) VALUES (
                :id, :title, :category, :summary, :technologies, :overview, :problem,
                :objectives, :role_text, :target_users, :requirements_text, :planning,
                :solution, :implementation, :testing, :security_text, :challenges,
                :results_text, :lessons, :repository_url, :live_url, :preview_image,
                :preview_alt, :preview_label, :is_concept, :published, :sort_order
             ) ON DUPLICATE KEY UPDATE
                title=VALUES(title), category=VALUES(category), summary=VALUES(summary),
                technologies_json=VALUES(technologies_json), overview=VALUES(overview),
                problem=VALUES(problem), objectives=VALUES(objectives), role_text=VALUES(role_text),
                target_users=VALUES(target_users), requirements_text=VALUES(requirements_text),
                planning=VALUES(planning), solution=VALUES(solution),
                implementation=VALUES(implementation), testing=VALUES(testing),
                security_text=VALUES(security_text), challenges=VALUES(challenges),
                results_text=VALUES(results_text), lessons=VALUES(lessons),
                repository_url=VALUES(repository_url), live_url=VALUES(live_url),
                preview_image=VALUES(preview_image), preview_alt=VALUES(preview_alt),
                preview_label=VALUES(preview_label), is_concept=VALUES(is_concept),
                published=VALUES(published), sort_order=VALUES(sort_order)'
        );
        $query->execute([
            'id' => $id,
            'title' => $title,
            'category' => $values['category'],
            'summary' => $values['summary'],
            'technologies' => json_encode($technologies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'overview' => $values['overview'],
            'problem' => $values['problem'],
            'objectives' => $values['objectives'],
            'role_text' => $values['role'],
            'target_users' => $values['targetUsers'],
            'requirements_text' => $values['requirements'],
            'planning' => $values['planning'],
            'solution' => $values['solution'],
            'implementation' => $values['implementation'],
            'testing' => $values['testing'],
            'security_text' => $values['security'],
            'challenges' => $values['challenges'],
            'results_text' => $values['results'],
            'lessons' => $values['lessons'],
            'repository_url' => $repositoryUrl,
            'live_url' => $liveUrl,
            'preview_image' => $previewImage,
            'preview_alt' => $values['previewAlt'],
            'preview_label' => $values['previewLabel'],
            'is_concept' => isset($_POST['isConcept']) ? 1 : 0,
            'published' => isset($_POST['published']) ? 1 : 0,
            'sort_order' => $sortOrder,
        ]);
        if ($newUploadedPreview !== '' && $previousPreviewImage !== '') {
            delete_uploaded_preview($previousPreviewImage);
        }
        audit_log($originalId === '' ? 'created' : 'updated', 'project', $id);
        set_flash('success', 'Project saved.');
        redirect_to('admin/projects.php?edit=' . rawurlencode($id));
    } catch (Throwable $exception) {
        if ($newUploadedPreview !== '') {
            delete_uploaded_preview($newUploadedPreview);
        }
        set_flash('error', $exception instanceof PDOException ? 'The project could not be saved.' : $exception->getMessage());
        redirect_to('admin/projects.php' . ($originalId !== '' ? '?edit=' . rawurlencode($originalId) : '?new=1'));
    }
}

$editId = clean_text($_GET['edit'] ?? '');
$project = null;
if ($editId !== '') {
    $query = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $query->execute([$editId]);
    $project = $query->fetch() ?: null;
}
$showEditor = isset($_GET['new']) || is_array($project);
$projects = db()->query('SELECT * FROM projects ORDER BY sort_order ASC, created_at ASC')->fetchAll();
$projectFilters = content_value('projectFilters', []);
$caseStudyFields = [
    'overview' => 'Overview',
    'problem' => 'Problem',
    'objectives' => 'Objectives',
    'role' => 'My role',
    'targetUsers' => 'Target users',
    'requirements' => 'Requirements',
    'planning' => 'Planning process',
    'solution' => 'Solution design',
    'implementation' => 'Technical implementation',
    'testing' => 'Testing process',
    'security' => 'Security considerations',
    'challenges' => 'Challenges',
    'results' => 'Results',
    'lessons' => 'Lessons learned',
];
$columnMap = [
    'role' => 'role_text',
    'targetUsers' => 'target_users',
    'requirements' => 'requirements_text',
    'security' => 'security_text',
    'results' => 'results_text',
];

admin_header('Projects', 'projects');
?>
<div class="section-actions">
  <p class="results-summary"><?= count($projects) ?> project<?= count($projects) === 1 ? '' : 's' ?> in the CMS. Drafts stay private until published.</p>
  <?php if (!$showEditor): ?><a class="primary" href="<?= e(app_url(admin_path('projects.php?new=1'))) ?>"><?php admin_icon('plus', 17); ?> Add project</a><?php endif; ?>
</div>

<?php if ($showEditor): ?>
<section class="editor-shell">
  <div class="card-head editor-heading"><div><span class="card-kicker">Case-file editor</span><h2><?= $project ? 'Edit project' : 'Add project' ?></h2></div><a href="<?= e(app_url(admin_path('projects.php'))) ?>">← Back to projects</a></div>
  <form method="post" enctype="multipart/form-data" class="stack" data-dirty-form>
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="original_id" value="<?= e($project['id'] ?? '') ?>">
    <div class="editor-grid">
      <div class="editor-main">
        <section class="card editor-section">
          <div class="editor-section__head"><span>01</span><div><h3>Project identity</h3><p>What visitors see first in the project grid.</p></div></div>
          <div class="form-grid">
            <label>Title<input required maxlength="180" name="title" value="<?= e($project['title'] ?? '') ?>"></label>
            <label>Category<input list="project-categories" maxlength="120" name="category" value="<?= e($project['category'] ?? '') ?>"><datalist id="project-categories"><?php foreach ($projectFilters as $filter): if ($filter !== 'All Projects'): ?><option value="<?= e($filter) ?>"><?php endif; endforeach; ?></datalist></label>
            <label class="wide">Minimal summary<textarea required maxlength="1600" name="summary"><?= e($project['summary'] ?? '') ?></textarea><small>Keep this concise; the complete story belongs in the case-study fields.</small></label>
            <label>Technologies<textarea name="technologies" rows="6"><?= e(implode("\n", json_decode($project['technologies_json'] ?? '[]', true) ?: [])) ?></textarea><small>One technology per line.</small></label>
            <label>Display order<input type="number" name="sort_order" value="<?= e($project['sort_order'] ?? count($projects)) ?>"></label>
            <label>Repository URL<input type="url" maxlength="500" name="repositoryUrl" value="<?= e($project['repository_url'] ?? '') ?>"></label>
            <label>Live URL<input type="url" maxlength="500" name="liveUrl" value="<?= e($project['live_url'] ?? '') ?>"></label>
          </div>
        </section>

        <section class="card editor-section">
          <div class="editor-section__head"><span>02</span><div><h3>Case-study narrative</h3><p>Document only verified information. Empty fields remain clearly marked on the public view.</p></div></div>
          <div class="form-grid">
            <?php foreach ($caseStudyFields as $field => $label): $column = $columnMap[$field] ?? $field; ?>
              <label class="wide"><?= e($label) ?><textarea maxlength="6000" name="<?= e($field) ?>"><?= e($project[$column] ?? '') ?></textarea></label>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <aside class="editor-aside">
        <section class="card editor-section media-card">
          <div class="editor-section__head"><span>03</span><div><h3>Preview media</h3><p>Safe JPEG, PNG, or WebP only.</p></div></div>
          <?php if (!empty($project['preview_image'])): ?><img class="preview" src="<?= e(app_url(ltrim($project['preview_image'], '/'))) ?>" alt="<?= e($project['preview_alt'] ?? '') ?>"><?php else: ?><div class="upload-placeholder"><?php admin_icon('projects', 24); ?><span>Preview appears here after upload</span></div><?php endif; ?>
          <label>Preview image<input type="file" name="preview_image" accept="image/jpeg,image/png,image/webp" <?= $project ? '' : 'required' ?>><small>Maximum 5 MB and 36 megapixels. Images are securely renamed, resized, and converted to WebP when PHP GD is available.</small></label>
          <label>Image alternative text<input required maxlength="300" name="previewAlt" value="<?= e($project['preview_alt'] ?? '') ?>"></label>
          <label>Preview label<input maxlength="80" name="previewLabel" value="<?= e($project['preview_label'] ?? 'Concept preview') ?>"></label>
        </section>

        <section class="card editor-section publish-card">
          <div class="editor-section__head"><span>04</span><div><h3>Publishing</h3><p>Control context and public visibility.</p></div></div>
          <label class="choice-row"><input type="checkbox" name="isConcept" value="1" <?= !isset($project['is_concept']) || (int) $project['is_concept'] === 1 ? 'checked' : '' ?>><span><strong>Concept artwork</strong><small>Clearly label this image as a design concept.</small></span></label>
          <label class="choice-row"><input type="checkbox" name="published" value="1" <?= !isset($project['published']) || (int) $project['published'] === 1 ? 'checked' : '' ?>><span><strong>Published</strong><small>Make this case file visible on the portfolio.</small></span></label>
        </section>
      </aside>
    </div>
    <div class="sticky-save"><span><?php admin_icon('shield', 16); ?> Changes are validated and added to the audit log.</span><button class="primary" type="submit">Save project</button></div>
  </form>
</section>
<?php else: ?>
<section class="project-admin-grid">
  <?php if (!$projects): ?><div class="empty-state card"><?php admin_icon('projects', 28); ?><strong>No projects yet</strong><span>Create the first case file to begin your project library.</span></div><?php endif; ?>
  <?php foreach ($projects as $item): ?>
    <article class="admin-project-card">
      <div class="admin-project-card__media">
        <img src="<?= e(app_url(ltrim($item['preview_image'], '/'))) ?>" alt="<?= e($item['preview_alt']) ?>">
        <span class="order-chip">Order <?= (int) $item['sort_order'] ?></span>
        <span class="badge <?= (int) $item['published'] === 1 ? 'published' : '' ?>"><?= (int) $item['published'] === 1 ? 'Published' : 'Draft' ?></span>
      </div>
      <div class="admin-project-card__body">
        <span class="card-kicker"><?= e($item['category'] ?: 'Uncategorized') ?></span>
        <h2><?= e($item['title']) ?></h2>
        <p><?= e($item['summary']) ?></p>
        <div class="project-meta"><span><?= (int) $item['is_concept'] === 1 ? 'Concept preview' : 'Project image' ?></span><span><?= e($item['id']) ?></span></div>
        <div class="actions">
          <a class="primary" href="<?= e(app_url('admin/projects.php?edit=' . rawurlencode($item['id']))) ?>">Edit case file</a>
          <form method="post" data-secure-confirm="Delete this project and its uploaded preview permanently?"><?php csrf_field(); ?><input type="hidden" name="original_id" value="<?= e($item['id']) ?>"><button class="danger-button" name="action" value="delete" type="submit">Delete</button></form>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
