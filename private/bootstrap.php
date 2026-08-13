<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const CONFIG_PATH = __DIR__ . '/config.local.php';
const RESUME_PATH = __DIR__ . '/resume/wrandell-almeda-resume.pdf';
const ADMIN_IDLE_TIMEOUT = 1800;
const ADMIN_ABSOLUTE_TIMEOUT = 28800;
const ADMIN_ROTATION_INTERVAL = 900;
const ADMIN_PATH = 'admin-03-22-25';
const SETUP_ACCESS_TIMEOUT = 600;
const SETUP_ACCESS_WINDOW = 900;
const SETUP_ACCESS_MAX_ATTEMPTS = 5;
const SETUP_ACCESS_HASH_PATH = __DIR__ . '/setup-access.php';
const SECRET_KEY_PATH = __DIR__ . '/secret.key';
require_once __DIR__ . '/smtp-mailer.php';

function admin_path(string $path = ''): string
{
    $clean = ltrim($path, '/');
    return ADMIN_PATH . ($clean === '' ? '/' : '/' . $clean);
}

function setup_access_hash(): string
{
    if (!is_file(SETUP_ACCESS_HASH_PATH)) {
        throw new RuntimeException('The setup access configuration is missing.');
    }
    $hash = require SETUP_ACCESS_HASH_PATH;
    if (!is_string($hash) || !str_starts_with($hash, '$2y$')) {
        throw new RuntimeException('The setup access configuration is invalid.');
    }
    return $hash;
}

function setup_access_fingerprint(): string
{
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    return hash('sha256', $agent);
}

function setup_access_granted(): bool
{
    start_secure_session();
    $grantedAt = filter_var($_SESSION['setup_access_granted_at'] ?? null, FILTER_VALIDATE_INT);
    $fingerprint = $_SESSION['setup_access_fingerprint'] ?? '';
    if ($grantedAt === false || !is_string($fingerprint)) {
        return false;
    }
    $valid = time() - (int) $grantedAt <= SETUP_ACCESS_TIMEOUT
        && hash_equals($fingerprint, setup_access_fingerprint());
    if (!$valid) {
        unset($_SESSION['setup_access_granted_at'], $_SESSION['setup_access_fingerprint']);
    }
    return $valid;
}

function grant_setup_access(): void
{
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['setup_access_granted_at'] = time();
    $_SESSION['setup_access_fingerprint'] = setup_access_fingerprint();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function setup_rate_limit_path(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'hello_wrandell_setup_'
        . hash('sha256', $ip)
        . '.json';
}

function setup_rate_limit_state(): array
{
    $path = setup_rate_limit_path();
    if (!is_file($path)) {
        return ['attempts' => [], 'locked_until' => 0];
    }
    $raw = file_get_contents($path);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        return ['attempts' => [], 'locked_until' => 0];
    }
    $cutoff = time() - SETUP_ACCESS_WINDOW;
    $attempts = array_values(array_filter(
        is_array($state['attempts'] ?? null) ? $state['attempts'] : [],
        static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $cutoff
    ));
    return [
        'attempts' => $attempts,
        'locked_until' => max(0, (int) ($state['locked_until'] ?? 0)),
    ];
}

function write_setup_rate_limit_state(array $state): void
{
    $path = setup_rate_limit_path();
    $body = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($path, $body, LOCK_EX);
    @chmod($path, 0600);
}

function setup_lock_remaining(): int
{
    $state = setup_rate_limit_state();
    return max(0, (int) $state['locked_until'] - time());
}

function record_setup_access_failure(): int
{
    $state = setup_rate_limit_state();
    $state['attempts'][] = time();
    if (count($state['attempts']) >= SETUP_ACCESS_MAX_ATTEMPTS) {
        $state['locked_until'] = time() + SETUP_ACCESS_WINDOW;
        $state['attempts'] = [];
    }
    write_setup_rate_limit_state($state);
    return max(0, (int) $state['locked_until'] - time());
}

