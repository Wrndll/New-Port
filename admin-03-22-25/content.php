<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/admin-layout.php';

require_admin();
$allowedSections = ['profile', 'about', 'education', 'approach'];
$section = clean_text($_GET['section'] ?? 'profile');
if (!in_array($section, $allowedSections, true)) {
    $section = 'profile';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $section = clean_text($_POST['section'] ?? 'profile');
    if (!in_array($section, $allowedSections, true)) {
        exit('Invalid content section.');
    }

    if ($section === 'profile') {
        $fields = [
            'name', 'initials', 'title', 'location', 'email', 'phoneDisplay',
            'phoneHref', 'positioning', 'valueProposition', 'heroSupport',
            'availability', 'linkedInUrl', 'githubUrl', 'bookingUrl',
        ];
        $profile = [];
        foreach ($fields as $field) {
            $profile[$field] = clean_text($_POST[$field] ?? '');
        }
        if ($profile['name'] === '' || $profile['title'] === '') {
            set_flash('error', 'Name and professional title are required.');
        } elseif ($profile['email'] !== '' && filter_var($profile['email'], FILTER_VALIDATE_EMAIL) === false) {
            set_flash('error', 'Enter a valid public email address.');
        } else {
            save_content_value('profile', $profile);
            audit_log('updated', 'content', 'profile');
            set_flash('success', 'Profile and social links updated.');
        }
    } elseif ($section === 'about') {
        $about = [
            'heading' => clean_text($_POST['heading'] ?? ''),
            'paragraphs' => lines_from_text($_POST['paragraphs'] ?? ''),
            'highlights' => lines_from_text($_POST['highlights'] ?? ''),
        ];
        save_content_value('about', $about);
        audit_log('updated', 'content', 'about');
        set_flash('success', 'About section updated.');
    } elseif ($section === 'education') {
        $education = [
            'degree' => clean_text($_POST['degree'] ?? ''),
            'institution' => clean_text($_POST['institution'] ?? ''),
            'graduationYear' => clean_text($_POST['graduationYear'] ?? ''),
        ];
        save_content_value('education', $education);
        audit_log('updated', 'content', 'education');
        set_flash('success', 'Education updated.');
    } else {
        $steps = [];
        foreach (lines_from_text($_POST['processSteps'] ?? '') as $line) {
            [$title, $description] = array_pad(explode('|', $line, 2), 2, '');
            if (trim($title) !== '') {
                $steps[] = [
                    'title' => clean_text($title),
                    'description' => clean_text($description),
                ];
            }
        }
        $filters = lines_from_text($_POST['projectFilters'] ?? '');
        $filters = array_values(array_filter($filters, static fn (string $item): bool => $item !== 'All Projects'));
        array_unshift($filters, 'All Projects');
        save_content_value('roleLabels', lines_from_text($_POST['roleLabels'] ?? ''));
        save_content_value('processSteps', $steps);
        save_content_value('strengths', lines_from_text($_POST['strengths'] ?? ''));
        save_content_value('projectFilters', $filters);
        audit_log('updated', 'content', 'approach');
        set_flash('success', 'Role labels, approach, strengths, and filters updated.');
    }
    redirect_to('admin/content.php?section=' . rawurlencode($section));
}

$profile = content_value('profile', []);
$about = content_value('about', ['heading' => '', 'paragraphs' => [], 'highlights' => []]);
$education = content_value('education', []);
$roleLabels = content_value('roleLabels', []);
$processSteps = content_value('processSteps', []);
$strengths = content_value('strengths', []);
$projectFilters = content_value('projectFilters', []);

