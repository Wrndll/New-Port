<?php

declare(strict_types=1);

/**
 * Configuration Example File
 * 
 * Copy this file to config.local.php and update with your values.
 * 
 * IMPORTANT SECURITY NOTES:
 * - This file should NEVER be committed to version control
 * - Use environment variables for sensitive data in production
 * - Generate a unique secret.key file (32 bytes, base64 encoded)
 * - Never use 'root' database user in production
 * 
 * For production deployments, consider using .env files:
 * - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 * - SITE_ORIGIN, BASE_PATH
 * - SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD
 * - RECAPTCHA_SITE_KEY, RECAPTCHA_SECRET
 */

return [
    // Database Configuration
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => (int)(getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: 'hello_wrandell',
    'db_user' => getenv('DB_USER') ?: 'portfolio_user',  // Use dedicated user, NOT root
    'db_pass' => getenv('DB_PASS') ?: '',
    
    // Application Paths
    'base_path' => getenv('BASE_PATH') ?: '/HelloWrandell',
    'site_origin' => getenv('SITE_ORIGIN') ?: 'http://localhost',
    
    // Email Configuration
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'Wrandell Almeda Portfolio',
    'mail_from' => getenv('MAIL_FROM') ?: 'wrandellalmeda@gmail.com',
    'mail_recipient' => getenv('MAIL_RECIPIENT') ?: 'wrandellalmeda@gmail.com',
    'smtp_host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'smtp_port' => (int)(getenv('SMTP_PORT') ?: 587),
    'smtp_encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
    'smtp_username' => getenv('SMTP_USERNAME') ?: 'wrandellalmeda@gmail.com',
    'smtp_password_encrypted' => getenv('SMTP_PASSWORD') ?: '',  // Will be encrypted on save
    'mail_last_test_at' => '',
    'mail_last_test_status' => 'Not tested',
    
    // CAPTCHA Configuration
    'captcha_provider' => getenv('CAPTCHA_PROVIDER') ?: 'math',
    'recaptcha_version' => 'v2_checkbox',
    'recaptcha_site_key' => getenv('RECAPTCHA_SITE_KEY') ?: '',
    'recaptcha_secret_encrypted' => getenv('RECAPTCHA_SECRET') ?: '',  // Will be encrypted on save
    'recaptcha_min_score' => 0.5,
];