function clear_setup_access_failures(): void
{
    $path = setup_rate_limit_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function verify_setup_access_password(mixed $value): bool
{
    $password = is_string($value) ? $value : '';
    return $password !== '' && password_verify($password, setup_access_hash());
}

function validate_strong_password(string $password): void
{
    if (strlen($password) < 14 || strlen($password) > 4096) {
        throw new RuntimeException('Use a password containing between 14 and 4,096 characters.');
    }
    if (
        !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        throw new RuntimeException('Use uppercase, lowercase, number, and symbol characters in the password.');
    }
}

function is_configured(): bool
{
    return is_file(CONFIG_PATH);
}

function require_cms_configuration(): void
{
    if (!is_configured()) {
        redirect_to(admin_path('setup.php'));
    }
}

function redirect_configured_cms_away_from_setup(): void
{
    if (is_configured()) {
        redirect_to(admin_path('login.php'));
    }
}

function config(bool $refresh = false): array
{
    static $configuration;
    if ($refresh) {
        $configuration = null;
    }
    if (is_array($configuration)) {
        return $configuration;
    }
    if (!is_configured()) {
        throw new RuntimeException('The CMS has not been configured.');
    }
    $value = require CONFIG_PATH;
    if (!is_array($value)) {
        throw new RuntimeException('The CMS configuration is invalid.');
    }
    $configuration = $value;
    return $configuration;
}


function write_config(array $settings): void
{
    $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($settings, true) . ";\n";
    $temporaryPath = CONFIG_PATH . '.tmp';
    if (file_put_contents($temporaryPath, $body, LOCK_EX) === false) {
        @unlink($temporaryPath);
        throw new RuntimeException('The configuration could not be saved.');
    }
    if (!@rename($temporaryPath, CONFIG_PATH)) {
        // Windows may refuse to replace an existing file with rename().
        if (is_file(CONFIG_PATH) && !@unlink(CONFIG_PATH)) {
            @unlink($temporaryPath);
            throw new RuntimeException('The configuration could not be replaced.');
        }
        if (!@rename($temporaryPath, CONFIG_PATH)) {
            @unlink($temporaryPath);
            throw new RuntimeException('The configuration could not be saved.');
        }
    }
    @chmod(CONFIG_PATH, 0600);
    config(true);
}

function encryption_key(): string
{
    if (is_file(SECRET_KEY_PATH)) {
        $raw = trim((string) file_get_contents(SECRET_KEY_PATH));
        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }
        throw new RuntimeException('The private encryption key is invalid.');
    }
    $key = random_bytes(32);
    if (file_put_contents(SECRET_KEY_PATH, base64_encode($key), LOCK_EX) === false) {
        throw new RuntimeException('The private encryption key could not be created.');
    }
    @chmod(SECRET_KEY_PATH, 0600);
    return $key;
}

function encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required to protect SMTP credentials.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('The SMTP credential could not be encrypted.');
    }
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decrypt_secret(string $encrypted): string
{
    if ($encrypted === '') {
        return '';
    }
    if (!str_starts_with($encrypted, 'v1:') || !function_exists('openssl_decrypt')) {
        return '';
    }
    $raw = base64_decode(substr($encrypted, 3), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plaintext) ? $plaintext : '';
}

function smtp_settings(array $settings): array
{
    $settings['smtp_password'] = decrypt_secret((string) ($settings['smtp_password_encrypted'] ?? ''));
    return $settings;
}

