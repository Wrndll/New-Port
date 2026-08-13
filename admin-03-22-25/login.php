<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';

require_cms_configuration();
admin_security_headers();
if (current_admin() !== null) {
    redirect_to(admin_path(''));
}

$error = '';
$sessionNotice = take_session_notice();
$flash = take_flash();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $email = strtolower(clean_text($_POST['email'] ?? ''));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ipHash = client_hash();
    $accountHash = hash('sha256', 'admin-login|' . $email);
    if (random_int(1, 25) === 1) {
        db()->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        db()->exec(
            'DELETE FROM rate_limits
             WHERE scope_name = "admin_login_account"
               AND attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        );
    }
    $check = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_hash = ? AND successful = 0
           AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $check->execute([$ipHash]);
    $accountCheck = db()->prepare(
        'SELECT COUNT(*) FROM rate_limits
         WHERE scope_name = "admin_login_account" AND key_hash = ?
           AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $accountCheck->execute([$accountHash]);
    if ((int) $check->fetchColumn() >= 8 || (int) $accountCheck->fetchColumn() >= 8) {
        $error = 'Too many sign-in attempts. Wait 15 minutes and try again.';
    } else {
        $query = db()->prepare('SELECT id, password_hash FROM admins WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        $candidate = $query->fetch();
        $candidateHash = is_array($candidate) && is_string($candidate['password_hash'] ?? null)
            ? $candidate['password_hash']
            : '$2y$12$pb017UxPmzJv/hiM5Gjj6.OqzAvHFYO5JgqsDPntCZHXzpvz1lD6S';
        $valid = password_verify($password, $candidateHash) && is_array($candidate);
        $attempt = db()->prepare('INSERT INTO login_attempts (ip_hash, successful) VALUES (?, ?)');
        $attempt->execute([$ipHash, $valid ? 1 : 0]);
        if ($valid) {
            if (password_needs_rehash($candidateHash, PASSWORD_DEFAULT)) {
                $rehash = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $candidate['id']]);
            }
            $clearAccountAttempts = db()->prepare(
                'DELETE FROM rate_limits WHERE scope_name = "admin_login_account" AND key_hash = ?'
            );
            $clearAccountAttempts->execute([$accountHash]);
            establish_admin_session((int) $candidate['id']);
            $update = db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?');
            $update->execute([(int) $candidate['id']]);
            redirect_to(admin_path(''));
        }
        $recordAccountAttempt = db()->prepare(
            'INSERT INTO rate_limits (scope_name, key_hash) VALUES ("admin_login_account", ?)'
        );
        $recordAccountAttempt->execute([$accountHash]);
        usleep(350000);
        $error = 'The email or password is incorrect.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Sign in · HelloWrandell CMS</title>
  <link rel="icon" href="<?= e(app_url('images/brand/favicon-v24-32.png')) ?>" sizes="32x32" type="image/png">
  <link rel="stylesheet" href="<?= e(app_url(admin_path('assets/admin.css?v=2.10.0'))) ?>">
</head>
<body class="auth-page">
  <main class="auth-card">
    <a class="brand brand-with-mark" href="<?= e(app_url('')) ?>">
      <span class="adaptive-logo" aria-hidden="true"></span>
      <span><strong>HelloWrandell</strong><small>Secure CMS</small></span>
    </a>
    <p class="eyebrow">Private administration</p>
    <h1>Welcome back</h1>
    <p class="muted">Manage portfolio content, projects, messages, and resume requests.</p>
    <?php if ($sessionNotice === 'expired'): ?><div class="notice warning" role="status">Your protected session expired. Sign in again to continue.</div><?php endif; ?>
    <?php if ($sessionNotice === 'credentials_changed'): ?><div class="notice warning" role="status">Your password changed, so the previous protected session was closed. Sign in again.</div><?php endif; ?>
    <?php if (is_array($flash)): ?><div class="notice <?= e((string) ($flash['type'] ?? 'success')) ?>" role="status"><?= e((string) ($flash['message'] ?? '')) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form class="stack" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Email<input required type="email" autocomplete="username" name="email"></label>
      <label>Password<input required type="password" autocomplete="current-password" name="password"></label>
      <button class="primary" type="submit">Sign in securely</button>
    </form>
    <a class="back-link" href="<?= e(app_url(admin_path('forgot-password.php'))) ?>">Forgot your password?</a>
    <p class="security-note">Sessions expire after inactivity. Repeated failed sign-ins are temporarily blocked.</p>
    <a class="back-link" href="<?= e(app_url('')) ?>">← Return to portfolio</a>
  </main>
  <script src="<?= e(app_url(admin_path('assets/admin.js?v=2.10.0'))) ?>" defer></script>
</body>
</html>

