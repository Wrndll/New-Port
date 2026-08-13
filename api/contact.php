<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';
require_once __DIR__ . '/../private/captcha.php';

if (!is_configured()) {
    json_response(503, false, 'The contact inbox has not been configured yet.');
}

$payload = require_json_post();
if (clean_text($payload['website'] ?? '') !== '') {
    json_response(200, true, 'Your message has been received.');
}
validate_form_timing($payload);

$name = clean_text($payload['name'] ?? '');
$email = strtolower(clean_text($payload['email'] ?? ''));
$company = clean_text($payload['company'] ?? '');
$opportunityType = clean_text($payload['opportunityType'] ?? '');
$message = clean_text($payload['message'] ?? '');
$allowedTypes = [
    'Employment Opportunity',
    'Technical Support',
    'Website Project',
    'System Improvement',
    'Collaboration',
    'Other',
];

if (text_length($name) < 2 || text_length($name) > 80) {
    json_response(422, false, 'Enter a name between 2 and 80 characters.');
}
if (text_length($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    json_response(422, false, 'Enter a valid email address.');
}
if (text_length($company) > 120) {
    json_response(422, false, 'Keep the organization name under 120 characters.');
}
if (!in_array($opportunityType, $allowedTypes, true)) {
    json_response(422, false, 'Select a valid opportunity type.');
}
if (text_length($message) < 20 || text_length($message) > 3000) {
    json_response(422, false, 'Enter a message between 20 and 3,000 characters.');
}

try {
    verify_captcha($payload, 'contact');
} catch (CaptchaValidationException $exception) {
    json_response($exception->httpStatus, false, $exception->getMessage(), ['captchaRequired' => true]);
} catch (Throwable $exception) {
    operational_log('contact-captcha', $exception);
    json_response(503, false, 'Verification is temporarily unavailable. Please try again.');
}

enforce_rate_limit('contact_ip', client_hash('contact-ip'), 3600, 8);
enforce_rate_limit('contact', client_hash($email), 900, 5);

try {
    $insert = db()->prepare(
        'INSERT INTO messages (name, email, company, opportunity_type, message)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([$name, $email, $company, $opportunityType, $message]);
    $messageId = (int) db()->lastInsertId();

    $settings = config();
    $body = implode(PHP_EOL, [
        'New portfolio inquiry',
        '',
        'Name: ' . $name,
        'Email: ' . $email,
        'Company or organization: ' . ($company !== '' ? $company : 'Not provided'),
        'Opportunity type: ' . $opportunityType,
        '',
        'Message:',
        $message,
        '',
        'Open the private CMS inbox to manage this message.',
    ]);
    $notified = send_text_mail(
        (string) $settings['mail_recipient'],
        'Portfolio inquiry: ' . $opportunityType,
        $body,
        $email
    );
    if ($notified) {
        $update = db()->prepare('UPDATE messages SET notification_sent = 1 WHERE id = ?');
        $update->execute([$messageId]);
    } else {
        operational_log('contact-notification', new RuntimeException(
            'Portfolio owner notification failed for stored message ID ' . $messageId . '.'
        ));
    }
    $resumeSent = send_contact_receipt_with_resume($email, $name, $opportunityType);
    if (!$resumeSent) {
        operational_log('contact-resume-delivery', new RuntimeException('Automatic resume delivery failed after a contact submission.'));
    }
    json_response(
        200,
        true,
        $resumeSent
            ? 'Thank you. Your message was received and the resume has been sent to your email.'
            : 'Thank you. Your message is safely in the inbox; the resume email could not be delivered yet.'
    );
} catch (Throwable $exception) {
    operational_log('contact-submission', $exception);
    json_response(503, false, 'The private inbox is temporarily unavailable. Please try again.');
}
