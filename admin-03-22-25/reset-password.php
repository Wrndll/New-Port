<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';
require_once __DIR__ . '/../private/password-reset.php';

require_cms_configuration();
admin_security_headers();
start_secure_session();
ensure_password_reset_schema();

$token = is_string($_POST['token'] ?? null)
    ? strtolower(trim($_POST['token']))
    : strtolower(trim((string) ($_GET['token'] ?? '')));
$record = password_reset_token_is_well_formed($token)
    ? password_reset_find_active($token)
    : null;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $ipPermitted = password_reset_rate_limit_permits(
        'password_reset_attempt_ip',
        client_hash('password-reset-attempt'),
        PASSWORD_RESET_ATTEMPT_WINDOW,
        PASSWORD_RESET_ATTEMPTS_PER_IP
    );
    $tokenPermitted = password_reset_rate_limit_permits(
        'password_reset_attempt_token',
        hash('sha256', 'password-reset-attempt|' . $token),
        PASSWORD_RESET_ATTEMPT_WINDOW,
        PASSWORD_RESET_ATTEMPTS_PER_TOKEN
    );

    if (!$ipPermitted || !$tokenPermitted) {
        $record = null;
        $error = 'This reset request cannot be completed. Request a new password reset link.';
        operational_log(
            'password-reset-rate-limit',
            new RuntimeException('A password reset submission was rate limited.')
        );
    } elseif ($record === null) {
        $error = 'This reset link is invalid, expired, or has already been used.';
    } else {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null)
            ? $_POST['password_confirmation']
            : '';
        try {
            if (strlen($password) > 4096 || strlen($confirmation) > 4096) {
                throw new RuntimeException('The password entries are too long.');
            }
            validate_strong_password($password);
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('The two password entries do not match.');
            }
            if (password_verify($password, (string) $record['password_hash'])) {
                throw new RuntimeException('Choose a password different from the current password.');
            }

            $connection = db();
            $connection->beginTransaction();
            try {
                $lock = $connection->prepare(
                    'SELECT id, admin_id
                     FROM password_reset_tokens
                     WHERE token_hash = ?
                       AND used_at IS NULL
                       AND expires_at > NOW()
                     LIMIT 1 FOR UPDATE'
                );
                $lock->execute([hash('sha256', $token)]);
                $lockedRecord = $lock->fetch();
                if (!is_array($lockedRecord)) {
                    throw new RuntimeException('This reset link is invalid, expired, or has already been used.');
                }

                $updateAdmin = $connection->prepare(
                    'UPDATE admins SET password_hash = ? WHERE id = ?'
                );
                $updateAdmin->execute([
                    password_hash($password, PASSWORD_DEFAULT),
                    (int) $lockedRecord['admin_id'],
                ]);
                $invalidate = $connection->prepare(
                    'UPDATE password_reset_tokens
                     SET used_at = NOW()
                     WHERE admin_id = ? AND used_at IS NULL'
                );
                $invalidate->execute([(int) $lockedRecord['admin_id']]);
                $clearLoginThrottle = $connection->prepare(
                    'DELETE FROM rate_limits
                     WHERE scope_name = "admin_login_account" AND key_hash = ?'
                );
                $clearLoginThrottle->execute([
                    hash('sha256', 'admin-login|' . strtolower((string) $record['email'])),
                ]);
                $connection->commit();
                $adminId = (int) $lockedRecord['admin_id'];
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $exception;
            }

            password_reset_audit('completed', (string) $adminId, [
                'sessions' => 'current_browser_invalidated',
            ]);
            start_secure_session();
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            set_flash('success', 'Your password was updated. Sign in with the new password.');
            redirect_to(admin_path('login.php'));
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $record = password_reset_find_active($token);
        } catch (Throwable $exception) {
            operational_log('password-reset-completion', $exception);
            $error = 'The password could not be changed. Request a new reset link and try again.';
            $record = null;
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
  <title>Choose a new password · HelloWrandell CMS</title>
  <link rel="icon" href="<?= e(app_url('images/brand/favicon-v24-32.png')) ?>" sizes="32x32" type="image/png">
  <link rel="stylesheet" href="<?= e(app_url(admin_path('assets/admin.css?v=2.10.0'))) ?>">
</head>
<body class="auth-page">
  <main class="auth-card">
    <a class="brand brand-with-mark" href="<?= e(app_url('')) ?>">
      <span class="adaptive-logo" aria-hidden="true"></span>
      <span><strong>HelloWrandell</strong><small>Secure CMS</small></span>
    </a>
    <p class="eyebrow">Protected password reset</p>
    <h1>Choose a new password</h1>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($record !== null): ?>
      <p class="muted">Use at least 14 characters with uppercase, lowercase, number, and symbol characters.</p>
      <form class="stack" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>New password<input required type="password" minlength="14" maxlength="4096" autocomplete="new-password" name="password"></label>
        <label>Confirm new password<input required type="password" minlength="14" maxlength="4096" autocomplete="new-password" name="password_confirmation"></label>
        <button class="primary" type="submit">Update password securely</button>
      </form>
      <p class="security-note">After a successful reset, this link is permanently invalidated and the current browser session is rotated.</p>
    <?php else: ?>
      <p class="muted">This reset link is invalid, expired, or has already been used.</p>
      <a class="primary" href="<?= e(app_url(admin_path('forgot-password.php'))) ?>">Request a new reset link</a>
    <?php endif; ?>
    <a class="back-link" href="<?= e(app_url(admin_path('login.php'))) ?>">← Return to sign in</a>
  </main>
</body>
</html>

