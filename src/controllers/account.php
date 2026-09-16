<?php
declare(strict_types=1);

function get_register(): void
{
    if (current_user()) {
        redirect('/');
    }
    render('auth/register', ['errors' => [], 'mode' => (string)config('registration')]);
    clear_old();
}

function post_register(): void
{
    [$ok, $errors, $user] = register_user(
        (string)($_POST['email'] ?? ''),
        (string)($_POST['password'] ?? ''),
        (string)($_POST['full_name'] ?? ''),
        (string)($_POST['grad_year'] ?? '')
    );

    if (!$ok) {
        remember_old($_POST);
        render('auth/register', ['errors' => $errors, 'mode' => (string)config('registration')]);
        return;
    }

    clear_old();
    render('auth/registered', ['user' => $user, 'mode' => (string)config('registration')]);
}

function get_verify(): void
{
    $user = verify_email((string)($_GET['token'] ?? ''));
    if (!$user) {
        render('auth/verified', ['ok' => false, 'user' => null]);
        return;
    }
    render('auth/verified', ['ok' => true, 'user' => $user]);
}

function get_login(): void
{
    if (current_user()) {
        redirect('/');
    }
    render('auth/login', ['error' => null]);
    clear_old();
}

function post_login(): void
{
    $email = (string)($_POST['email'] ?? '');
    [$ok, $message, $user] = attempt_login($email, (string)($_POST['password'] ?? ''));

    if (!$ok) {
        remember_old($_POST);
        audit('user.login_failed', 'user', null, ['email' => normalize_email($email)]);
        render('auth/login', ['error' => $message]);
        return;
    }

    login_user($user);
    audit('user.login', 'user', (int)$user['id'], []);
    clear_old();

    start_session();
    $next = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);

    // Admins and auditors land on their own area; a voter has no use for it.
    $home = in_array($user['role'], ['admin', 'auditor'], true) ? '/admin' : '/';

    flash('success', 'Signed in as ' . $user['email'] . '.');
    redirect($next && str_starts_with((string)$next, '/') ? (string)$next : $home);
}

function post_logout(): void
{
    $u = current_user();
    if ($u) {
        audit('user.logout', 'user', (int)$u['id'], []);
    }
    logout_user();
    flash('success', 'Signed out.');
    redirect('/');
}

function get_forgot(): void
{
    render('auth/forgot', ['sent' => false]);
}

function post_forgot(): void
{
    begin_password_reset((string)($_POST['email'] ?? ''));
    // Always the same answer, whether or not the address is on file.
    render('auth/forgot', ['sent' => true]);
}

function get_reset(): void
{
    $token = (string)($_GET['token'] ?? '');
    $user  = user_by_reset_token($token);
    render('auth/reset', ['token' => $token, 'valid' => (bool)$user, 'error' => null]);
}

function post_reset(): void
{
    $token    = (string)($_POST['token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if ($password !== $confirm) {
        render('auth/reset', ['token' => $token, 'valid' => true, 'error' => 'The two passwords do not match.']);
        return;
    }
    if (mb_strlen($password, 'UTF-8') < 8) {
        render('auth/reset', ['token' => $token, 'valid' => true, 'error' => 'Password must be at least 8 characters.']);
        return;
    }
    if (!complete_password_reset($token, $password)) {
        render('auth/reset', [
            'token' => $token,
            'valid' => false,
            'error' => 'That reset link is invalid or has expired. Request a new one.',
        ]);
        return;
    }

    flash('success', 'Password updated. Sign in with the new one.');
    redirect('/login');
}

function get_account(): void
{
    $user = require_login();

    $stmt = db()->prepare(
        'SELECT r.*, p.title AS position_title, e.title AS election_title, e.timezone
           FROM vote_receipts r
           JOIN positions p ON p.id = r.position_id
           JOIN elections e ON e.id = r.election_id
          WHERE r.user_id = ?
          ORDER BY r.cast_at DESC, r.id DESC'
    );
    $stmt->execute([(int)$user['id']]);

    render('account', ['user' => $user, 'receipts' => $stmt->fetchAll()]);
}