function safe_image_upload(array $upload, string $directory, string $prefix, int $maxWidth = 2200, int $maxHeight = 2200): string
{
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image upload did not complete.');
    }
    if ((int) ($upload['size'] ?? 0) < 1 || (int) $upload['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Images must be smaller than 5 MB.');
    }
    $temporaryName = (string) ($upload['tmp_name'] ?? '');
    if ($temporaryName === '' || !is_uploaded_file($temporaryName)) {
        throw new RuntimeException('The uploaded image could not be verified.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryName);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new RuntimeException('Upload a JPEG, PNG, or WebP image.');
    }
    $details = @getimagesize($temporaryName);
    $width = is_array($details) ? (int) ($details[0] ?? 0) : 0;
    $height = is_array($details) ? (int) ($details[1] ?? 0) : 0;
    if ($width < 120 || $height < 120 || $width > 8000 || $height > 8000 || $width * $height > 36000000) {
        throw new RuntimeException('Images must be between 120×120 and 8,000×8,000 pixels and below 36 megapixels.');
    }
    $targetDirectory = APP_ROOT . '/uploads/' . trim($directory, '/');
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('The upload directory could not be created.');
    }
    $safePrefix = preg_replace('/[^a-z0-9-]+/', '-', strtolower($prefix)) ?: 'image';
    $base = $safePrefix . '-' . bin2hex(random_bytes(8));

    $canOptimize = function_exists('imagecreatetruecolor') && function_exists('imagewebp');
    if ($canOptimize) {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($temporaryName),
            'image/png' => @imagecreatefrompng($temporaryName),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($temporaryName) : false,
            default => false,
        };
        if ($source !== false) {
            $scale = min(1, $maxWidth / $width, $maxHeight / $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $filename = $base . '.webp';
            $destination = $targetDirectory . '/' . $filename;
            $written = imagewebp($canvas, $destination, 84);
            imagedestroy($canvas);
            imagedestroy($source);
            if ($written) {
                @chmod($destination, 0644);
                return '/uploads/' . trim($directory, '/') . '/' . $filename;
            }
        }
    }

    $filename = $base . '.' . $allowed[$mime];
    $destination = $targetDirectory . '/' . $filename;
    if (!move_uploaded_file($temporaryName, $destination)) {
        throw new RuntimeException('The image could not be saved.');
    }
    @chmod($destination, 0644);
    return '/uploads/' . trim($directory, '/') . '/' . $filename;
}

function delete_uploaded_asset(string $path, string $directory): void
{
    $prefix = '/uploads/' . trim($directory, '/') . '/';
    if (!str_starts_with($path, $prefix)) {
        return;
    }
    $basename = basename($path);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return;
    }
    $target = APP_ROOT . $prefix . $basename;
    if (is_file($target)) {
        @unlink($target);
    }
}

function operational_log(string $channel, Throwable $exception): string
{
    $requestId = bin2hex(random_bytes(6));
    $directory = __DIR__ . '/logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    $safeChannel = preg_replace('/[^a-z0-9-]+/', '-', strtolower($channel)) ?: 'application';
    $entry = sprintf(
        "[%s] request=%s type=%s message=%s file=%s line=%d\n",
        date('c'),
        $requestId,
        get_class($exception),
        str_replace(["\r", "\n"], ' ', $exception->getMessage()),
        basename($exception->getFile()),
        $exception->getLine()
    );
    @file_put_contents($directory . '/' . $safeChannel . '.log', $entry, FILE_APPEND | LOCK_EX);
    return $requestId;
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $settings = config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $settings['db_host'],
        (int) $settings['db_port'],
        $settings['db_name']
    );
    $connection = new PDO($dsn, $settings['db_user'], $settings['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $connection;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.sid_length', '64');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', (string) ADMIN_ABSOLUTE_TIMEOUT);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('hello_wrandell_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_path() . '/' . ADMIN_PATH,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_cache_limiter('nocache');
    session_start();
}

