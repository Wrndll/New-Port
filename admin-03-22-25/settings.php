<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/admin-layout.php';
require_once __DIR__ . '/../private/captcha.php';

$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = clean_text($_POST['action'] ?? '');
    try {
        if ($action === 'account') {
            require_current_admin_password($_POST['current_password'] ?? '');
            $email = strtolower(clean_text($_POST['email'] ?? ''));
            $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid administrator email.');
            }
            if ($newPassword !== '') {
                validate_strong_password($newPassword);
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = db()->prepare('UPDATE admins SET email = ?, password_hash = ? WHERE id = ?');
                $update->execute([$email, $newPasswordHash, (int) $admin['id']]);
                establish_admin_session((int) $admin['id']);
            } else {
                $update = db()->prepare('UPDATE admins SET email = ? WHERE id = ?');
                $update->execute([$email, (int) $admin['id']]);
            }
            audit_log('updated', 'admin_account', (string) $admin['id']);
            set_flash('success', 'Administrator account updated securely.');
        } elseif ($action === 'delivery') {
            require_current_admin_password($_POST['current_password'] ?? '');
            $mailFromName = clean_text($_POST['mail_from_name'] ?? 'Wrandell Almeda Portfolio');
            $mailFrom = strtolower(clean_text($_POST['mail_from'] ?? ''));
            $mailRecipient = strtolower(clean_text($_POST['mail_recipient'] ?? ''));
            $siteOrigin = rtrim(clean_text($_POST['site_origin'] ?? ''), '/');
            $smtpHost = strtolower(clean_text($_POST['smtp_host'] ?? ''));
            $smtpPort = filter_var($_POST['smtp_port'] ?? null, FILTER_VALIDATE_INT);
            $smtpEncryption = strtolower(clean_text($_POST['smtp_encryption'] ?? 'tls'));
            $smtpUsername = strtolower(clean_text($_POST['smtp_username'] ?? ''));
            $newAppPassword = is_string($_POST['smtp_app_password'] ?? null) ? trim($_POST['smtp_app_password']) : '';
            $deliveryAction = clean_text($_POST['delivery_action'] ?? 'save');
            if ($mailFromName === '' || text_length($mailFromName) > 100) {
                throw new RuntimeException('Enter a sender name under 100 characters.');
            }
            if (
                filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false
                || filter_var($mailRecipient, FILTER_VALIDATE_EMAIL) === false
                || filter_var($smtpUsername, FILTER_VALIDATE_EMAIL) === false
            ) {
                throw new RuntimeException('Enter valid sender, notification, and SMTP account email addresses.');
            }
            if ($smtpHost === '' || !preg_match('/^[a-z0-9.-]{1,253}$/', $smtpHost)) {
                throw new RuntimeException('Enter a valid SMTP host such as smtp.gmail.com.');
            }
            if ($smtpPort === false || $smtpPort < 1 || $smtpPort > 65535) {
                throw new RuntimeException('Enter a valid SMTP port.');
            }
            if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
                throw new RuntimeException('Choose a supported SMTP encryption mode.');
            }
            if ($smtpHost === 'smtp.gmail.com' && $newAppPassword !== '') {
                $newAppPassword = preg_replace('/\s+/', '', $newAppPassword) ?? '';
                if (!preg_match('/^[A-Za-z0-9]{16}$/', $newAppPassword)) {
                    throw new RuntimeException('Enter the complete 16-character Google App Password. Spaces are removed automatically.');
                }
            }
            if (!site_origin_is_valid($siteOrigin)) {
                throw new RuntimeException('Enter an origin only, such as http://localhost or https://portfolio.example.com.');
            }

            $settings = config();
            $existingEncryptedPassword = (string) ($settings['smtp_password_encrypted'] ?? '');
            if ($newAppPassword === '' && $existingEncryptedPassword === '') {
                throw new RuntimeException('Enter the SMTP app password the first time you configure delivery.');
            }
            if ($smtpHost === 'smtp.gmail.com' && $newAppPassword === '') {
                $storedAppPassword = preg_replace('/\s+/', '', decrypt_secret($existingEncryptedPassword)) ?? '';
                if (!preg_match('/^[A-Za-z0-9]{16}$/', $storedAppPassword)) {
                    throw new RuntimeException('The stored Gmail credential is incomplete. Enter a new 16-character Google App Password.');
                }
            }
            $settings['site_origin'] = $siteOrigin;
            $settings['mail_from_name'] = $mailFromName;
            $settings['mail_from'] = $mailFrom;
            $settings['mail_recipient'] = $mailRecipient;
            $settings['smtp_host'] = $smtpHost;
            $settings['smtp_port'] = (int) $smtpPort;
            $settings['smtp_encryption'] = $smtpEncryption;
            $settings['smtp_username'] = $smtpUsername;
            if ($newAppPassword !== '') {
                if (strlen($newAppPassword) > 512) {
                    throw new RuntimeException('The SMTP app password is too long.');
                }
                $settings['smtp_password_encrypted'] = encrypt_secret($newAppPassword);
            }
            write_config($settings);

            if ($deliveryAction === 'test') {
                $testSettings = config(true);
                $sent = send_text_mail(
                    $mailRecipient,
                    'HelloWrandell SMTP test',
                    "Hello,\n\nThis test confirms that the HelloWrandell CMS can send email through the configured SMTP account.\n\nTime: " . date('Y-m-d H:i:s T'),
                    null,
                    $testSettings
                );
                $testSettings['mail_last_test_at'] = date('Y-m-d H:i:s');
                $testSettings['mail_last_test_status'] = $sent ? 'Passed' : 'Failed';
                write_config($testSettings);
                audit_log($sent ? 'tested_successfully' : 'test_failed', 'smtp_delivery');
                if (!$sent) {
                    throw new RuntimeException('The SMTP test failed. Confirm the host, port, encryption, email, and app password.');
                }
                set_flash('success', 'Delivery settings saved and the SMTP test email was sent.');
            } else {
                audit_log('updated', 'delivery_settings');
                set_flash('success', 'SMTP, trusted origin, and notification settings saved securely.');
            }
        } elseif ($action === 'captcha') {
            require_current_admin_password($_POST['current_password'] ?? '');
            $provider = strtolower(clean_text($_POST['captcha_provider'] ?? 'math'));
            $version = strtolower(clean_text($_POST['recaptcha_version'] ?? 'v2_checkbox'));
            $siteKey = clean_text($_POST['recaptcha_site_key'] ?? '');
            $newSecret = is_string($_POST['recaptcha_secret'] ?? null)
                ? trim($_POST['recaptcha_secret'])
                : '';
            $minimumScore = filter_var($_POST['recaptcha_min_score'] ?? 0.5, FILTER_VALIDATE_FLOAT);

            if (!in_array($provider, ['math', 'google'], true)) {
                throw new RuntimeException('Choose the built-in math challenge or Google reCAPTCHA.');
            }
            if (!in_array($version, ['v2_checkbox', 'v3'], true)) {
                throw new RuntimeException('Choose a supported Google reCAPTCHA version.');
            }
            if ($minimumScore === false || $minimumScore < 0.1 || $minimumScore > 1.0) {
                throw new RuntimeException('The reCAPTCHA v3 minimum score must be between 0.1 and 1.0.');
            }
            if ($siteKey !== '' && !preg_match('/^[A-Za-z0-9_-]{20,200}$/', $siteKey)) {
                throw new RuntimeException('Enter a valid Google reCAPTCHA site key.');
            }
            if ($newSecret !== '' && !preg_match('/^[A-Za-z0-9_-]{20,200}$/', $newSecret)) {
                throw new RuntimeException('Enter a valid Google reCAPTCHA secret key.');
            }

            $settings = config();
            $existingSecret = (string) ($settings['recaptcha_secret_encrypted'] ?? '');
            $storedSecretAvailable = $existingSecret !== '' && decrypt_secret($existingSecret) !== '';
            if ($provider === 'google' && ($siteKey === '' || ($newSecret === '' && !$storedSecretAvailable))) {
                throw new RuntimeException('Add both the Google reCAPTCHA site key and secret before enabling it.');
            }

            $settings['captcha_provider'] = $provider;
            $settings['recaptcha_version'] = $version;
            $settings['recaptcha_site_key'] = $siteKey;
            $settings['recaptcha_min_score'] = round((float) $minimumScore, 2);
            if ($newSecret !== '') {
                $settings['recaptcha_secret_encrypted'] = encrypt_secret($newSecret);
            }
            write_config($settings);
            audit_log('updated', 'captcha_settings', '', [
                'provider' => $provider,
                'version' => $provider === 'google' ? $version : 'built_in_math',
            ]);
            set_flash(
                'success',
                $provider === 'math'
                    ? 'Built-in one-time math verification is now active.'
                    : 'Google reCAPTCHA settings were saved securely.'
            );
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception instanceof PDOException ? 'Settings could not be updated.' : $exception->getMessage());
    }
    redirect_to(admin_path('settings.php'));
}

