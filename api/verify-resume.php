<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$success = false;
$heading = 'This verification link is not valid.';
$message = 'Request a new copy from the portfolio resume form.';

if (is_configured() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $token = clean_text($_GET['token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        try {
            $tokenHash = hash('sha256', $token);
            $query = db()->prepare('SELECT * FROM resume_requests WHERE token_hash = ? LIMIT 1');
            $query->execute([$tokenHash]);
            $request = $query->fetch();
            if (is_array($request) && $request['status'] === 'sent') {
                $success = true;
                $heading = 'The resume was already sent.';
                $message = 'Check the inbox for the email address you verified.';
            } elseif (!is_array($request) || strtotime($request['expires_at']) < time()) {
                $heading = 'This verification link has expired.';
                $message = 'Return to the portfolio and submit a new resume request.';
            } elseif ($request['status'] !== 'pending') {
                $heading = 'This verification link has already been processed.';
                $message = 'Return to the portfolio if you need to submit a new request.';
            } else {
                $verified = db()->prepare(
                    'UPDATE resume_requests SET status = "verified", verified_at = NOW()
                     WHERE id = ? AND status = "pending" AND expires_at >= NOW()'
                );
                $verified->execute([(int) $request['id']]);
                if ($verified->rowCount() !== 1) {
                    $heading = 'This verification link has already been processed.';
                    $message = 'Check your inbox or submit a new request from the portfolio.';
                } elseif (send_resume_attachment($request['email'], $request['name'])) {
                    $sent = db()->prepare(
                        'UPDATE resume_requests SET status = "sent", sent_at = NOW() WHERE id = ?'
                    );
                    $sent->execute([(int) $request['id']]);
                    $success = true;
                    $heading = 'Email confirmed. Resume sent.';
                    $message = 'The resume has been sent as a PDF attachment. Check your inbox and spam folder.';
                } else {
                    $failed = db()->prepare('UPDATE resume_requests SET status = "failed" WHERE id = ?');
                    $failed->execute([(int) $request['id']]);
                    $heading = 'Your email was confirmed, but delivery failed.';
                    $message = 'Wrandell can see the request in the private CMS and can follow up after SMTP is corrected.';
                }
            }
        } catch (Throwable) {
            $heading = 'The request could not be completed.';
            $message = 'Please try again later or contact Wrandell directly.';
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
  <title><?= e($heading) ?></title>
  <style>
    :root{color-scheme:light;font-family:"Segoe UI",Arial,sans-serif}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:1rem;background:radial-gradient(circle at 82% 10%,rgba(173,112,80,.13),transparent 28rem),#f8f3ea;color:#30302f}.card{width:min(100%,38rem);padding:clamp(1.5rem,6vw,3rem);border:1px solid rgba(48,48,47,.13);border-radius:1.7rem;background:rgba(252,250,246,.96);box-shadow:0 28px 80px rgba(61,49,39,.14)}.mark{display:grid;place-items:center;width:3.3rem;height:3.3rem;border-radius:50%;background:<?= $success ? '#e7f3ec' : '#f6e7e9' ?>;color:<?= $success ? '#3f7a62' : '#a33f4f' ?>;font-size:1.4rem;font-weight:800}h1{margin:1.4rem 0 .7rem;font-family:Georgia,"Times New Roman",serif;font-size:clamp(2rem,6vw,3rem);font-weight:400;letter-spacing:-.055em;line-height:1.05}p{color:#6f6963;line-height:1.7}a{display:inline-flex;margin-top:1rem;padding:.75rem 1.1rem;border-radius:2rem;background:#30302f;color:#f8f3ea;text-decoration:none;font-weight:700;transition:transform .18s ease,background .18s ease}a:hover{transform:translateY(-2px);background:#ad7050}
  </style>
</head>
<body>
  <main class="card">
    <span class="mark" aria-hidden="true"><?= $success ? '✓' : '!' ?></span>
    <h1><?= e($heading) ?></h1>
    <p><?= e($message) ?></p>
    <a href="<?= e(app_url('')) ?>">Return to portfolio</a>
  </main>
</body>
</html>