function is_https_request(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function admin_security_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header(
        "Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; "
        . "font-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'"
    );
    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function base_path(): string
{
    if (is_configured()) {
        $value = (string) (config()['base_path'] ?? '/HelloWrandell');
    } else {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $marker = strpos($script, '/' . ADMIN_PATH . '/');
        $value = $marker === false ? '/HelloWrandell' : substr($script, 0, $marker);
    }
    $value = '/' . trim($value, '/');
    return $value === '/' ? '' : $value;
}

function app_url(string $path = ''): string
{
    $normalized = ltrim($path, '/');
    if ($normalized === 'admin') {
        $normalized = ADMIN_PATH;
    } elseif (str_starts_with($normalized, 'admin/')) {
        $normalized = ADMIN_PATH . substr($normalized, 5);
    }
    return base_path() . '/' . $normalized;
}

function site_origin_parts(mixed $value): ?array
{
    $origin = rtrim(clean_text($value), '/');
    if ($origin === '' || filter_var($origin, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $port = parse_url($origin, PHP_URL_PORT);
    $path = (string) (parse_url($origin, PHP_URL_PATH) ?? '');
    if (
        !in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || ($path !== '' && $path !== '/')
        || parse_url($origin, PHP_URL_QUERY) !== null
        || parse_url($origin, PHP_URL_FRAGMENT) !== null
        || parse_url($origin, PHP_URL_USER) !== null
        || parse_url($origin, PHP_URL_PASS) !== null
    ) {
        return null;
    }
    $resolvedPort = is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);
    return ['origin' => $origin, 'scheme' => $scheme, 'host' => $host, 'port' => $resolvedPort];
}

function site_origin_is_valid(mixed $value): bool
{
    return site_origin_parts($value) !== null;
}

function origins_match(mixed $first, mixed $second): bool
{
    $left = site_origin_parts($first);
    $right = site_origin_parts($second);
    return $left !== null
        && $right !== null
        && $left['scheme'] === $right['scheme']
        && $left['host'] === $right['host']
        && $left['port'] === $right['port'];
}

function absolute_url(string $path): string
{
    $configuredOrigin = is_configured() ? clean_text(config()['site_origin'] ?? '') : '';
    if (site_origin_is_valid($configuredOrigin)) {
        return rtrim($configuredOrigin, '/') . app_url($path);
    }
    $serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (!preg_match('/^[a-z0-9.\-\[\]]+$/', $serverName)) {
        $serverName = 'localhost';
    }
    $port = (int) ($_SERVER['SERVER_PORT'] ?? (is_https_request() ? 443 : 80));
    $defaultPort = is_https_request() ? 443 : 80;
    $portSuffix = $port > 0 && $port !== $defaultPort ? ':' . $port : '';
    return (is_https_request() ? 'https' : 'http') . '://' . $serverName . $portSuffix . app_url($path);
}

function redirect_to(string $path): never
{
    header('Location: ' . app_url($path), true, 303);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = strip_tags(trim($value));
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function lines_from_text(mixed $value): array
{
    if (!is_string($value)) {
        return [];
    }
    $lines = preg_split('/\R/u', $value) ?: [];
    return array_values(array_filter(
        array_map('clean_text', $lines),
        static fn (string $line): bool => $line !== ''
    ));
}

function json_response(int $status, bool $success, string $message, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function same_origin_request(): bool
{
    $origin = clean_text($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }
    $originScheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $originPort = parse_url($origin, PHP_URL_PORT);
    if (!in_array($originScheme, ['http', 'https'], true) || $originHost === '') {
        return false;
    }
    $originPort = is_int($originPort) ? $originPort : ($originScheme === 'https' ? 443 : 80);

    $requestScheme = is_https_request() ? 'https' : 'http';
    $requestAuthority = clean_text($_SERVER['HTTP_HOST'] ?? 'localhost');
    $requestUrl = $requestScheme . '://' . $requestAuthority;
    $requestHost = strtolower((string) parse_url($requestUrl, PHP_URL_HOST));
    $requestPort = parse_url($requestUrl, PHP_URL_PORT);
    $requestPort = is_int($requestPort) ? $requestPort : ($requestScheme === 'https' ? 443 : 80);

    $matchesRequest = $originScheme === $requestScheme
        && $originHost === $requestHost
        && $originPort === $requestPort;
    if ($matchesRequest) {
        return true;
    }

    if (is_configured()) {
        return origins_match(config()['site_origin'] ?? '', $origin);
    }
    return false;
}

function require_json_post(int $maxBytes = 16384): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_response(405, false, 'Only POST requests are accepted.');
    }
    if (!same_origin_request()) {
        json_response(403, false, 'This request origin is not allowed.');
    }
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with($contentType, 'application/json')) {
        json_response(415, false, 'Send this form as JSON.');
    }
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0 || $contentLength > $maxBytes) {
        json_response(413, false, 'The request is empty or too large.');
    }
    $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (!is_string($body) || strlen($body) > $maxBytes) {
        json_response(413, false, 'The request is too large.');
    }
    try {
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(400, false, 'The request is not valid JSON.');
    }
    if (!is_array($payload)) {
        json_response(400, false, 'The request is invalid.');
    }
    return $payload;
}

