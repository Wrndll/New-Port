<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_to(admin_path('login.php'));
}
verify_csrf();
start_secure_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $parameters = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $parameters['path'], '', (bool) $parameters['secure'], true);
}
session_destroy();
redirect_to(admin_path('login.php'));