admin_header('Profile & content', 'content');
?>
<p class="lead">Edit the public profile, about copy, education, social links, and home-page labels.</p>
<nav class="tabs" aria-label="Content sections">
  <?php foreach (['profile' => 'Profile & social', 'about' => 'About', 'education' => 'Education', 'approach' => 'Approach & labels'] as $key => $label): ?>
    <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= e(app_url('admin/content.php?section=' . $key)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="card">
<?php if ($section === 'profile'): ?>
  <form method="post" class="stack" data-dirty-form>
    <?php csrf_field(); ?><input type="hidden" name="section" value="profile">
    <div class="form-grid">
      <label>Name<input required maxlength="120" name="name" value="<?= e($profile['name'] ?? '') ?>"></label>
      <label>Initials<input maxlength="8" name="initials" value="<?= e($profile['initials'] ?? '') ?>"></label>
      <label>Professional title<input required maxlength="160" name="title" value="<?= e($profile['title'] ?? '') ?>"></label>
      <label>Location<input maxlength="160" name="location" value="<?= e($profile['location'] ?? '') ?>"></label>
      <label>Email<input type="email" maxlength="190" name="email" value="<?= e($profile['email'] ?? '') ?>"></label>
      <label>Phone shown publicly<input maxlength="80" name="phoneDisplay" value="<?= e($profile['phoneDisplay'] ?? '') ?>"></label>
      <label>Phone link value<input maxlength="80" name="phoneHref" value="<?= e($profile['phoneHref'] ?? '') ?>"></label>
      <label>Availability label<input maxlength="160" name="availability" value="<?= e($profile['availability'] ?? '') ?>"></label>
      <label class="wide">Positioning line<textarea name="positioning" maxlength="500"><?= e($profile['positioning'] ?? '') ?></textarea></label>
      <label class="wide">Value proposition<textarea name="valueProposition" maxlength="1000"><?= e($profile['valueProposition'] ?? '') ?></textarea></label>
      <label class="wide">Hero supporting copy<textarea name="heroSupport" maxlength="1000"><?= e($profile['heroSupport'] ?? '') ?></textarea></label>
      <label>LinkedIn URL<input type="url" maxlength="500" name="linkedInUrl" value="<?= e($profile['linkedInUrl'] ?? '') ?>"></label>
      <label>GitHub URL<input type="url" maxlength="500" name="githubUrl" value="<?= e($profile['githubUrl'] ?? '') ?>"></label>
      <label class="wide">Booking URL<input type="url" maxlength="500" name="bookingUrl" value="<?= e($profile['bookingUrl'] ?? '') ?>"></label>
    </div>
    <button class="primary" type="submit">Save profile</button>
  </form>
<?php elseif ($section === 'about'): ?>
  <form method="post" class="stack" data-dirty-form>
    <?php csrf_field(); ?><input type="hidden" name="section" value="about">
    <label>Heading<textarea required name="heading" maxlength="500"><?= e($about['heading'] ?? '') ?></textarea></label>
    <label>Paragraphs<textarea name="paragraphs" rows="8"><?= e(implode("\n", $about['paragraphs'] ?? [])) ?></textarea><small>One paragraph per line.</small></label>
    <label>Highlights<textarea name="highlights" rows="8"><?= e(implode("\n", $about['highlights'] ?? [])) ?></textarea><small>One highlight per line.</small></label>
    <button class="primary" type="submit">Save about section</button>
  </form>
<?php elseif ($section === 'education'): ?>
  <form method="post" class="stack" data-dirty-form>
    <?php csrf_field(); ?><input type="hidden" name="section" value="education">
    <div class="form-grid">
      <label>Degree<input maxlength="220" name="degree" value="<?= e($education['degree'] ?? '') ?>"></label>
      <label>Institution<input maxlength="220" name="institution" value="<?= e($education['institution'] ?? '') ?>"></label>
      <label>Graduation year<input maxlength="40" name="graduationYear" value="<?= e($education['graduationYear'] ?? '') ?>"></label>
    </div>
    <button class="primary" type="submit">Save education</button>
  </form>
<?php else: ?>
  <form method="post" class="stack" data-dirty-form>
    <?php csrf_field(); ?><input type="hidden" name="section" value="approach">
    <label>Rotating role labels<textarea name="roleLabels" rows="6"><?= e(implode("\n", $roleLabels)) ?></textarea><small>One label per line.</small></label>
    <label>Process steps<textarea name="processSteps" rows="9"><?php
      $stepLines = array_map(static fn (array $step): string => ($step['title'] ?? '') . ' | ' . ($step['description'] ?? ''), $processSteps);
      echo e(implode("\n", $stepLines));
    ?></textarea><small>One step per line in the format: Title | Description</small></label>
    <label>Strengths<textarea name="strengths" rows="9"><?= e(implode("\n", $strengths)) ?></textarea><small>One strength per line.</small></label>
    <label>Project filters<textarea name="projectFilters" rows="7"><?= e(implode("\n", $projectFilters)) ?></textarea><small>“All Projects” is always included first.</small></label>
    <button class="primary" type="submit">Save approach and labels</button>
  </form>
<?php endif; ?>
</section>
<?php admin_footer(); ?>