function validate_form_timing(array $payload): void
{
    $startedAt = filter_var($payload['startedAt'] ?? null, FILTER_VALIDATE_INT);
    $elapsed = $startedAt === false
        ? 0
        : (int) floor(microtime(true) * 1000) - (int) $startedAt;
    if ($elapsed < 1800 || $elapsed > 7200000) {
        json_response(400, false, 'Please refresh the page and complete the form again.');
    }
}

function client_hash(string $extra = ''): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip . '|' . strtolower($extra));
}

function rate_limit_permits(string $scope, string $keyHash, int $windowSeconds, int $maximum): bool
{
    $connection = db();
    if (random_int(1, 25) === 1) {
        $connection->exec('DELETE FROM rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)');
    }

    // Serialize each rate-limit bucket so concurrent requests cannot all pass
    // the count-before-insert check at the same time.
    $lockName = 'hw_rl_' . substr(hash('sha256', $scope . '|' . $keyHash), 0, 50);
    $acquire = $connection->prepare('SELECT GET_LOCK(?, 2)');
    $acquire->execute([$lockName]);
    if ((int) $acquire->fetchColumn() !== 1) {
        return false;
    }

    try {
        $query = $connection->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE scope_name = ? AND key_hash = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $query->execute([$scope, $keyHash, $windowSeconds]);
        if ((int) $query->fetchColumn() >= $maximum) {
            return false;
        }
        $insert = $connection->prepare('INSERT INTO rate_limits (scope_name, key_hash) VALUES (?, ?)');
        $insert->execute([$scope, $keyHash]);
        return true;
    } finally {
        $release = $connection->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

function enforce_rate_limit(string $scope, string $keyHash, int $windowSeconds, int $maximum): void
{
    if (!rate_limit_permits($scope, $keyHash, $windowSeconds, $maximum)) {
        json_response(429, false, 'Too many requests were submitted. Please wait before trying again.');
    }
}

function content_value(string $key, mixed $fallback = null): mixed
{
    $query = db()->prepare('SELECT content_json FROM site_content WHERE content_key = ?');
    $query->execute([$key]);
    $json = $query->fetchColumn();
    if (!is_string($json)) {
        return $fallback;
    }
    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function save_content_value(string $key, mixed $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $query = db()->prepare(
        'INSERT INTO site_content (content_key, content_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE content_json = VALUES(content_json)'
    );
    $query->execute([$key, $json]);
}

function admin_session_fingerprint(string $passwordHash): string
{
    return hash_hmac('sha256', $passwordHash, encryption_key());
}

function current_admin(): ?array
{
    start_secure_session();
    $id = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null) {
        return null;
    }
    $now = time();
    $startedAt = (int) ($_SESSION['auth_started_at'] ?? $now);
    $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? $now);
    $rotatedAt = (int) ($_SESSION['rotated_at'] ?? $now);
    $_SESSION['auth_started_at'] = $startedAt;
    $_SESSION['rotated_at'] = $rotatedAt;

    if (
        $now - $lastActivityAt > ADMIN_IDLE_TIMEOUT
        || $now - $startedAt > ADMIN_ABSOLUTE_TIMEOUT
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_notice'] = 'expired';
        return null;
    }

    if ($now - $rotatedAt >= ADMIN_ROTATION_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['rotated_at'] = $now;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $_SESSION['last_activity_at'] = $now;

    $query = db()->prepare('SELECT id, email, password_hash FROM admins WHERE id = ? LIMIT 1');
    $query->execute([(int) $id]);
    $admin = $query->fetch();
    if (!is_array($admin)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $storedFingerprint = is_string($_SESSION['auth_fingerprint'] ?? null)
        ? $_SESSION['auth_fingerprint']
        : '';
    $currentFingerprint = admin_session_fingerprint((string) ($admin['password_hash'] ?? ''));
    if ($storedFingerprint === '' || !hash_equals($currentFingerprint, $storedFingerprint)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_notice'] = 'credentials_changed';
        return null;
    }
    unset($admin['password_hash']);
    return $admin;
}

function establish_admin_session(int $adminId): void
{
    $query = db()->prepare('SELECT password_hash FROM admins WHERE id = ? LIMIT 1');
    $query->execute([$adminId]);
    $passwordHash = $query->fetchColumn();
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('The administrator session could not be established.');
    }
    start_secure_session();
    session_regenerate_id(true);
    $now = time();
    $_SESSION = [
        'admin_id' => $adminId,
        'auth_started_at' => $now,
        'last_activity_at' => $now,
        'rotated_at' => $now,
        'auth_fingerprint' => admin_session_fingerprint($passwordHash),
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
}

function take_session_notice(): string
{
    start_secure_session();
    $notice = is_string($_SESSION['session_notice'] ?? null) ? $_SESSION['session_notice'] : '';
    unset($_SESSION['session_notice']);
    return $notice;
}

function admin_session_summary(): array
{
    start_secure_session();
    $now = time();
    $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? $now);
    $startedAt = (int) ($_SESSION['auth_started_at'] ?? $now);
    return [
        'idle_minutes' => max(0, (int) ceil((ADMIN_IDLE_TIMEOUT - ($now - $lastActivityAt)) / 60)),
        'absolute_hours' => max(0, round((ADMIN_ABSOLUTE_TIMEOUT - ($now - $startedAt)) / 3600, 1)),
        'https' => is_https_request(),
    ];
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect_to(admin_path('login.php'));
    }
    return $admin;
}

function require_current_admin_password(mixed $value): void
{
    $admin = require_admin();
    $password = is_string($value) ? $value : '';
    $query = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $query->execute([(int) $admin['id']]);
    $hash = $query->fetchColumn();
    if (!is_string($hash) || !password_verify($password, $hash)) {
        throw new RuntimeException('Your administrator password is required to complete this sensitive action.');
    }
}

function csrf_token(): string
{
    start_secure_session();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_secure_session();
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submitted) || !is_string($expected) || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('The form session expired. Go back, refresh, and try again.');
    }
}

