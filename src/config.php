<?php
declare(strict_types=1);

/**
 * Application configuration.
 * Everything here can be overridden with a data/config.local.php that returns an array.
 */

// Normally already defined by helpers.php; guarded so this file also works if
// it is ever required on its own.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

$config = [
    // Public name shown in the UI and in emails.
    'site_name'    => 'Alumni Elections',

    // Absolute base URL, no trailing slash, used for the links inside
    // verification and password-reset emails.
    //
    // Leave it empty and the site works it out from the incoming request, so a
    // fresh install sends working links straight away. SET IT EXPLICITLY on the
    // live site: it is the only way those links cannot be influenced by the Host
    // header a visitor sends. The admin dashboard nags until you do.
    'base_url'     => getenv('APP_BASE_URL') ?: '',

    // SQLite database file. The env var lets the test suite run against its own
    // file instead of the live one.
    'db_path'      => getenv('APP_DB_PATH') ?: APP_ROOT . '/data/elections.sqlite',

    // Where candidate photos land, and the URL prefix they are served from.
    'upload_dir'   => APP_ROOT . '/public/uploads/candidates',
    'upload_url'   => '/uploads/candidates',
    'photo_max_px' => 900,
    'photo_max_mb' => 6,

    // Mail transport: 'log' writes to data/mail.log and sends nothing.
    // 'mail' uses PHP's mail(). Use 'log' anywhere that is not the live site.
    'mail_driver'  => getenv('APP_MAIL_DRIVER') ?: 'log',
    'mail_from'    => getenv('APP_MAIL_FROM') ?: 'no-reply@example.org',
    'mail_log'     => APP_ROOT . '/data/mail.log',

    // Who may create an account.
    //   'open'      - anybody with a working email address
    //   'roll'      - only addresses the admin has put on the alumni roll
    //   'approval'  - anybody may sign up, but an admin must approve before they can vote
    'registration' => 'roll',

    // Session hardening
    'session_name' => 'alumnivote',

    // Failed logins allowed per email+IP inside the window before a lockout.
    'login_max_attempts' => 6,
    'login_window_min'   => 15,
];

$local = APP_ROOT . '/data/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_merge($config, $override);
    }
}

return $config;
