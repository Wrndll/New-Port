<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';
require_once __DIR__ . '/../private/password-reset.php';

require_cms_configuration();
admin_security_headers();
if (current_admin() !== null) {
    redirect_to(admin_path(''));
}

$submitted = ($_SESSION['password_reset_notice'] ?? false) === true;
unset($_SESSION['password_reset_notice']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $requestStartedAt = microtime(true);
    verify_csrf();
    $email = strtolower(clean_text($_POST['email'] ?? ''));
    $ipHash = client_hash('password-reset-request');
    $accountHash = hash('sha256', 'password-reset-account|' . $email);

    try {
        ensure_password_reset_schema();
        $ipPermitted = password_reset_rate_limit_permits(
            'password_reset_ip',
            $ipHash,
            PASSWORD_RESET_REQUEST_WINDOW,
            PASSWORD_RESET_REQUESTS_PER_IP
        );
        $accountPermitted = password_reset_rate_limit_permits(
            'password_reset_account',
            $accountHash,
            PASSWORD_RESET_REQUEST_WINDOW,
            PASSWORD_RESET_REQUESTS_PER_ACCOUNT
        );

        if ($ipPermitted && $accountPermitted && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $query = db()->prepare('SELECT id, email FROM admins WHERE email = ? LIMIT 1');
            $query->execute([$email]);
            $admin = $query->fetch();

            if (is_array($admin)) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $connection = db();
                $connection->beginTransaction();
                try {
                    $insert = $connection->prepare(
                        'INSERT INTO password_reset_tokens
                            (admin_id, token_hash, request_ip_hash, user_agent_hash, expires_at)
                         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
                    );
                    $insert->execute([
                        (int) $admin['id'],
                        $tokenHash,
                        $ipHash,
                        password_reset_user_agent_hash(),
                        PASSWORD_RESET_EXPIRY_MINUTES,
                    ]);
                    $resetId = (int) $connection->lastInsertId();
                    $connection->commit();
                } catch (Throwable $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    throw $exception;
                }

                $resetUrl = absolute_url(
                    admin_path('reset-password.php?token=' . rawurlencode($token))
                );
                try {
                    $delivered = password_reset_send_email((string) $admin['email'], $resetUrl);
                } catch (Throwable $exception) {
                    $delivered = false;
                    operational_log('password-reset-delivery', $exception);
                }

                if ($delivered) {
                    // Keep any previously delivered link usable until the new
                    // message is accepted, then make the newest link canonical.
                    $invalidateOlder = db()->prepare(
                        'UPDATE password_reset_tokens
                         SET used_at = NOW()
                         WHERE admin_id = ? AND id <> ? AND used_at IS NULL'
                    );
                    $invalidateOlder->execute([(int) $admin['id'], $resetId]);
                } else {
                    $expire = db()->prepare(
                        'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?'
                    );
                    $expire->execute([$resetId]);
                    operational_log(
                        'password-reset-delivery',
                        new RuntimeException('The password reset email was not accepted by the configured mail transport.')
                    );
                }
                password_reset_audit(
                    $delivered ? 'requested' : 'delivery_failed',
                    (string) $admin['id'],
                    ['delivery' => $delivered ? 'accepted' : 'failed']
                );
            } else {
                password_reset_audit('request_received', '', ['account_match' => false]);
            }
        } elseif (!$ipPermitted || !$accountPermitted) {
            operational_log(
                'password-reset-rate-limit',
                new RuntimeException('A password reset request was rate limited.')
            );
        }
    } catch (Throwable $exception) {
        operational_log('password-reset-request', $exception);
    }

    // Keep the response envelope similar for matching and non-matching
    // accounts while SMTP remains synchronous in this compact CMS.
    $targetSeconds = random_int(4500, 5200) / 1000;
    $remainingSeconds = $targetSeconds - (microtime(true) - $requestStartedAt);
    if ($remainingSeconds > 0) {
        usleep((int) ($remainingSeconds * 1000000));
    }
    $_SESSION['password_reset_notice'] = true;
    redirect_to(admin_path('forgot-password.php'));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Forgot password · HelloWrandell CMS</title>
  <link rel="icon" href="<?= e(app_url('images/brand/favicon-v24-32.png')) ?>" sizes="32x32" type="image/png">
  <link rel="stylesheet" href="<?= e(app_url(admin_path('assets/admin.css?v=2.10.0'))) ?>">
</head>
<body class="auth-page">
  <main class="auth-card">
    <a class="brand brand-with-mark" href="<?= e(app_url('')) ?>">
      <span class="adaptive-logo" aria-hidden="true"></span>
      <span><strong>HelloWrandell</strong><small>Secure CMS</small></span>
    </a>
    <p class="eyebrow">Account recovery</p>
    <h1>Reset access</h1>
    <?php if ($submitted): ?>
      <div class="notice success" role="status">If that email belongs to an administrator account, a secure reset link has been sent. Check your inbox and spam folder.</div>
      <p class="security-note">For privacy, this page never confirms whether an account exists. Reset links expire after <?= PASSWORD_RESET_EXPIRY_MINUTES ?> minutes and work only once.</p>
    <?php else: ?>
      <p class="muted">Enter the administrator email. We’ll send a private, single-use link to choose a new password.</p>
      <form class="stack" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Email<input required type="email" autocomplete="email" name="email" maxlength="190"></label>
        <button class="primary" type="submit">Email secure reset link</button>
      </form>
      <p class="security-note">Requests are rate-limited. The reset link contains a random token that is never stored in readable form.</p>
    <?php endif; ?>
    <a class="back-link" href="<?= e(app_url(admin_path('login.php'))) ?>">← Return to sign in</a>
  </main>
</body>
</html>

