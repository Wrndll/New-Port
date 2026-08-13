<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';
require_once __DIR__ . '/../private/captcha.php';

if (!is_configured()) {
    json_response(503, false, 'The resume request service has not been configured yet.');
}

$payload = require_json_post();
if (clean_text($payload['website'] ?? '') !== '') {
    json_response(200, true, 'Check your inbox to continue.');
}
validate_form_timing($payload);

$name = clean_text($payload['name'] ?? '');
$email = strtolower(clean_text($payload['email'] ?? ''));
$company = clean_text($payload['company'] ?? '');
$purpose = clean_text($payload['purpose'] ?? '');

if (text_length($name) < 2 || text_length($name) > 80) {
    json_response(422, false, 'Enter a name between 2 and 80 characters.');
}
if (text_length($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    json_response(422, false, 'Enter a valid email address.');
}
if (text_length($company) > 120) {
    json_response(422, false, 'Keep the organization name under 120 characters.');
}
if (text_length($purpose) < 10 || text_length($purpose) > 1000) {
    json_response(422, false, 'Enter a reason between 10 and 1,000 characters.');
}
if (!is_file(RESUME_PATH)) {
    json_response(503, false, 'The resume is temporarily unavailable.');
}

try {
    verify_captcha($payload, 'resume');
} catch (CaptchaValidationException $exception) {
    json_response($exception->httpStatus, false, $exception->getMessage(), ['captchaRequired' => true]);
} catch (Throwable $exception) {
    operational_log('resume-captcha', $exception);
    json_response(503, false, 'Verification is temporarily unavailable. Please try again.');
}

enforce_rate_limit('resume_ip', client_hash('resume-ip'), 3600, 5);
enforce_rate_limit('resume', client_hash($email), 3600, 3);

try {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $insert = db()->prepare(
        'INSERT INTO resume_requests (
            name, email, company, purpose, status, token_hash, expires_at
         ) VALUES (?, ?, ?, ?, "pending", ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );
    $insert->execute([$name, $email, $company, $purpose, $tokenHash]);
    $requestId = (int) db()->lastInsertId();
    $verificationUrl = absolute_url('api/verify-resume.php?token=' . rawurlencode($token));
    $body = implode(PHP_EOL, [
        'Hello ' . $name . ',',
        '',
        'Confirm this email address to receive Wrandell I. Almeda’s resume:',
        $verificationUrl,
        '',
        'This one-time link expires in 30 minutes. If you did not request the resume, ignore this message.',
    ]);
    $sent = send_text_mail(
        $email,
        'Confirm your resume request — Wrandell I. Almeda',
        $body
    );
    if (!$sent) {
        $update = db()->prepare('UPDATE resume_requests SET status = "failed" WHERE id = ?');
        $update->execute([$requestId]);
        operational_log('smtp-delivery', new RuntimeException(
            'SMTP delivery was rejected or could not be completed for a resume verification email.'
        ));
        json_response(
            503,
            false,
            'The request was recorded, but the verification email could not be sent. Please try again later.'
        );
    }
    json_response(200, true, 'Check your inbox and confirm your email to receive the resume.');
} catch (Throwable $exception) {
    operational_log('resume-request', $exception);
    json_response(503, false, 'The resume request could not be recorded. Please try again.');
}
