<?php
declare(strict_types=1);

/* ------------------------------------------------------------- session --- */

function current_user(): ?array
{
    static $cache = null;
    static $cachedId = null;

    start_session();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    if ($cache !== null && $cachedId === $id) {
        return $cache;
    }

    $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);

    if (!$u || $u['status'] === 'suspended') {
        unset($_SESSION['uid']);
        return null;
    }

    $cache    = $u;
    $cachedId = $id;
    return $u;
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* -------------------------------------------------------------- guards --- */

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function is_auditor(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'auditor';
}

/** Can this account actually cast a ballot? Admins and auditors cannot. */
function can_vote(?array $u = null): bool
{
    $u ??= current_user();
    return $u && $u['role'] === 'voter' && $u['status'] === 'active' && $u['email_verified_at'];
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        start_session();
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        flash('error', 'Please sign in first.');
        redirect('/login');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        render('error', ['title' => 'Not allowed', 'message' => 'That area is for administrators only.']);
        exit;
    }
    return $u;
}

/** Admin or auditor: anything that is read-only oversight. */
function require_oversight(): array
{
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'auditor'], true)) {
        http_response_code(403);
        render('error', ['title' => 'Not allowed', 'message' => 'That area is for administrators and auditors only.']);
        exit;
    }
    return $u;
}

/* ------------------------------------------------------- registration --- */

function find_user_by_email(string $email): ?array
{
    return db_row('SELECT * FROM users WHERE email_norm = ?', [normalize_email($email)]);
}

function email_on_roll(string $email): bool
{
    return db_val('SELECT 1 FROM alumni_roll WHERE email_norm = ?', [normalize_email($email)]) !== null;
}

/**
 * Create an account. Returns [ok, errors[], user|null].
 * The caller decides what to say to the browser; this function never echoes.
 */
function register_user(string $email, string $password, string $fullName, string $gradYear): array
{
    $errors = [];
    $email  = trim($email);
    $norm   = normalize_email($email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (mb_strlen($password, 'UTF-8') < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if (trim($fullName) === '') {
        $errors['full_name'] = 'Tell us your name as it should appear on the alumni roll.';
    }

    $mode = (string)config('registration');
    if (!$errors && $mode === 'roll' && !email_on_roll($email)) {
        $errors['email'] = 'That address is not on the alumni roll. '
                         . 'Contact the elections administrator to be added.';
    }

    if (!$errors && find_user_by_email($email)) {
        $errors['email'] = 'An account already exists for that address. Try signing in, or reset your password.';
    }

    if ($errors) {
        return [false, $errors, null];
    }

    // 'roll' means the address was already vetted, so the account goes straight
    // to active once the address is confirmed. 'approval' parks it as pending.
    $status = ($mode === 'approval') ? 'pending' : 'active';
    $token  = bin2hex(random_bytes(32));

    db_run(
        'INSERT INTO users (email, email_norm, password_hash, full_name, grad_year, role, status,
                            verify_token, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, \'voter\', ?, ?, ?, ?)',
        [$email, $norm, password_hash($password, PASSWORD_DEFAULT),
         trim($fullName), trim($gradYear), $status, $token, now(), now()]
    );

    $user = find_user_by_email($email);
    mail_verification($user, $token);
    audit('user.registered', 'user', (int)$user['id'], ['email' => $email, 'mode' => $mode]);

    return [true, [], $user];
}

function verify_email(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $u = db_row('SELECT * FROM users WHERE verify_token = ?', [$token]);
    if (!$u) {
        return null;
    }

    db_run('UPDATE users SET email_verified_at = ?, verify_token = NULL, updated_at = ? WHERE id = ?',
           [now(), now(), $u['id']]);

    audit('user.email_verified', 'user', (int)$u['id'], ['email' => $u['email']]);

    return db_row('SELECT * FROM users WHERE id = ?', [$u['id']]);
}

/* ------------------------------------------------------- login throttle -- */

function login_attempts_recent(string $email): int
{
    $cfg    = config();
    $cutoff = gmdate('Y-m-d H:i:s', time() - ((int)$cfg['login_window_min'] * 60));
    return db_int(
        'SELECT COUNT(*) FROM login_attempts WHERE email_norm = ? AND ip = ? AND created_at >= ?',
        [normalize_email($email), client_ip(), $cutoff]
    );
}

function record_login_failure(string $email): void
{
    db_run('INSERT INTO login_attempts (email_norm, ip, created_at) VALUES (?, ?, ?)',
           [normalize_email($email), client_ip(), now()]);
}

function clear_login_failures(string $email): void
{
    db_run('DELETE FROM login_attempts WHERE email_norm = ? AND ip = ?',
           [normalize_email($email), client_ip()]);
}

/**
 * Attempt a sign-in. Returns [ok, message, user|null].
 * The failure message is deliberately the same for "no such user" and "wrong
 * password" so the form cannot be used to discover who holds an account.
 */
function attempt_login(string $email, string $password): array
{
    $generic = 'That email and password combination did not match.';

    if (login_attempts_recent($email) >= (int)config('login_max_attempts')) {
        return [false, 'Too many failed attempts. Wait ' . config('login_window_min')
                     . ' minutes and try again, or reset your password.', null];
    }

    $u = find_user_by_email($email);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        record_login_failure($email);
        return [false, $generic, null];
    }

    if ($u['status'] === 'suspended') {
        return [false, 'This account has been suspended. Contact the elections administrator.', null];
    }
    if (!$u['email_verified_at']) {
        return [false, 'Confirm your email address first — check the link we sent when you signed up.', null];
    }
    if ($u['status'] === 'pending') {
        return [false, 'Your account is waiting for an administrator to approve it.', null];
    }

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
               [password_hash($password, PASSWORD_DEFAULT), now(), $u['id']]);
    }

    clear_login_failures($email);
    return [true, '', $u];
}

/* ------------------------------------------------------ password reset --- */

function begin_password_reset(string $email): void
{
    $u = find_user_by_email($email);
    if (!$u) {
        return; // Silent: the form must not reveal whether the address exists.
    }
    $token   = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + 3600);

    db_run('UPDATE users SET reset_token = ?, reset_expires_at = ?, updated_at = ? WHERE id = ?',
           [$token, $expires, now(), $u['id']]);

    mail_password_reset($u, $token);
    audit('user.reset_requested', 'user', (int)$u['id'], ['email' => $u['email']]);
}

function user_by_reset_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    return db_row('SELECT * FROM users WHERE reset_token = ? AND reset_expires_at >= ?', [$token, now()]);
}

function complete_password_reset(string $token, string $password): bool
{
    $u = user_by_reset_token($token);
    if (!$u || mb_strlen($password, 'UTF-8') < 8) {
        return false;
    }
    db_run(
        'UPDATE users
            SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL,
                email_verified_at = COALESCE(email_verified_at, ?), updated_at = ?
          WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), now(), now(), $u['id']]
    );
    audit('user.password_reset', 'user', (int)$u['id'], ['email' => $u['email']]);
    return true;
}
