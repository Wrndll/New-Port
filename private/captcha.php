<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CAPTCHA_TTL_SECONDS = 300;
const CAPTCHA_MAX_ACTIVE_CHALLENGES = 8;
const CAPTCHA_ISSUE_WINDOW_SECONDS = 300;
const CAPTCHA_MAX_ISSUES_PER_WINDOW = 20;

final class CaptchaValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422
    ) {
        parent::__construct($message);
    }
}

function captcha_provider(?array $settings = null): string
{
    $value = strtolower(clean_text(($settings ?? config())['captcha_provider'] ?? 'math'));
    return in_array($value, ['math', 'google'], true) ? $value : 'math';
}

function captcha_context(mixed $value): string
{
    $context = strtolower(clean_text($value));
    return in_array($context, ['contact', 'resume'], true) ? $context : 'contact';
}

function captcha_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '900');
    session_name('hello_wrandell_captcha');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (base_path() === '' ? '' : base_path()) . '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_cache_limiter('nocache');
    if (!session_start()) {
        throw new CaptchaValidationException('The verification session could not be started. Refresh and try again.', 503);
    }
}

function captcha_prune_session(int $now): void
{
    $challenges = is_array($_SESSION['captcha_challenges'] ?? null)
        ? $_SESSION['captcha_challenges']
        : [];
    $_SESSION['captcha_challenges'] = array_filter(
        $challenges,
        static fn (mixed $challenge): bool => is_array($challenge)
            && (int) ($challenge['expires_at'] ?? 0) >= $now
    );

    $issued = is_array($_SESSION['captcha_issued_at'] ?? null)
        ? $_SESSION['captcha_issued_at']
        : [];
    $_SESSION['captcha_issued_at'] = array_values(array_filter(
        $issued,
        static fn (mixed $timestamp): bool => is_int($timestamp)
            && $timestamp >= $now - CAPTCHA_ISSUE_WINDOW_SECONDS
    ));
}