$settings = config();
$siteOrigin = clean_text($settings['site_origin'] ?? 'http://localhost');
$smtpPasswordConfigured = (string) ($settings['smtp_password_encrypted'] ?? '') !== '';
$storedSmtpPassword = $smtpPasswordConfigured
    ? (preg_replace('/\s+/', '', decrypt_secret((string) $settings['smtp_password_encrypted'])) ?? '')
    : '';
$smtpCredentialValid = ($settings['smtp_host'] ?? '') !== 'smtp.gmail.com'
    ? $smtpPasswordConfigured
    : (bool) preg_match('/^[A-Za-z0-9]{16}$/', $storedSmtpPassword);
$lastMailTestAt = clean_text($settings['mail_last_test_at'] ?? '');
$lastMailTestStatus = clean_text($settings['mail_last_test_status'] ?? 'Not tested');
$captchaProvider = captcha_provider($settings);
$recaptchaVersion = strtolower(clean_text($settings['recaptcha_version'] ?? 'v2_checkbox'));
if (!in_array($recaptchaVersion, ['v2_checkbox', 'v3'], true)) {
    $recaptchaVersion = 'v2_checkbox';
}
$recaptchaSiteKey = clean_text($settings['recaptcha_site_key'] ?? '');
$recaptchaSecretConfigured = (string) ($settings['recaptcha_secret_encrypted'] ?? '') !== '';
$recaptchaMinimumScore = max(0.1, min(1.0, (float) ($settings['recaptcha_min_score'] ?? 0.5)));
$audit = db()->query(
    'SELECT a.created_at, a.action_name, a.entity_type, a.entity_id, admins.email
     FROM audit_logs a LEFT JOIN admins ON admins.id = a.admin_id
     ORDER BY a.created_at DESC LIMIT 30'
)->fetchAll();
$failedLogins = (int) db()->query(
    'SELECT COUNT(*) FROM login_attempts
     WHERE successful = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
)->fetchColumn();
$lastLoginQuery = db()->prepare('SELECT last_login_at FROM admins WHERE id = ?');
$lastLoginQuery->execute([(int) $admin['id']]);
$lastLogin = $lastLoginQuery->fetchColumn();
$session = admin_session_summary();

