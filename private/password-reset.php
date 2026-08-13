<?php

declare(strict_types=1);

const PASSWORD_RESET_EXPIRY_MINUTES = 30;
const PASSWORD_RESET_REQUEST_WINDOW = 3600;
const PASSWORD_RESET_REQUESTS_PER_IP = 5;
const PASSWORD_RESET_REQUESTS_PER_ACCOUNT = 3;
const PASSWORD_RESET_ATTEMPT_WINDOW = 900;
const PASSWORD_RESET_ATTEMPTS_PER_IP = 10;
const PASSWORD_RESET_ATTEMPTS_PER_TOKEN = 5;

function ensure_password_reset_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    // The table is installed through private/schema.sql. Public recovery
    // requests only verify availability and never require runtime DDL rights.
    $schemaCheck = db()->query('SELECT 1 FROM password_reset_tokens LIMIT 1');
    $schemaCheck->closeCursor();
    $ready = true;

    if (random_int(1, 20) === 1) {
        db()->exec(
            'DELETE FROM password_reset_tokens
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
        db()->exec(
            'DELETE FROM rate_limits
             WHERE scope_name LIKE "password_reset_%"
               AND attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        );
    }
}

function password_reset_token_is_well_formed(mixed $token): bool
{
    return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
}

function password_reset_user_agent_hash(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
}

function password_reset_rate_limit_permits(
    string $scope,
    string $keyHash,
    int $windowSeconds,
    int $maximum
): bool {
    return rate_limit_permits($scope, $keyHash, $windowSeconds, $maximum);
}

function password_reset_find_active(string $token): ?array
{
    if (!password_reset_token_is_well_formed($token)) {
        return null;
    }

    ensure_password_reset_schema();
    $query = db()->prepare(
        'SELECT reset_tokens.id, reset_tokens.admin_id, admins.email, admins.password_hash
         FROM password_reset_tokens AS reset_tokens
         INNER JOIN admins ON admins.id = reset_tokens.admin_id
         WHERE reset_tokens.token_hash = ?
           AND reset_tokens.used_at IS NULL
           AND reset_tokens.expires_at > NOW()
         LIMIT 1'
    );
    $query->execute([hash('sha256', $token)]);
    $record = $query->fetch();
    return is_array($record) ? $record : null;
}

function password_reset_send_email(string $recipient, string $resetUrl): bool
{
    $safeUrl = e($resetUrl);
    $boundary = '=_HelloWrandell_Reset_' . bin2hex(random_bytes(12));
    $plain = implode("\r\n", [
        'Hello,',
        '',
        'A password reset was requested for your HelloWrandell CMS account.',
        'Use this secure link within ' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes:',
        '',
        $resetUrl,
        '',
        'The link works once. If you did not request this change, ignore this email.',
        '',
        'HelloWrandell Secure CMS',
    ]);
    $html = '<!doctype html><html><body style="margin:0;background:#f3ebe2;color:#30302f;font-family:Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:36px 16px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:auto;background:#fcfaf6;border:1px solid #ded4c9;border-radius:24px;overflow:hidden">'
        . '<tr><td style="padding:28px 34px;background:#30302f;color:#f8f3ea">'
        . '<div style="font-size:12px;letter-spacing:2px;font-weight:bold;color:#d8a487">HELLOWRANDELL SECURE CMS</div>'
        . '<h1 style="margin:12px 0 0;font-family:Georgia,serif;font-size:32px;font-weight:normal">Reset your password</h1>'
        . '</td></tr><tr><td style="padding:34px;font-size:16px;line-height:1.65">'
        . '<p style="margin-top:0">A password reset was requested for your administrator account.</p>'
        . '<p>This single-use link expires in <strong>' . PASSWORD_RESET_EXPIRY_MINUTES . ' minutes</strong>.</p>'
        . '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="display:inline-block;padding:14px 22px;border-radius:999px;background:#30302f;color:#f8f3ea;text-decoration:none;font-weight:bold">Choose a new password</a></p>'
        . '<p style="font-size:13px;color:#706861;word-break:break-all">Or copy this address:<br>' . $safeUrl . '</p>'
        . '<p style="margin-bottom:0;color:#706861">If you did not request this change, ignore this email. Your current password remains unchanged.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $body = implode("\r\n", [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $plain,
        '',
        '--' . $boundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $html,
        '',
        '--' . $boundary . '--',
    ]);

    return send_raw_message(
        config(),
        $recipient,
        'Reset your HelloWrandell CMS password',
        $body,
        ['Content-Type: multipart/alternative; boundary="' . $boundary . '"']
    );
}

function password_reset_audit(
    string $action,
    string $adminId = '',
    array $details = []
): void {
    try {
        audit_log($action, 'password_reset', $adminId, $details);
    } catch (Throwable $exception) {
        operational_log('password-reset-audit', $exception);
    }
}
