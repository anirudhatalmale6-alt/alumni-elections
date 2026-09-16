<?php
declare(strict_types=1);

/**
 * Minimal mail sender.
 *
 * The default driver is 'log': it writes the whole message to data/mail.log and
 * hands nothing to a transport. That is on purpose — while the site is being
 * built and tested, no message should ever leave the machine, not even to an
 * address that looks invalid. Switch to 'mail' only on the live site.
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $cfg    = config();
    $from   = (string)$cfg['mail_from'];
    $driver = (string)$cfg['mail_driver'];

    $headers = [
        'From: ' . $cfg['site_name'] . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: alumni-elections',
    ];

    if ($driver === 'mail') {
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    // 'log' (default) and anything unrecognised.
    $entry = str_repeat('=', 70) . "\n"
           . 'DATE:    ' . now() . " UTC\n"
           . 'TO:      ' . $to . "\n"
           . 'FROM:    ' . $from . "\n"
           . 'SUBJECT: ' . $subject . "\n"
           . str_repeat('-', 70) . "\n"
           . $body . "\n";

    $path = (string)$cfg['mail_log'];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    return (bool)file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}

function mail_verification(array $user, string $token): void
{
    $url = base_url() . '/verify?token=' . urlencode($token);
    $body = "Hello " . ($user['full_name'] ?: 'there') . ",\n\n"
          . "An account was created for this address on " . config('site_name') . ".\n\n"
          . "Confirm your address to activate it:\n\n    " . $url . "\n\n"
          . "If you did not sign up, ignore this message — the account stays inactive and nobody can vote with it.\n";
    send_mail((string)$user['email'], 'Confirm your ' . config('site_name') . ' account', $body);
}

function mail_password_reset(array $user, string $token): void
{
    $url = base_url() . '/reset?token=' . urlencode($token);
    $body = "Hello " . ($user['full_name'] ?: 'there') . ",\n\n"
          . "A password reset was requested for this address.\n\n"
          . "Set a new password here (the link is good for one hour):\n\n    " . $url . "\n\n"
          . "If you did not ask for this, ignore the message — your current password still works.\n";
    send_mail((string)$user['email'], 'Reset your ' . config('site_name') . ' password', $body);
}

function mail_account_approved(array $user): void
{
    $url = base_url() . '/login';
    $body = "Hello " . ($user['full_name'] ?: 'there') . ",\n\n"
          . "Your alumni account has been approved. You can sign in and vote in any open election:\n\n    "
          . $url . "\n";
    send_mail((string)$user['email'], 'Your ' . config('site_name') . ' account is active', $body);
}