function set_flash(string $type, string $message): void
{
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    start_secure_session();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function audit_log(string $action, string $entityType, string $entityId = '', array $details = []): void
{
    $admin = current_admin();
    $query = db()->prepare(
        'INSERT INTO audit_logs (admin_id, action_name, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $query->execute([
        $admin['id'] ?? null,
        $action,
        $entityType,
        $entityId,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function header_safe(string $value): string
{
    return str_replace(["\r", "\n"], ' ', $value);
}

function send_raw_message(array $settings, string $to, string $subject, string $body, array $extraHeaders = []): bool
{
    $mailSettings = smtp_settings($settings);
    $mailer = new HelloWrandellSmtpMailer();
    if (($mailSettings['smtp_host'] ?? '') !== '' && ($mailSettings['smtp_password'] ?? '') !== '') {
        $sent = $mailer->send($mailSettings, $to, $subject, $body, [
            'from_name' => header_safe((string) ($mailSettings['mail_from_name'] ?? 'Wrandell Almeda Portfolio')),
            'extra' => $extraHeaders,
        ]);
        if (!$sent && $mailer->lastError() instanceof Throwable) {
            operational_log('smtp-delivery', $mailer->lastError());
        }
        return $sent;
    }

    $from = header_safe((string) ($settings['mail_from'] ?? 'wrandellalmeda@gmail.com'));
    $headers = array_merge([
        'MIME-Version: 1.0',
        'From: ' . header_safe((string) ($settings['mail_from_name'] ?? 'Wrandell Almeda Portfolio')) . ' <' . $from . '>',
        'X-Mailer: HelloWrandell CMS',
    ], $extraHeaders);
    return mail(header_safe($to), header_safe($subject), $body, implode("\r\n", $headers));
}

function send_text_mail(string $to, string $subject, string $body, ?string $replyTo = null, ?array $overrideSettings = null): bool
{
    $settings = $overrideSettings ?? config();
    $extra = ['Content-Type: text/plain; charset=UTF-8'];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $extra[] = 'Reply-To: ' . header_safe($replyTo);
    }
    return send_raw_message($settings, $to, $subject, $body, $extra);
}

function send_resume_attachment(string $to, string $recipientName): bool
{
    if (!is_file(RESUME_PATH) || !is_readable(RESUME_PATH)) {
        return false;
    }
    $file = file_get_contents(RESUME_PATH);
    if (!is_string($file)) {
        return false;
    }
    $boundary = '=_HelloWrandell_' . bin2hex(random_bytes(12));
    $body = implode("\r\n", [
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        'Hello ' . $recipientName . ',',
        '',
        'Thank you for confirming your email address. Wrandell I. Almeda’s resume is attached to this message.',
        '',
        'Regards,',
        'Wrandell I. Almeda',
        '',
        '--' . $boundary,
        'Content-Type: application/pdf; name="Wrandell-I-Almeda-Resume.pdf"',
        'Content-Disposition: attachment; filename="Wrandell-I-Almeda-Resume.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($file)),
        '--' . $boundary . '--',
    ]);
    return send_raw_message(
        config(),
        $to,
        'Requested resume — Wrandell I. Almeda',
        $body,
        ['Content-Type: multipart/mixed; boundary="' . $boundary . '"']
    );
}

function send_contact_receipt_with_resume(string $to, string $recipientName, string $opportunityType): bool
{
    if (!is_file(RESUME_PATH) || !is_readable(RESUME_PATH)) {
        return false;
    }
    $file = file_get_contents(RESUME_PATH);
    if (!is_string($file)) {
        return false;
    }
    $safeName = e($recipientName);
    $safeType = e($opportunityType);
    $boundary = '=_HelloWrandell_' . bin2hex(random_bytes(12));
    $html = '<!doctype html><html><body style="margin:0;background:#f3ebe2;color:#30302f;font-family:Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="padding:36px 16px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:auto;background:#fcfaf6;border:1px solid #ded4c9;border-radius:24px;overflow:hidden">'
        . '<tr><td style="padding:28px 34px;background:#30302f;color:#f8f3ea"><div style="font-size:12px;letter-spacing:2px;font-weight:bold;color:#d8a487">WRANDELL I. ALMEDA</div><h1 style="margin:12px 0 0;font-family:Georgia,serif;font-size:32px;font-weight:normal">Thank you for reaching out.</h1></td></tr>'
        . '<tr><td style="padding:34px;font-size:16px;line-height:1.65"><p style="margin-top:0">Hello ' . $safeName . ',</p><p>Thank you for your <strong>' . $safeType . '</strong> enquiry. Your message has been received and is being reviewed.</p><p>As requested, a current copy of Wrandell’s resume is attached to this email.</p><p style="margin-bottom:0">Kind regards,<br><strong>Wrandell I. Almeda</strong></p></td></tr>'
        . '</table></td></tr></table></body></html>';
    $body = implode("\r\n", [
        '--' . $boundary, 'Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: 8bit', '', $html, '',
        '--' . $boundary, 'Content-Type: application/pdf; name="Wrandell-I-Almeda-Resume.pdf"', 'Content-Disposition: attachment; filename="Wrandell-I-Almeda-Resume.pdf"', 'Content-Transfer-Encoding: base64', '', chunk_split(base64_encode($file)), '--' . $boundary . '--',
    ]);
    return send_raw_message(config(), $to, 'Thank you for contacting Wrandell I. Almeda', $body, ['Content-Type: multipart/mixed; boundary="' . $boundary . '"']);
}
