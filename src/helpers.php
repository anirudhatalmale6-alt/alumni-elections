<?php
declare(strict_types=1);

/*
 * APP_ROOT is defined here rather than in config.php on purpose. config.php is
 * only read the first time config() is called, so a request that renders a view
 * without ever touching configuration — a 404, for instance — would otherwise
 * hit an undefined constant and turn into a 500.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }

    $overrides = $GLOBALS['__config_overrides'] ?? [];
    if ($key === null) {
        return $overrides ? array_merge($cfg, $overrides) : $cfg;
    }
    if (array_key_exists($key, $overrides)) {
        return $overrides[$key];
    }
    return $cfg[$key] ?? null;
}

/**
 * Override one setting at runtime. The test suite uses it to exercise each
 * registration mode without editing a file; the web app never calls it.
 */
function config_set(string $key, $value): void
{
    $GLOBALS['__config_overrides'][$key] = $value;
}

/** HTML-escape. Every value that reaches a template goes through this. */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Absolute site URL with no trailing slash, for links that leave the site.
 *
 * Uses the configured value when there is one. Otherwise it is derived from the
 * current request, which is what makes a fresh install send usable confirmation
 * links before anything has been configured. A derived value is a convenience,
 * not a security boundary — see config.php.
 */
function base_url(): string
{
    $configured = trim((string)config('base_url'));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        // Strip anything that is not a plausible host:port before using it.
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']);
        if ($host !== '') {
            return $scheme . '://' . $host;
        }
    }

    return 'http://localhost:8080';
}

/** True when base_url is being guessed rather than configured. */
function base_url_is_derived(): bool
{
    return trim((string)config('base_url')) === '';
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // There is no browser on the command line, and starting a session there
    // would leave stray session files behind for the CLI user.
    if (PHP_SAPI === 'cli') {
        return;
    }
    session_name((string)config('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/* ---------------------------------------------------------------- CSRF --- */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = (string)($_POST['_csrf'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        http_response_code(419);
        render('error', [
            'title'   => 'Session expired',
            'message' => 'This form was submitted with an expired or missing security token. '
                       . 'Go back, reload the page and try again.',
        ]);
        exit;
    }
}

/* --------------------------------------------------------------- flash --- */

function flash(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $key, string $default = ''): string
{
    start_session();
    return (string)($_SESSION['old'][$key] ?? $default);
}

function remember_old(array $data): void
{
    start_session();
    unset($data['_csrf'], $data['password'], $data['password_confirm']);
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    start_session();
    unset($_SESSION['old']);
}

/* ------------------------------------------------------------ redirect --- */

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

/* ------------------------------------------------------------- views ----- */

function render(string $view, array $vars = []): void
{
    $file = APP_ROOT . '/views/' . $view . '.php';
    if (!is_file($file)) {
        throw new RuntimeException("View not found: {$view}");
    }
    $vars['_view'] = $view;
    extract($vars, EXTR_SKIP);

    ob_start();
    require $file;
    $content = (string)ob_get_clean();

    require APP_ROOT . '/views/layout.php';
}

/* ---------------------------------------------------------------- time --- */

/** Turn a UTC 'Y-m-d H:i:s' into a human string in the election's timezone. */
function fmt_dt(?string $utc, string $tz = 'UTC', string $format = 'D j M Y, H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone($tz))->format($format) . ' ' . $tz;
    } catch (Throwable $e) {
        return $utc . ' UTC';
    }
}

/** Parse an <input type="datetime-local"> value in $tz into UTC storage form. */
function local_to_utc(string $localValue, string $tz): ?string
{
    $localValue = trim($localValue);
    if ($localValue === '') {
        return null;
    }
    $localValue = str_replace('T', ' ', $localValue);
    if (strlen($localValue) === 16) {
        $localValue .= ':00';
    }
    try {
        $d = new DateTimeImmutable($localValue, new DateTimeZone($tz));
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/** Inverse of local_to_utc, for pre-filling the edit form. */
function utc_to_local_input(?string $utc, string $tz): string
{
    if (!$utc) {
        return '';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

function human_bytes(int $n): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n = intdiv($n, 1024);
        $i++;
    }
    return $n . ' ' . $u[$i];
}
