<?php
declare(strict_types=1);

/**
 * Concurrency test.
 *
 * The sequential "vote twice" test in run.php only proves the second call is
 * refused after the first has finished. That is not the case anybody worries
 * about. The real worry is a voter double-clicking, or opening two tabs, so that
 * two requests are inside cast_ballot at the same instant.
 *
 * This script therefore launches real, separate PHP processes that all try to
 * cast a ballot for the SAME voter at the SAME moment, against one SQLite file,
 * and then checks that exactly one vote survived.
 *
 *   php tests/concurrency.php [workers]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$WORKERS = max(2, (int)($_SERVER['argv'][1] ?? 12));
$ROUNDS  = 5;

$testDb = sys_get_temp_dir() . '/alumni_race_' . getmypid() . '.sqlite';

/* ------------------------------------------------------------------------- */
/* Worker mode: invoked by this same file as a child process.                 */
/* ------------------------------------------------------------------------- */

if (($_SERVER['argv'][1] ?? '') === '--worker') {
    $db        = (string)$_SERVER['argv'][2];
    $userId    = (int)$_SERVER['argv'][3];
    $electionId= (int)$_SERVER['argv'][4];
    $startAt   = (float)$_SERVER['argv'][5];

    putenv('APP_DB_PATH=' . $db);
    require dirname(__DIR__) . '/src/helpers.php';
    require dirname(__DIR__) . '/src/db.php';
    require dirname(__DIR__) . '/src/mail.php';
    require dirname(__DIR__) . '/src/audit.php';
    require dirname(__DIR__) . '/src/auth.php';
    require dirname(__DIR__) . '/src/elections.php';
    config_set('mail_driver', 'log');
    config_set('mail_log', sys_get_temp_dir() . '/alumni_race_mail.log');

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $election  = election_find($electionId);
    $structure = ballot_structure($electionId);

    $choices = [];
    foreach ($structure as $pos) {
        $choices[(int)$pos['id']] = (int)$pos['candidates'][0]['id'];
    }

    // Every worker spins until the same wall-clock instant, so they collide.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    try {
        [$ok, $err] = cast_ballot($election, $user, $choices);
        fwrite(STDOUT, $ok ? "ACCEPTED\n" : "REFUSED: " . $err . "\n");
    } catch (Throwable $ex) {
        fwrite(STDOUT, "CRASH: " . $ex->getMessage() . "\n");
        if (getenv('RACE_TRACE') === '1') {
            fwrite(STDOUT, $ex->getFile() . ':' . $ex->getLine() . "\n" . $ex->getTraceAsString() . "\n");
        }
    }
    exit(0);
}

/* ------------------------------------------------------------------------- */
/* Parent mode                                                                */
/* ------------------------------------------------------------------------- */

foreach ([$testDb, $testDb . '-wal', $testDb . '-shm'] as $f) {
    if (is_file($f)) { unlink($f); }
}
putenv('APP_DB_PATH=' . $testDb);

require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/mail.php';
require dirname(__DIR__) . '/src/audit.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/elections.php';
config_set('mail_driver', 'log');
config_set('mail_log', sys_get_temp_dir() . '/alumni_race_mail.log');

$pass = 0;
$fail = 0;

function ok(bool $cond, string $what): void
{
    if ($cond) {
        $GLOBALS['pass']++;
        echo "  \033[32m✓\033[0m " . $what . "\n";
    } else {
        $GLOBALS['fail']++;
        echo "  \033[31m✗ " . $what . "\033[0m\n";
    }
}

echo "\033[1mConcurrency: " . $WORKERS . " simultaneous ballots per round, "
   . $ROUNDS . " rounds\033[0m\n";
echo "database: " . $testDb . "\n\n";

db();