$contentApiHealth = [
    'status' => 'Passed',
    'message' => 'The database, public content records, and published project query are available.',
    'content_rows' => 0,
    'published_projects' => 0,
    'request_id' => '',
];
try {
    $contentApiHealth['content_rows'] = (int) db()->query('SELECT COUNT(*) FROM site_content')->fetchColumn();
    $contentApiHealth['published_projects'] = (int) db()->query('SELECT COUNT(*) FROM projects WHERE published = 1')->fetchColumn();
    $sample = content_value('profile', []);
    json_encode($sample, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    $contentApiHealth['status'] = 'Failed';
    $contentApiHealth['message'] = 'The public site is using its static fallback. Review the private request ID before changing the database.';
    $contentApiHealth['request_id'] = operational_log('content-health', $exception);
}

admin_header('Settings & security', 'settings');
?>
<section class="settings-grid">
  <section class="card settings-card">
    <div class="settings-card__head">
      <span class="settings-icon"><?php admin_icon('lock', 20); ?></span>
      <div><span class="card-kicker">Identity</span><h2>Administrator account</h2></div>
    </div>
    <p class="muted">Your current password is required before any account change is accepted.</p>
    <form method="post" class="stack" data-dirty-form>
      <?php csrf_field(); ?><input type="hidden" name="action" value="account">
      <label>Admin email<input required type="email" name="email" value="<?= e($admin['email']) ?>"></label>
      <label>Current password<input required type="password" autocomplete="current-password" name="current_password"></label>
      <label>New password<input type="password" minlength="14" maxlength="4096" autocomplete="new-password" name="new_password"><small>Leave blank to keep the existing password. Use at least 14 characters with uppercase, lowercase, number, and symbol.</small></label>
      <button class="primary" type="submit">Update account securely</button>
    </form>
  </section>

  <section class="card settings-card settings-card--wide">
    <div class="settings-card__head">
      <span class="settings-icon"><?php admin_icon('external', 20); ?></span>
      <div><span class="card-kicker">Email delivery</span><h2>SMTP and notifications</h2></div>
    </div>
    <p class="muted">Configure an SMTP account and app password for contact notifications and verified Resume delivery. The app password is encrypted and never displayed again.</p>
    <div class="mail-status <?= strtolower($lastMailTestStatus) === 'passed' ? 'is-passed' : '' ?>">
      <span>SMTP credential: <strong><?= !$smtpPasswordConfigured ? 'Not configured' : ($smtpCredentialValid ? 'Stored securely' : 'Needs replacement') ?></strong></span>
      <span>Last test: <strong><?= e($lastMailTestStatus) ?></strong><?= $lastMailTestAt !== '' ? ' · ' . e($lastMailTestAt) : '' ?></span>
    </div>
    <form method="post" class="stack" data-dirty-form autocomplete="off">
      <?php csrf_field(); ?><input type="hidden" name="action" value="delivery">
      <div class="form-grid">
        <label class="wide">Trusted portfolio origin<input required type="url" name="site_origin" value="<?= e($siteOrigin) ?>"><small>Use http://localhost for local XAMPP. Use HTTPS before public deployment.</small></label>
        <label>SMTP host<input required name="smtp_host" maxlength="253" value="<?= e($settings['smtp_host'] ?? 'smtp.gmail.com') ?>"></label>
        <label>SMTP port<input required type="number" min="1" max="65535" name="smtp_port" value="<?= e($settings['smtp_port'] ?? 587) ?>"></label>
        <label>Encryption<select name="smtp_encryption"><option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS</option><option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (local testing only)</option></select></label>
        <label>SMTP username / email<input required type="email" autocomplete="username" name="smtp_username" value="<?= e($settings['smtp_username'] ?? $settings['mail_from'] ?? '') ?>"></label>
        <label>Sender name<input required maxlength="100" name="mail_from_name" value="<?= e($settings['mail_from_name'] ?? 'Wrandell Almeda Portfolio') ?>"></label>
        <label>Verified sender address<input required type="email" name="mail_from" value="<?= e($settings['mail_from'] ?? '') ?>"></label>
        <label>Contact notification recipient<input required type="email" name="mail_recipient" value="<?= e($settings['mail_recipient'] ?? '') ?>"></label>
        <label>SMTP app password<input type="password" name="smtp_app_password" autocomplete="new-password" maxlength="512" placeholder="<?= $smtpPasswordConfigured ? 'Leave blank to keep the stored app password' : 'Enter the app password' ?>"><small><?= $smtpPasswordConfigured ? 'A protected credential is already stored. Enter a value only to replace it.' : 'For Gmail, use a Google App Password rather than your normal account password.' ?></small></label>
        <label>Current administrator password<input required type="password" autocomplete="current-password" name="current_password"><small>Step-up verification protects email credentials and delivery settings.</small></label>
      </div>
      <div class="settings-actions">
        <button class="secondary" type="submit" name="delivery_action" value="save">Save email settings</button>
        <button class="primary" type="submit" name="delivery_action" value="test">Save and send test email</button>
      </div>
    </form>
  </section>

  <section class="card settings-card settings-card--wide" id="captcha-settings">
    <div class="settings-card__head">
      <span class="settings-icon"><?php admin_icon('shield', 20); ?></span>
      <div><span class="card-kicker">Spam protection</span><h2>Human verification</h2></div>
    </div>
    <p class="muted">The built-in option presents an accessible one-time math question and needs no external account. Google keys are encrypted or safely separated: the public site key may be displayed, but the secret is never shown again.</p>
    <div class="mail-status <?= $captchaProvider === 'math' || $recaptchaSecretConfigured ? 'is-passed' : '' ?>">
      <span>Active provider: <strong><?= $captchaProvider === 'math' ? 'Built-in math challenge' : 'Google reCAPTCHA' ?></strong></span>
      <span>Google secret: <strong><?= $recaptchaSecretConfigured ? 'Stored securely' : 'Not configured' ?></strong></span>
    </div>
    <form method="post" class="stack" data-dirty-form autocomplete="off">
      <?php csrf_field(); ?><input type="hidden" name="action" value="captcha">
      <div class="form-grid">
        <label>Verification provider
          <select name="captcha_provider">
            <option value="math" <?= $captchaProvider === 'math' ? 'selected' : '' ?>>Built-in one-time math challenge</option>
            <option value="google" <?= $captchaProvider === 'google' ? 'selected' : '' ?>>Google reCAPTCHA</option>
          </select>
          <small>Math is the secure default and works without third-party scripts or keys.</small>
        </label>
        <label>Google reCAPTCHA version
          <select name="recaptcha_version">
            <option value="v2_checkbox" <?= $recaptchaVersion === 'v2_checkbox' ? 'selected' : '' ?>>v2 visible checkbox</option>
            <option value="v3" <?= $recaptchaVersion === 'v3' ? 'selected' : '' ?>>v3 score-based</option>
          </select>
        </label>
        <label class="wide">Google site key<input name="recaptcha_site_key" maxlength="200" value="<?= e($recaptchaSiteKey) ?>" autocomplete="off" placeholder="Public site key"><small>This key is intentionally public and is used by the portfolio form.</small></label>
        <label>Google secret key<input type="password" name="recaptcha_secret" maxlength="200" autocomplete="new-password" placeholder="<?= $recaptchaSecretConfigured ? 'Leave blank to keep the stored secret' : 'Enter secret key' ?>"><small><?= $recaptchaSecretConfigured ? 'A protected secret is already stored. Enter a value only to replace it.' : 'Required only when Google reCAPTCHA is selected.' ?></small></label>
        <label>v3 minimum score<input type="number" name="recaptcha_min_score" min="0.1" max="1" step="0.1" value="<?= e(number_format($recaptchaMinimumScore, 1, '.', '')) ?>"><small>0.5 is a balanced default. This setting is ignored for v2 and math.</small></label>
        <label class="wide">Current administrator password<input required type="password" autocomplete="current-password" name="current_password"><small>Step-up verification protects provider selection and the encrypted Google secret.</small></label>
      </div>
      <div class="settings-actions">
        <button class="primary" type="submit">Save spam protection</button>
      </div>
    </form>
  </section>

  <section class="card settings-card settings-card--wide api-health-card">
    <div class="settings-card__head">
      <span class="settings-icon"><?php admin_icon('activity', 20); ?></span>
      <div><span class="card-kicker">Public website</span><h2>Content API health</h2></div>
    </div>
    <div class="api-health-summary <?= strtolower($contentApiHealth['status']) === 'passed' ? 'is-passed' : 'is-failed' ?>">
      <span><i aria-hidden="true"></i><strong><?= e($contentApiHealth['status']) ?></strong></span>
      <p><?= e($contentApiHealth['message']) ?></p>
    </div>
    <dl class="health-metrics">
      <div><dt>Content records</dt><dd><?= (int) $contentApiHealth['content_rows'] ?></dd></div>
      <div><dt>Published projects</dt><dd><?= (int) $contentApiHealth['published_projects'] ?></dd></div>
      <div><dt>Frontend mode</dt><dd><?= strtolower($contentApiHealth['status']) === 'passed' ? 'CMS content' : 'Static fallback' ?></dd></div>
      <?php if ($contentApiHealth['request_id'] !== ''): ?><div><dt>Private request ID</dt><dd><code><?= e($contentApiHealth['request_id']) ?></code></dd></div><?php endif; ?>
    </dl>
    <p class="muted">The public page remains online with verified static content if the CMS database is temporarily unavailable. Detailed errors are written only to the protected private log directory.</p>
  </section>
</section>

<section class="security-overview" id="security">
  <div class="security-overview__copy">
    <span class="settings-icon"><?php admin_icon('shield', 22); ?></span>
    <div><span class="card-kicker">Security process</span><h2>Layered administrator protection</h2><p>Authentication, session controls, request validation, encrypted delivery credentials, upload isolation, and audit visibility work together.</p></div>
  </div>
  <div class="security-metrics">
    <div><span>Idle auto-lock</span><strong><?= (int) $session['idle_minutes'] ?> minutes</strong><small>Activity resets the timer</small></div>
    <div><span>Session remaining</span><strong><?= e((string) $session['absolute_hours']) ?> hours</strong><small>Fresh sign-in required at expiry</small></div>
    <div><span>Failed sign-ins</span><strong><?= $failedLogins ?></strong><small>During the last 24 hours</small></div>
    <div><span>Transport</span><strong><?= $session['https'] ? 'HTTPS' : 'Local HTTP' ?></strong><small><?= $session['https'] ? 'Secure cookies active' : 'Upgrade before public use' ?></small></div>
  </div>
  <p class="security-last-login"><?php admin_icon('activity', 16); ?> Last successful sign-in: <?= e(is_string($lastLogin) ? $lastLogin : 'Current first session') ?></p>
</section>

<section class="card table-wrap" id="activity">
  <div class="card-head"><div><span class="card-kicker">Accountability</span><h2>Recent CMS activity</h2></div><span class="badge">Last 30 events</span></div>
  <table class="data-table">
    <thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Item</th></tr></thead>
    <tbody>
      <?php if (!$audit): ?><tr><td colspan="4" class="empty">No recorded changes yet.</td></tr><?php endif; ?>
      <?php foreach ($audit as $entry): ?>
        <tr><td><?= e($entry['created_at']) ?></td><td><?= e($entry['email'] ?? 'System') ?></td><td><span class="activity-action"><?= e(str_replace('_', ' ', $entry['action_name'])) ?></span></td><td><?= e($entry['entity_type']) ?> <?= e($entry['entity_id']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php admin_footer(); ?>