function issue_math_captcha(string $context): array
{
    captcha_start_session();
    $now = time();
    captcha_prune_session($now);

    if (count($_SESSION['captcha_issued_at']) >= CAPTCHA_MAX_ISSUES_PER_WINDOW) {
        throw new CaptchaValidationException('Too many verification challenges were requested. Wait a moment and try again.', 429);
    }

    $operator = random_int(0, 2);
    if ($operator === 0) {
        $left = random_int(2, 18);
        $right = random_int(2, 18);
        $answer = $left + $right;
        $question = $left . ' + ' . $right;
    } elseif ($operator === 1) {
        $left = random_int(8, 24);
        $right = random_int(2, $left - 1);
        $answer = $left - $right;
        $question = $left . ' − ' . $right;
    } else {
        $left = random_int(2, 9);
        $right = random_int(2, 9);
        $answer = $left * $right;
        $question = $left . ' × ' . $right;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $_SESSION['captcha_challenges'][$tokenHash] = [
        'answer_hash' => hash_hmac('sha256', (string) $answer, encryption_key()),
        'context' => $context,
        'expires_at' => $now + CAPTCHA_TTL_SECONDS,
    ];
    $_SESSION['captcha_issued_at'][] = $now;

    while (count($_SESSION['captcha_challenges']) > CAPTCHA_MAX_ACTIVE_CHALLENGES) {
        array_shift($_SESSION['captcha_challenges']);
    }

    return [
        'provider' => 'math',
        'token' => $token,
        'question' => 'What is ' . $question . '?',
        'expiresIn' => CAPTCHA_TTL_SECONDS,
    ];
}

function captcha_public_configuration(string $context): array
{
    $settings = config();
    if (captcha_provider($settings) === 'math') {
        return issue_math_captcha($context);
    }

    $siteKey = clean_text($settings['recaptcha_site_key'] ?? '');
    $version = strtolower(clean_text($settings['recaptcha_version'] ?? 'v2_checkbox'));
    if (
        $siteKey === ''
        || decrypt_secret((string) ($settings['recaptcha_secret_encrypted'] ?? '')) === ''
        || !in_array($version, ['v2_checkbox', 'v3'], true)
    ) {
        throw new CaptchaValidationException('Google reCAPTCHA is not completely configured. Choose the built-in math verification or finish its settings.', 503);
    }

    return [
        'provider' => 'google',
        'version' => $version,
        'siteKey' => $siteKey,
        'action' => $context,
    ];
}

function captcha_verify_math(array $payload, string $context): void
{
    $token = clean_text($payload['captchaToken'] ?? $payload['captcha_token'] ?? '');
    $answer = clean_text($payload['captchaAnswer'] ?? $payload['captcha_answer'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || !preg_match('/^-?\d{1,3}$/', $answer)) {
        throw new CaptchaValidationException('Complete the verification question before submitting.');
    }

    captcha_start_session();
    $now = time();
    captcha_prune_session($now);
    $tokenHash = hash('sha256', $token);
    $challenge = $_SESSION['captcha_challenges'][$tokenHash] ?? null;

    // Consume before checking the answer so every challenge is single-use,
    // including failed attempts.
    unset($_SESSION['captcha_challenges'][$tokenHash]);

    if (!is_array($challenge)) {
        throw new CaptchaValidationException('The verification question expired or was already used. Request a new question and try again.');
    }
    if ((int) ($challenge['expires_at'] ?? 0) < $now) {
        throw new CaptchaValidationException('The verification question expired. Request a new question and try again.');
    }
    if (!hash_equals((string) ($challenge['context'] ?? ''), $context)) {
        throw new CaptchaValidationException('The verification question does not belong to this form. Refresh and try again.');
    }

    $submittedHash = hash_hmac('sha256', (string) ((int) $answer), encryption_key());
    if (!hash_equals((string) ($challenge['answer_hash'] ?? ''), $submittedHash)) {
        throw new CaptchaValidationException('That answer is not correct. Try a new verification question.');
    }
}

function captcha_google_request(string $secret, string $response): array
{
    $body = http_build_query([
        'secret' => $secret,
        'response' => $response,
        'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ], '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $handle = curl_init('https://www.google.com/recaptcha/api/siteverify');
        if ($handle === false) {
            throw new CaptchaValidationException('The verification service is temporarily unavailable.', 503);
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        if (!is_string($raw) || $curlError !== 0 || $httpStatus !== 200) {
            throw new CaptchaValidationException('The verification service could not be reached. Please try again.', 503);
        }
    } else {
        $streamContext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 7,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $streamContext);
        if (!is_string($raw)) {
            throw new CaptchaValidationException('The verification service could not be reached. Please try again.', 503);
        }
    }

    try {
        $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new CaptchaValidationException('The verification service returned an invalid response. Please try again.', 503);
    }
    if (!is_array($decoded)) {
        throw new CaptchaValidationException('The verification service returned an invalid response. Please try again.', 503);
    }
    return $decoded;
}

function captcha_verify_google(array $payload, string $context): void
{
    $settings = config();
    $response = clean_text(
        $payload['captchaResponse']
        ?? $payload['captcha_response']
        ?? $payload['g-recaptcha-response']
        ?? ''
    );
    if ($response === '' || strlen($response) > 4096) {
        throw new CaptchaValidationException('Complete Google reCAPTCHA before submitting.');
    }

    $secret = decrypt_secret((string) ($settings['recaptcha_secret_encrypted'] ?? ''));
    if ($secret === '') {
        throw new CaptchaValidationException('Google reCAPTCHA is not completely configured.', 503);
    }

    $result = captcha_google_request($secret, $response);
    if (($result['success'] ?? false) !== true) {
        throw new CaptchaValidationException('Google reCAPTCHA could not verify this request. Please try again.');
    }

    $configuredHost = strtolower((string) parse_url(
        clean_text($settings['site_origin'] ?? ''),
        PHP_URL_HOST
    ));
    $responseHost = strtolower(clean_text($result['hostname'] ?? ''));
    if ($configuredHost !== '' && ($responseHost === '' || !hash_equals($configuredHost, $responseHost))) {
        throw new CaptchaValidationException('Google reCAPTCHA returned an unexpected site identity.');
    }

    $version = strtolower(clean_text($settings['recaptcha_version'] ?? 'v2_checkbox'));
    if ($version === 'v3') {
        $action = clean_text($result['action'] ?? '');
        $score = filter_var($result['score'] ?? null, FILTER_VALIDATE_FLOAT);
        $minimum = max(0.1, min(1.0, (float) ($settings['recaptcha_min_score'] ?? 0.5)));
        if (!hash_equals($context, $action) || $score === false || (float) $score < $minimum) {
            throw new CaptchaValidationException('This request did not pass the spam check. Please try again.');
        }
    }
}

function verify_captcha(array $payload, string $context): void
{
    $context = captcha_context($context);
    if (captcha_provider() === 'google') {
        captcha_verify_google($payload, $context);
        return;
    }
    captcha_verify_math($payload, $context);
}