// One election, two positions, open right now.
$stmt = db()->prepare(
    'INSERT INTO elections (title, starts_at, ends_at, timezone, results_mode, created_at, updated_at)
     VALUES (?, ?, ?, \'UTC\', \'live\', ?, ?)'
);
$stmt->execute([
    'Race test',
    gmdate('Y-m-d H:i:s', time() - 3600),
    gmdate('Y-m-d H:i:s', time() + 3600),
    now(), now(),
]);
$electionId = (int)db()->lastInsertId();

foreach (['President', 'Secretary'] as $ptitle) {
    db()->prepare('INSERT INTO positions (election_id, title, sort_order, created_at) VALUES (?, ?, 0, ?)')
        ->execute([$electionId, $ptitle, now()]);
    $pid = (int)db()->lastInsertId();
    foreach (['First Choice', 'Second Choice'] as $cname) {
        db()->prepare('INSERT INTO candidates (position_id, full_name, sort_order, created_at) VALUES (?, ?, 0, ?)')
            ->execute([$pid, $cname . ' for ' . $ptitle, now()]);
    }
}

$positionCount = count(positions_for($electionId));
$self = __FILE__;
$php  = PHP_BINARY;

$totalAccepted = 0;

for ($round = 1; $round <= $ROUNDS; $round++) {
    // A fresh voter each round, so each round is a clean race.
    $email = 'racer' . $round . '@school.test';
    db()->prepare(
        'INSERT INTO users (email, email_norm, password_hash, full_name, role, status,
                            email_verified_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, \'voter\', \'active\', ?, ?, ?)'
    )->execute([$email, $email, password_hash('x', PASSWORD_DEFAULT), 'Racer ' . $round, now(), now(), now()]);
    $userId = (int)db()->lastInsertId();

    $startAt = microtime(true) + 1.2;   // give every child time to boot and park

    $procs = [];
    for ($i = 0; $i < $WORKERS; $i++) {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' --worker '
             . escapeshellarg($testDb) . ' ' . $userId . ' ' . $electionId . ' ' . $startAt;
        $procs[] = popen($cmd . ' 2>&1', 'r');
    }

    $accepted = 0;
    $refused  = 0;
    $crashed  = 0;
    $crashMsg = '';

    foreach ($procs as $p) {
        $out = trim((string)stream_get_contents($p));
        pclose($p);
        if (str_starts_with($out, 'ACCEPTED')) {
            $accepted++;
        } elseif (str_starts_with($out, 'REFUSED')) {
            $refused++;
        } else {
            $crashed++;
            $crashMsg = $out;
        }
    }

    $receipts = (int)db()->query(
        'SELECT COUNT(*) FROM vote_receipts WHERE user_id = ' . $userId
    )->fetchColumn();

    $ballots = (int)db()->query(
        'SELECT COUNT(*) FROM ballots WHERE election_id = ' . $electionId
    )->fetchColumn();

    echo "Round {$round}: {$accepted} accepted, {$refused} refused, {$crashed} crashed"
       . ($crashMsg !== '' ? "  [{$crashMsg}]" : '') . "\n";

    ok($accepted === 1, "  exactly one of the {$WORKERS} simultaneous ballots was accepted");
    ok($crashed === 0,  '  no worker crashed — the losers got a clean refusal, not an error page');
    ok($receipts === $positionCount,
       "  the voter holds exactly {$positionCount} receipts (one per position), not more");
    ok($ballots === $totalAccepted + $positionCount,
       '  exactly one ballot per position was added to the count');

    $totalAccepted += $positionCount;
}

echo "\n";
$chain = verify_ballot_chain($electionId);
ok($chain['ok'] === true,
   'the hash chain is intact after ' . $chain['checked'] . ' ballots written by racing processes');
ok($chain['checked'] === $ROUNDS * $positionCount,
   'and it contains exactly one ballot per position per round (' . $chain['checked'] . ')');

$distinct = (int)db()->query('SELECT COUNT(DISTINCT user_id) FROM vote_receipts')->fetchColumn();
ok($distinct === $ROUNDS, 'turnout counts ' . $ROUNDS . ' voters, not ' . ($ROUNDS * $WORKERS) . ' attempts');

echo "\n" . str_repeat('─', 62) . "\n";
printf("\033[1m%d passed, %d failed\033[0m\n", $pass, $fail);

foreach ([$testDb, $testDb . '-wal', $testDb . '-shm',
          sys_get_temp_dir() . '/alumni_race_mail.log'] as $f) {
    if (is_file($f)) { unlink($f); }
}

exit($fail > 0 ? 1 : 0);
