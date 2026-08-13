<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';

redirect_configured_cms_away_from_setup();

admin_security_headers();
start_secure_session();

$setupAccessError = '';
if (!setup_access_granted()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf();
        $action = clean_text($_POST['action'] ?? '');
        if ($action !== 'unlock_setup') {
            $setupAccessError = 'The setup request is invalid. Refresh and try again.';
        } else {
            $remaining = setup_lock_remaining();
            if ($remaining > 0) {
                $setupAccessError = 'Too many failed setup attempts. Wait ' . max(1, (int) ceil($remaining / 60)) . ' minute(s) and try again.';
            } elseif (verify_setup_access_password($_POST['setup_password'] ?? '')) {
                clear_setup_access_failures();
                grant_setup_access();
                redirect_to(admin_path('setup.php'));
            } else {
                $remaining = record_setup_access_failure();
                usleep(400000);
                $setupAccessError = $remaining > 0
                    ? 'Too many failed setup attempts. Setup access is temporarily locked.'
                    : 'The setup access password is incorrect.';
            }
        }
    }
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Protected setup · HelloWrandell CMS</title>
  <link rel="icon" href="<?= e(app_url('images/brand/favicon-v24-32.png')) ?>" sizes="32x32" type="image/png">
  <link rel="stylesheet" href="<?= e(app_url(admin_path('assets/admin.css?v=2.10.0'))) ?>">
</head>
<body class="auth-page">
  <main class="setup-card setup-access-card">
    <a class="brand brand-with-mark" href="<?= e(app_url('')) ?>">
      <span class="adaptive-logo" aria-hidden="true"></span>
      <span><strong>HelloWrandell</strong><small>Protected setup</small></span>
    </a>
    <p class="eyebrow">Authorized access only</p>
    <h1>Unlock first-run setup</h1>
    <p class="muted">Enter the separate setup password before database and administrator configuration can be opened. Failed attempts are rate-limited and this access expires after 10 minutes.</p>
    <?php if ($setupAccessError !== ''): ?><div class="notice error" role="alert"><?= e($setupAccessError) ?></div><?php endif; ?>
    <form method="post" class="stack" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="unlock_setup">
      <label>Setup access password<input required type="password" autocomplete="current-password" name="setup_password" maxlength="4096"></label>
      <button class="primary" type="submit">Unlock setup</button>
    </form>
    <p class="security-note">The setup password is stored only as a one-way hash. After successful configuration, this page automatically locks and redirects to the CMS login.</p>
    <a class="back-link" href="<?= e(app_url('')) ?>">← Return to portfolio</a>
  </main>
</body>
</html>
<?php
    exit;
}

