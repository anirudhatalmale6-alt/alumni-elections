<?php
declare(strict_types=1);

/**
 * Command line helper.
 *
 *   php bin/console.php create-admin <email> <password> [name]
 *   php bin/console.php create-user  <email> <password> <role> [name]
 *   php bin/console.php roll-add     <email> [email ...]
 *   php bin/console.php seed-demo
 *   php bin/console.php verify-chain <election_id>
 *   php bin/console.php stats
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is for the command line only.\n");
}

require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/mail.php';
require dirname(__DIR__) . '/src/audit.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/elections.php';

$argv = $_SERVER['argv'];
$cmd  = $argv[1] ?? 'help';

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "Error: " . $s . "\n"); exit(1); }

/** Create or update an account straight from the CLI, already confirmed and active. */
function upsert_account(string $email, string $password, string $role, string $name): array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail($email . ' is not a valid email address.');
    }
    if (mb_strlen($password, 'UTF-8') < 8) {
        fail('The password must be at least 8 characters.');
    }
    if (!in_array($role, ['admin', 'voter', 'auditor'], true)) {
        fail('Role must be admin, voter or auditor.');
    }

    $existing = find_user_by_email($email);
    if ($existing) {
        $stmt = db()->prepare(
            'UPDATE users SET password_hash = ?, role = ?, status = \'active\',
                              email_verified_at = COALESCE(email_verified_at, ?),
                              full_name = CASE WHEN ? = \'\' THEN full_name ELSE ? END,
                              verify_token = NULL, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            password_hash($password, PASSWORD_DEFAULT), $role, now(),
            $name, $name, now(), $existing['id'],
        ]);
        out('Updated existing account ' . $email . ' (role: ' . $role . ').');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO users (email, email_norm, password_hash, full_name, role, status,
                                email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'active\', ?, ?, ?)'
        );
        $stmt->execute([
            $email, normalize_email($email), password_hash($password, PASSWORD_DEFAULT),
            $name, $role, now(), now(), now(),
        ]);
        out('Created ' . $role . ' account ' . $email . '.');
    }

    return find_user_by_email($email);
}

switch ($cmd) {
    case 'create-admin':
        $email = $argv[2] ?? fail('Usage: create-admin <email> <password> [name]');
        $pass  = $argv[3] ?? fail('Usage: create-admin <email> <password> [name]');
        $name  = $argv[4] ?? 'Elections Administrator';
        $u = upsert_account($email, $pass, 'admin', $name);
        audit('user.created_cli', 'user', (int)$u['id'], ['email' => $email, 'role' => 'admin']);
        out('Sign in at ' . config('base_url') . '/login');
        break;

    case 'create-user':
        $email = $argv[2] ?? fail('Usage: create-user <email> <password> <role> [name]');
        $pass  = $argv[3] ?? fail('Usage: create-user <email> <password> <role> [name]');
        $role  = $argv[4] ?? 'voter';
        $name  = $argv[5] ?? '';
        $u = upsert_account($email, $pass, $role, $name);
        audit('user.created_cli', 'user', (int)$u['id'], ['email' => $email, 'role' => $role]);
        break;

    case 'roll-add':
        $emails = array_slice($argv, 2);
        if (!$emails) {
            fail('Usage: roll-add <email> [email ...]');
        }
        $stmt = db()->prepare(
            'INSERT OR IGNORE INTO alumni_roll (email_norm, note, created_at) VALUES (?, \'cli\', ?)'
        );
        $n = 0;
        foreach ($emails as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                out('  skipped (not an email): ' . $em);
                continue;
            }
            $stmt->execute([normalize_email($em), now()]);
            $n += $stmt->rowCount();
        }
        out($n . ' address(es) added to the roll.');
        break;

    case 'verify-chain':
        $id = (int)($argv[2] ?? 0);
        if (!$id) {
            fail('Usage: verify-chain <election_id>');
        }
        $r = verify_ballot_chain($id);
        if ($r['ok']) {
            out('OK — ' . $r['checked'] . ' ballots verified, chain intact.');
        } else {
            out('BROKEN — chain fails at ballot #' . $r['broken_at']
                . ' after ' . $r['checked'] . ' good ballots.');
            exit(2);
        }
        break;

    case 'stats':
        $pdo = db();
        foreach (['users', 'alumni_roll', 'elections', 'positions', 'candidates',
                  'ballots', 'vote_receipts', 'audit_log'] as $t) {
            out(str_pad($t, 16) . (int)$pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn());
        }
        break;

    case 'seed-demo':
        require __DIR__ . '/seed_demo.php';
        break;

    default:
        out('Commands:');
        out('  create-admin <email> <password> [name]');
        out('  create-user  <email> <password> <role> [name]     role: admin|voter|auditor');
        out('  roll-add     <email> [email ...]');
        out('  seed-demo                                          sample election + voters');
        out('  verify-chain <election_id>');
        out('  stats');
        break;
}
