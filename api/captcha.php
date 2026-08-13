<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/captcha.php';

if (!is_configured()) {
    json_response(503, false, 'Verification has not been configured yet.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    json_response(405, false, 'Only GET requests are accepted.');
}
if (!same_origin_request()) {
    json_response(403, false, 'This request origin is not allowed.');
}

try {
    $context = captcha_context($_GET['context'] ?? 'contact');
    json_response(200, true, 'Verification is ready.', [
        'captcha' => captcha_public_configuration($context),
    ]);
} catch (CaptchaValidationException $exception) {
    json_response($exception->httpStatus, false, $exception->getMessage());
} catch (Throwable $exception) {
    operational_log('captcha-challenge', $exception);
    json_response(503, false, 'Verification is temporarily unavailable. Please try again.');
}