$error = '';
$values = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'hello_wrandell',
    'db_user' => 'root',
    'base_path' => base_path() !== '' ? base_path() : '/HelloWrandell',
    'site_origin' => 'http://localhost',
    'mail_from' => 'wrandellalmeda@gmail.com',
    'mail_recipient' => 'wrandellalmeda@gmail.com',
    'admin_email' => 'wrandellalmeda@gmail.com',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    if (clean_text($_POST['action'] ?? '') !== 'configure') {
        $error = 'The setup request is invalid. Refresh and try again.';
    } else {
        foreach (array_keys($values) as $key) {
            $values[$key] = clean_text($_POST[$key] ?? $values[$key]);
        }
        $dbPassword = is_string($_POST['db_pass'] ?? null) ? $_POST['db_pass'] : '';
        $adminPassword = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';

        try {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_name'])) {
                throw new RuntimeException('Use only letters, numbers, and underscores in the database name.');
            }
            if (!ctype_digit($values['db_port']) || (int) $values['db_port'] < 1 || (int) $values['db_port'] > 65535) {
                throw new RuntimeException('Enter a valid MySQL port.');
            }
            if (filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid administrator email.');
            }
            if (filter_var($values['mail_recipient'], FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid notification recipient.');
            }
            if (filter_var($values['mail_from'], FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid sender address.');
            }
            validate_strong_password($adminPassword);
            $basePath = '/' . trim($values['base_path'], '/');
            if ($basePath === '/' || !preg_match('#^/[A-Za-z0-9/_-]+$#', $basePath)) {
                throw new RuntimeException('Enter a valid base path such as /HelloWrandell.');
            }
            $siteOrigin = rtrim($values['site_origin'], '/');
            if (!site_origin_is_valid($siteOrigin)) {
                throw new RuntimeException('Enter an origin only, such as http://localhost or https://portfolio.example.com.');
            }

            $serverDsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $values['db_host'],
                (int) $values['db_port']
            );
            $connection = new PDO($serverDsn, $values['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $quotedDatabase = '`' . str_replace('`', '``', $values['db_name']) . '`';
            $connection->exec(
                'CREATE DATABASE IF NOT EXISTS ' . $quotedDatabase
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $connection->exec('USE ' . $quotedDatabase);

            $schema = file_get_contents(__DIR__ . '/../private/schema.sql');
            if (!is_string($schema)) {
                throw new RuntimeException('The database schema could not be read.');
            }
            $statements = preg_split('/;\s*(?:\R|$)/', $schema) ?: [];
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $connection->exec($statement);
                }
            }

            $admin = $connection->prepare(
                'INSERT INTO admins (email, password_hash) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
            );
            $admin->execute([
                strtolower($values['admin_email']),
                password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);

            $seed = require __DIR__ . '/../private/seed.php';
            $saveContent = $connection->prepare(
                'INSERT INTO site_content (content_key, content_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE content_json = VALUES(content_json)'
            );
            foreach ($seed['content'] as $key => $contentValue) {
                $saveContent->execute([
                    $key,
                    json_encode($contentValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }

            $projectQuery = $connection->prepare(
                'INSERT INTO projects (
                    id, title, category, summary, technologies_json, overview, problem,
                    objectives, role_text, target_users, requirements_text, planning,
                    solution, implementation, testing, security_text, challenges,
                    results_text, lessons, repository_url, live_url, preview_image,
                    preview_alt, preview_label, is_concept, published, sort_order
                 ) VALUES (
                    :id, :title, :category, :summary, :technologies, "", "", "", "", "",
                    "", "", "", "", "", "", "", "", "", "", "", :preview_image,
                    :preview_alt, "Concept preview", 1, 1, :sort_order
                 ) ON DUPLICATE KEY UPDATE title = VALUES(title)'
            );
            foreach ($seed['projects'] as $index => $project) {
                $projectQuery->execute([
                    'id' => $project['id'],
                    'title' => $project['title'],
                    'category' => $project['category'],
                    'summary' => $project['summary'],
                    'technologies' => '[]',
                    'preview_image' => $project['preview_image'],
                    'preview_alt' => $project['preview_alt'],
                    'sort_order' => $index,
                ]);
            }

            $configuration = [
                'db_host' => $values['db_host'],
                'db_port' => (int) $values['db_port'],
                'db_name' => $values['db_name'],
                'db_user' => $values['db_user'],
                'db_pass' => $dbPassword,
                'base_path' => $basePath,
                'site_origin' => $siteOrigin,
                'mail_from_name' => 'Wrandell Almeda Portfolio',
                'mail_from' => strtolower($values['mail_from']),
                'mail_recipient' => strtolower($values['mail_recipient']),
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => strtolower($values['mail_from']),
                'smtp_password_encrypted' => '',
                'mail_last_test_at' => '',
                'mail_last_test_status' => 'Not tested',
            ];
            write_config($configuration);
            $adminId = (int) $connection->lastInsertId();
            if ($adminId === 0) {
                $lookup = $connection->prepare('SELECT id FROM admins WHERE email = ?');
                $lookup->execute([strtolower($values['admin_email'])]);
                $adminId = (int) $lookup->fetchColumn();
            }
            establish_admin_session($adminId);
            redirect_to(admin_path(''));
        } catch (Throwable $exception) {
            $error = $exception instanceof PDOException
                ? 'Setup could not connect to MySQL or create the database. Check the XAMPP MySQL service and credentials.'
                : $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Set up HelloWrandell CMS</title>
  <link rel="icon" href="<?= e(app_url('images/brand/favicon-v24-32.png')) ?>" sizes="32x32" type="image/png">
  <link rel="stylesheet" href="<?= e(app_url(admin_path('assets/admin.css?v=2.10.0'))) ?>">
</head>
<body class="auth-page">
  <main class="setup-card">
    <a class="brand brand-with-mark" href="<?= e(app_url('')) ?>">
      <span class="adaptive-logo" aria-hidden="true"></span>
      <span><strong>HelloWrandell</strong><small>Secure CMS</small></span>
    </a>
    <p class="eyebrow">Protected first-run setup</p>
    <h1>HelloWrandell CMS</h1>
    <p class="muted">Create the MySQL database, private configuration, and first administrator. This unlocked setup session expires after 10 minutes and setup locks automatically when completed.</p>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="configure">
      <fieldset>
        <legend>MySQL</legend>
        <div class="form-grid">
          <label>Host<input required name="db_host" value="<?= e($values['db_host']) ?>"></label>
          <label>Port<input required inputmode="numeric" name="db_port" value="<?= e($values['db_port']) ?>"></label>
          <label>Database<input required name="db_name" value="<?= e($values['db_name']) ?>"></label>
          <label>User<input required name="db_user" value="<?= e($values['db_user']) ?>"></label>
          <label class="wide">Password<input type="password" name="db_pass" autocomplete="new-password"><small>Blank is common for a new local XAMPP installation. Use a dedicated database user before public deployment.</small></label>
        </div>
      </fieldset>
      <fieldset>
        <legend>Website and email</legend>
        <div class="form-grid">
          <label>Base path<input required name="base_path" value="<?= e($values['base_path']) ?>"></label>
          <label>Portfolio origin<input required type="url" name="site_origin" value="<?= e($values['site_origin']) ?>"><small>Use http://localhost for local XAMPP. Use HTTPS in production.</small></label>
          <label>Mail sender<input required type="email" name="mail_from" value="<?= e($values['mail_from']) ?>"></label>
          <label class="wide">Notification recipient<input required type="email" name="mail_recipient" value="<?= e($values['mail_recipient']) ?>"></label>
        </div>
      </fieldset>
      <fieldset>
        <legend>Administrator</legend>
        <div class="form-grid">
          <label>Admin email<input required type="email" autocomplete="username" name="admin_email" value="<?= e($values['admin_email']) ?>"></label>
          <label>Password<input required minlength="14" maxlength="4096" type="password" autocomplete="new-password" name="admin_password"><small>At least 14 characters with uppercase, lowercase, number, and symbol.</small></label>
        </div>
      </fieldset>
      <button class="primary" type="submit">Create CMS securely</button>
    </form>
  </main>
</body>
</html>

