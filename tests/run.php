<?php
declare(strict_types=1);

/**
 * Integration tests. They run against a real SQLite file (a throwaway one), not
 * a mock, so the unique constraints and triggers that carry the integrity rules
 * are the things actually being exercised.
 *
 *   php tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$testDb = sys_get_temp_dir() . '/alumni_test_' . getmypid() . '.sqlite';
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
config_set('mail_log', sys_get_temp_dir() . '/alumni_test_mail_' . getmypid() . '.log');

/* ---------------------------------------------------------- tiny harness -- */

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['group'] = '';

function group(string $name): void
{
    $GLOBALS['group'] = $name;
    echo "\n\033[1m" . $name . "\033[0m\n";
}

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

function eq($expected, $actual, string $what): void
{
    $cond = $expected === $actual;
    if (!$cond) {
        $what .= "  [expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "]";
    }
    ok($cond, $what);
}

/** Assert that a callable throws. */
function throws(callable $fn, string $what, string $needle = ''): void
{
    try {
        $fn();
        ok(false, $what . ' (nothing was thrown)');
    } catch (Throwable $ex) {
        if ($needle !== '' && !str_contains($ex->getMessage(), $needle)) {
            ok(false, $what . ' (threw, but message was: ' . $ex->getMessage() . ')');
            return;
        }
        ok(true, $what);
    }
}

/* --------------------------------------------------------------- helpers -- */

function mk_user(string $email, string $role = 'voter', string $status = 'active', bool $verified = true): array
{
    $stmt = db()->prepare(
        'INSERT INTO users (email, email_norm, password_hash, full_name, role, status,
                            email_verified_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $email, normalize_email($email), password_hash('password123', PASSWORD_DEFAULT),
        'Test ' . $email, $role, $status, $verified ? now() : null, now(), now(),
    ]);
    return find_user_by_email($email);
}

function mk_election(string $title, int $startOffset, int $endOffset, string $mode = 'after_close'): array
{
    $stmt = db()->prepare(
        'INSERT INTO elections (title, starts_at, ends_at, timezone, results_mode, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $title,
        gmdate('Y-m-d H:i:s', time() + $startOffset),
        gmdate('Y-m-d H:i:s', time() + $endOffset),
        'UTC', $mode, now(), now(),
    ]);
    return election_find((int)db()->lastInsertId());
}

function mk_position(int $electionId, string $title): int
{
    $stmt = db()->prepare(
        'INSERT INTO positions (election_id, title, sort_order, created_at) VALUES (?, ?, 0, ?)'
    );
    $stmt->execute([$electionId, $title, now()]);
    return (int)db()->lastInsertId();
}

function mk_candidate(int $positionId, string $name): int
{
    $stmt = db()->prepare(
        'INSERT INTO candidates (position_id, full_name, sort_order, created_at) VALUES (?, ?, 0, ?)'
    );
    $stmt->execute([$positionId, $name, now()]);
    return (int)db()->lastInsertId();
}

/* ========================================================================== */

echo "\033[1mAlumni Elections — integration tests\033[0m\n";
echo "database: " . $testDb . "\n";

db(); // triggers the migrations

/* -------------------------------------------------------------------------- */
group('Schema: the ballot cannot be linked to a voter');

$cols = db()->query("PRAGMA table_info(ballots)")->fetchAll(PDO::FETCH_COLUMN, 1);
ok(!in_array('user_id', $cols, true),
   'ballots has no user_id column — a tally cannot be traced to an individual');
ok(in_array('candidate_id', $cols, true) && in_array('hash', $cols, true),
   'ballots stores the choice and its hash');

$rcols = db()->query("PRAGMA table_info(vote_receipts)")->fetchAll(PDO::FETCH_COLUMN, 1);
ok(in_array('user_id', $rcols, true),
   'vote_receipts records who voted, separately from what was voted');

$idx = db()->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='vote_receipts'")->fetchColumn();
ok(str_contains((string)$idx, 'UNIQUE (election_id, position_id, user_id)'),
   'one-vote-per-voter is a UNIQUE constraint in the schema, not a PHP check');

/* -------------------------------------------------------------------------- */
group('Registration is restricted to the alumni roll');

config_set('registration', 'roll');

[$ok1, $err1] = register_user('stranger@nowhere.test', 'password123', 'A Stranger', '2000');
ok($ok1 === false && isset($err1['email']), 'an address that is not on the roll is refused');

db()->prepare('INSERT INTO alumni_roll (email_norm, created_at) VALUES (?, ?)')
    ->execute(['alum@school.test', now()]);

[$ok2, $err2, $newUser] = register_user('alum@school.test', 'password123', 'Real Alum', '2012');
ok($ok2 === true, 'an address on the roll is accepted');
eq('active', $newUser['status'], 'a roll-vetted account starts active');
ok($newUser['email_verified_at'] === null, 'but it is not confirmed until the email link is opened');

[$ok3, $err3] = register_user('ALUM@school.test', 'password123', 'Duplicate', '2012');
ok($ok3 === false && isset($err3['email']),
   'a second account on the same address is refused, and the check is case-insensitive');

[$ok4, $err4] = register_user('alum@school.test', 'short', 'X', '');
ok($ok4 === false && isset($err4['password']), 'a password under 8 characters is refused');

/* -------------------------------------------------------------------------- */
group('Registration mode: open and approval');

config_set('registration', 'open');
[$ok5, , $openUser] = register_user('anyone@web.test', 'password123', 'Any One', '');
ok($ok5 === true, 'open mode lets any valid address register');
eq('active', $openUser['status'], 'open mode activates immediately');

config_set('registration', 'approval');
[$ok6, , $pendingUser] = register_user('waiting@web.test', 'password123', 'Wait Ing', '');
ok($ok6 === true, 'approval mode accepts the signup');
eq('pending', $pendingUser['status'], 'approval mode parks the account as pending');

config_set('registration', 'roll');

/* -------------------------------------------------------------------------- */
group('Email confirmation and sign-in');

[$li1, $msg1] = attempt_login('alum@school.test', 'password123');
ok($li1 === false && str_contains($msg1, 'Confirm your email'),
   'an unconfirmed account cannot sign in');

$fresh = find_user_by_email('alum@school.test');
$verified = verify_email((string)$fresh['verify_token']);
ok($verified !== null && $verified['email_verified_at'] !== null, 'the confirmation link confirms the address');

ok(verify_email((string)$fresh['verify_token']) === null, 'the same confirmation link cannot be replayed');

[$li2] = attempt_login('alum@school.test', 'password123');
ok($li2 === true, 'a confirmed active account signs in');

[$li3, $msg3] = attempt_login('alum@school.test', 'wrong-password');
ok($li3 === false, 'the wrong password is refused');

[$li4, $msg4] = attempt_login('does-not-exist@school.test', 'whatever');
eq($msg3, $msg4,
   'a wrong password and an unknown address give the identical message, so the form cannot enumerate accounts');

$pendingLogin = mk_user('pending@school.test', 'voter', 'pending');
[$li5, $msg5] = attempt_login('pending@school.test', 'password123');
ok($li5 === false && str_contains($msg5, 'approve'), 'a pending account cannot sign in');

$susp = mk_user('susp@school.test', 'voter', 'suspended');
[$li6, $msg6] = attempt_login('susp@school.test', 'password123');
ok($li6 === false && str_contains($msg6, 'suspended'), 'a suspended account cannot sign in');

/* -------------------------------------------------------------------------- */
group('Login throttling');

config_set('login_max_attempts', 3);
for ($i = 0; $i < 3; $i++) {
    attempt_login('throttle@school.test', 'nope');
}
[$li7, $msg7] = attempt_login('throttle@school.test', 'nope');
ok($li7 === false && str_contains($msg7, 'Too many failed attempts'),
   'repeated failures from one address lock the form for a while');
config_set('login_max_attempts', 100);

/* -------------------------------------------------------------------------- */
group('Password reset');

begin_password_reset('alum@school.test');
$withToken = find_user_by_email('alum@school.test');
ok($withToken['reset_token'] !== null, 'a reset token is issued');

ok(complete_password_reset((string)$withToken['reset_token'], 'brand-new-password') === true,
   'the reset link sets the new password');
ok(complete_password_reset((string)$withToken['reset_token'], 'another-password') === false,
   'the same reset link cannot be used twice');

[$li8] = attempt_login('alum@school.test', 'brand-new-password');
ok($li8 === true, 'the new password works');
[$li9] = attempt_login('alum@school.test', 'password123');
ok($li9 === false, 'the old password no longer works');

// An expired token must be refused even though it is still in the row.
$expUser = mk_user('expired@school.test');
db()->prepare('UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?')
    ->execute(['expired-token', gmdate('Y-m-d H:i:s', time() - 60), $expUser['id']]);
ok(user_by_reset_token('expired-token') === null, 'an expired reset token is refused');

/* -------------------------------------------------------------------------- */
group('The voting window is enforced on the server clock');

$upcoming = mk_election('Upcoming', 3600, 7200);
$open     = mk_election('Open now', -3600, 3600);
$closed   = mk_election('Closed', -7200, -3600);

eq('upcoming', election_phase($upcoming), 'an election that has not started reads as upcoming');
eq('open',     election_phase($open),     'an election inside its window reads as open');
eq('closed',   election_phase($closed),   'an election past its end reads as closed');

$openId  = (int)$open['id'];
$posPres = mk_position($openId, 'President');
$posSec  = mk_position($openId, 'Secretary');
$aliceId = mk_candidate($posPres, 'Alice');
$bobId   = mk_candidate($posPres, 'Bob');
$zeroId  = mk_candidate($posPres, 'Never Voted For');
$secId   = mk_candidate($posSec, 'Sole Secretary');

$v1 = mk_user('v1@school.test');
$v2 = mk_user('v2@school.test');
$v3 = mk_user('v3@school.test');
$v4 = mk_user('v4@school.test');

$upPos = mk_position((int)$upcoming['id'], 'Chair');
$upCan = mk_candidate($upPos, 'Early Bird');
[$vw1, $vwErr1] = cast_ballot($upcoming, $v1, [$upPos => $upCan]);
ok($vw1 === false && str_contains($vwErr1, 'not open'), 'a vote before the window opens is refused');

$clPos = mk_position((int)$closed['id'], 'Chair');
$clCan = mk_candidate($clPos, 'Late Bird');
[$vw2, $vwErr2] = cast_ballot($closed, $v1, [$clPos => $clCan]);
ok($vw2 === false && str_contains($vwErr2, 'not open'), 'a vote after the window closes is refused');

/* -------------------------------------------------------------------------- */
group('Elections are listed open-first');

$order = array_map(
    static fn(array $e) => election_phase($e),
    array_values(array_filter(elections_list(), static fn($e) => in_array($e['title'],
        ['Upcoming', 'Open now', 'Closed'], true)))
);
eq(['open', 'upcoming', 'closed'], $order,
   'the open election is listed first, then the upcoming one, then the closed one');

/* -------------------------------------------------------------------------- */
group('Casting a ballot');

[$c1, $e1, $receipt1] = cast_ballot($open, $v1, [$posPres => $aliceId, $posSec => $secId]);
ok($c1 === true, 'a ballot inside the window is accepted');
ok(strlen($receipt1) === 10, 'the voter is given a receipt code');
ok(has_voted_in_election((int)$v1['id'], $openId), 'the voter is recorded as having voted');

/* -------------------------------------------------------------------------- */
group('One vote per voter — the central rule');

[$c2, $e2] = cast_ballot($open, $v1, [$posPres => $bobId, $posSec => $secId]);
ok($c2 === false, 'the same voter cannot submit a second ballot');
ok(str_contains($e2, 'already been recorded'), 'and is told so plainly');

$aliceCount = (int)db()->query('SELECT COUNT(*) FROM ballots WHERE candidate_id = ' . $aliceId)->fetchColumn();
$bobCount   = (int)db()->query('SELECT COUNT(*) FROM ballots WHERE candidate_id = ' . $bobId)->fetchColumn();
eq(1, $aliceCount, 'the first vote is still there');
eq(0, $bobCount,   'the rejected second ballot left nothing behind — the transaction rolled back whole');

// The refusal must come from the database, not only from the PHP path above.
throws(
    function () use ($openId, $posPres, $v1) {
        db()->prepare(
            'INSERT INTO vote_receipts (election_id, position_id, user_id, receipt_code, cast_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$openId, $posPres, (int)$v1['id'], 'FORCED', now()]);
    },
    'a direct INSERT of a duplicate receipt is rejected by the database itself',
    'UNIQUE'
);

/* -------------------------------------------------------------------------- */
group('Who may vote');

$adminUser   = mk_user('admin@school.test', 'admin');
$auditorUser = mk_user('auditor@school.test', 'auditor');
$unverified  = mk_user('unverified@school.test', 'voter', 'active', false);
$suspended   = mk_user('suspended@school.test', 'voter', 'suspended');

ok(can_vote($v2) === true, 'an active confirmed voter may vote');
ok(can_vote($adminUser) === false, 'an administrator may not vote');
ok(can_vote($auditorUser) === false, 'an auditor may not vote');
ok(can_vote($unverified) === false, 'an unconfirmed account may not vote');
ok(can_vote($suspended) === false, 'a suspended account may not vote');

[$ca] = cast_ballot($open, $adminUser, [$posPres => $aliceId]);
ok($ca === false, 'an administrator ballot is refused by cast_ballot too, not only hidden in the UI');

/* -------------------------------------------------------------------------- */
group('A ballot cannot be forged');

[$cf1, $ef1] = cast_ballot($open, $v2, [$posPres => $secId]);   // secretary candidate in president slot
ok($cf1 === false && str_contains($ef1, 'not standing'),
   'voting for a candidate who stands in a different position is refused');

[$cf2, $ef2] = cast_ballot($open, $v2, [$posPres => 999999]);
ok($cf2 === false, 'voting for a candidate id that does not exist is refused');

eq(false, has_voted_in_election((int)$v2['id'], $openId),
   'a refused ballot does not consume the voter\'s one vote');

/* -------------------------------------------------------------------------- */
group('Abstention');

[$cab] = cast_ballot($open, $v2, [$posPres => $bobId, $posSec => 0]);
ok($cab === true, 'a ballot with one position left blank is accepted');

$secBallots  = (int)db()->query('SELECT COUNT(*) FROM ballots WHERE position_id = ' . $posSec)->fetchColumn();
$secReceipts = (int)db()->query('SELECT COUNT(*) FROM vote_receipts WHERE position_id = ' . $posSec)->fetchColumn();
eq(2, $secReceipts, 'the abstained position still records that the voter settled it');
eq(1, $secBallots,  'but no vote is counted for it');

[$cab2] = cast_ballot($open, $v3, [$posPres => 0, $posSec => 0]);
ok($cab2 === false, 'a completely empty ballot is refused rather than silently swallowed');

/* -------------------------------------------------------------------------- */
group('Counting');

[$cc3] = cast_ballot($open, $v3, [$posPres => $aliceId, $posSec => $secId]);
[$cc4] = cast_ballot($open, $v4, [$posPres => $aliceId, $posSec => 0]);
ok($cc3 && $cc4, 'two more ballots go in');

$t = tally_position($posPres);
eq(4, $t['votes'], 'the president count equals the number of president votes cast');
eq('Alice', $t['rows'][0]['full_name'], 'the leader is first');
eq(3, (int)$t['rows'][0]['votes'], 'Alice has three votes');
ok(count($t['rows']) === 3, 'every candidate appears in the result');

$zeroRow = null;
foreach ($t['rows'] as $r) {
    if ((int)$r['id'] === $zeroId) { $zeroRow = $r; }
}
ok($zeroRow !== null && (int)$zeroRow['votes'] === 0,
   'a candidate nobody voted for is still listed, with zero — not dropped from the table');
eq(['Alice'], $t['leaders'], 'a clear winner is not reported as a tie');
ok($t['tied'] === false, 'and the tie flag is false');

$ts = tally_position($posSec);
eq(2, $ts['votes'], 'the secretary count ignores the abstentions');
eq(2, $ts['abstentions'], 'and reports them separately');
eq(4, $ts['submitted'], 'four voters settled the secretary position in total');

$turn = turnout($openId);
eq(4, $turn['voted'], 'turnout counts distinct voters, not ballot lines');

/* -------------------------------------------------------------------------- */
group('Ties are reported as ties');

$tieElection = mk_election('Tie', -3600, 3600);
$tiePos = mk_position((int)$tieElection['id'], 'Chair');
$tieA = mk_candidate($tiePos, 'Ay');
$tieB = mk_candidate($tiePos, 'Bee');
cast_ballot($tieElection, mk_user('t1@school.test'), [$tiePos => $tieA]);
cast_ballot($tieElection, mk_user('t2@school.test'), [$tiePos => $tieB]);

$tt = tally_position($tiePos);
ok($tt['tied'] === true, 'an equal split is flagged as a tie');
eq(2, count($tt['leaders']), 'and both leaders are named');

$emptyPos = mk_position((int)$tieElection['id'], 'Nobody Voted');
mk_candidate($emptyPos, 'Lonely');
$et = tally_position($emptyPos);
ok($et['tied'] === false && $et['leaders'] === [],
   'a position with no votes at all is not reported as a tie between zeroes');

/* -------------------------------------------------------------------------- */
group('Ballot hash chain detects tampering');

$chain = verify_ballot_chain($openId);
ok($chain['ok'] === true, 'the chain verifies on untouched data');
ok($chain['checked'] > 0, 'and it actually checked some ballots (not a vacuous pass)');

// Move one vote from Alice to Bob straight in the database, the way somebody
// with file access would.
$victim = db()->query('SELECT id FROM ballots WHERE position_id = ' . $posPres . ' ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('UPDATE ballots SET candidate_id = ? WHERE id = ?')->execute([$bobId, $victim]);

$broken = verify_ballot_chain($openId);
ok($broken['ok'] === false, 'editing a ballot directly in the database is detected');
eq((int)$victim, $broken['broken_at'], 'and the first bad ballot is identified');

// Put it back so the rest of the suite runs on consistent data.
db()->prepare('UPDATE ballots SET candidate_id = ? WHERE id = ?')->execute([$aliceId, $victim]);
ok(verify_ballot_chain($openId)['ok'] === true, 'restoring the row makes the chain verify again');

// Deleting a ballot must also be visible.
$deleted = db()->query('SELECT id FROM ballots WHERE election_id = ' . $openId . ' ORDER BY id LIMIT 1, 1')->fetchColumn();
$row = db()->query('SELECT * FROM ballots WHERE id = ' . (int)$deleted)->fetch();
db()->exec('DELETE FROM ballots WHERE id = ' . (int)$deleted);
ok(verify_ballot_chain($openId)['ok'] === false, 'removing a ballot breaks the chain too');

db()->prepare(
    'INSERT INTO ballots (id, election_id, position_id, candidate_id, cast_at, nonce, prev_hash, hash)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $row['id'], $row['election_id'], $row['position_id'], $row['candidate_id'],
    $row['cast_at'], $row['nonce'], $row['prev_hash'], $row['hash'],
]);
ok(verify_ballot_chain($openId)['ok'] === true, 'and putting it back exactly restores the chain');

/* -------------------------------------------------------------------------- */
group('The audit log cannot be rewritten');

audit('test.entry', 'test', 1, ['note' => 'written by the test suite']);
$lastId = (int)db()->query('SELECT MAX(id) FROM audit_log')->fetchColumn();
ok($lastId > 0, 'an audit entry is written');

throws(
    fn() => db()->exec("UPDATE audit_log SET action = 'tampered' WHERE id = " . $lastId),
    'UPDATE on the audit log is rejected by the database',
    'append-only'
);
throws(
    fn() => db()->exec('DELETE FROM audit_log WHERE id = ' . $lastId),
    'DELETE on the audit log is rejected by the database',
    'append-only'
);

$stillThere = db()->query('SELECT action FROM audit_log WHERE id = ' . $lastId)->fetchColumn();
eq('test.entry', $stillThere, 'the entry is unchanged after both attempts');

$ballotAudit = db()->query(
    "SELECT details FROM audit_log WHERE action = 'ballot.cast' ORDER BY id DESC LIMIT 1"
)->fetchColumn();
ok($ballotAudit !== false, 'casting a ballot writes an audit entry');
ok(!str_contains((string)$ballotAudit, 'candidate') && !str_contains((string)$ballotAudit, 'user_id'),
   'and that entry records no candidate and no voter');

/* -------------------------------------------------------------------------- */
group('Results visibility');

$voterViewer  = $v1;
$adminViewer  = $adminUser;
$auditViewer  = $auditorUser;

$liveOpen    = mk_election('Live open',      -3600, 3600,  'live');
$liveUpcoming= mk_election('Live upcoming',   3600, 7200,  'live');
$autoOpen    = mk_election('Auto open',      -3600, 3600,  'after_close');
$autoClosed  = mk_election('Auto closed',    -7200, -3600, 'after_close');
$manualClosed= mk_election('Manual closed',  -7200, -3600, 'manual');

ok(results_visible($liveOpen, $voterViewer) === true,
   'live mode: a voter sees the numbers while voting is open');
ok(results_visible($liveUpcoming, $voterViewer) === false,
   'live mode: nothing is shown before voting opens');
ok(results_visible($autoOpen, $voterViewer) === false,
   'scheduled mode: results stay sealed while voting is open');
ok(results_visible($autoClosed, $voterViewer) === true,
   'scheduled mode: results appear by themselves once voting closes');
ok(results_visible($manualClosed, $voterViewer) === false,
   'manual mode: a closed election stays sealed until it is published');

db()->prepare('UPDATE elections SET results_published_at = ? WHERE id = ?')
    ->execute([now(), $manualClosed['id']]);
ok(results_visible(election_find((int)$manualClosed['id']), $voterViewer) === true,
   'manual mode: publishing makes them visible');

ok(results_visible($autoOpen, $adminViewer) === true,  'an administrator can watch turnout at any time');
ok(results_visible($autoOpen, $auditViewer) === true,  'an auditor can too');
ok(results_visible($autoOpen, null) === false,         'a signed-out visitor cannot');

/* -------------------------------------------------------------------------- */
group('Eligible roll counting');

$before = eligible_voter_count();
mk_user('extra-active@school.test', 'voter', 'active', true);
mk_user('extra-pending@school.test', 'voter', 'pending', true);
mk_user('extra-unverified@school.test', 'voter', 'active', false);
mk_user('extra-admin@school.test', 'admin', 'active', true);
eq($before + 1, eligible_voter_count(),
   'only active, confirmed voter accounts count towards the eligible roll');

/* -------------------------------------------------------------------------- */
group('Photo upload rejects anything that is not an image');

$fakeJpg = sys_get_temp_dir() . '/not_really_' . getmypid() . '.jpg';
file_put_contents($fakeJpg, "<?php echo 'this is a script pretending to be a photo'; ?>");
[$pu1, $puErr] = store_candidate_photo([
    'error' => UPLOAD_ERR_OK, 'tmp_name' => $fakeJpg, 'size' => filesize($fakeJpg), 'name' => 'photo.jpg',
]);
ok($pu1 === false, 'a PHP script renamed to .jpg is rejected');
ok(str_contains($puErr, 'not a readable image'), 'with a clear reason');
unlink($fakeJpg);

// A real image goes through, and comes out re-encoded as a JPEG.
$realPng = sys_get_temp_dir() . '/real_' . getmypid() . '.png';
$im = imagecreatetruecolor(1400, 900);
imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
imagepng($im, $realPng);
imagedestroy($im);

[$pu2, , $stored] = store_candidate_photo([
    'error' => UPLOAD_ERR_OK, 'tmp_name' => $realPng, 'size' => filesize($realPng), 'name' => 'face.png',
]);
ok($pu2 === true && $stored !== '', 'a real PNG is accepted');
$storedPath = config('upload_dir') . '/' . $stored;
ok(is_file($storedPath), 'and written into the uploads folder');
$info = getimagesize($storedPath);
eq(IMAGETYPE_JPEG, $info[2], 'stored as a JPEG regardless of what was uploaded');
ok(max($info[0], $info[1]) <= (int)config('photo_max_px'),
   'and resized down to the configured maximum');
ok(str_ends_with($stored, '.jpg') && !str_contains($stored, '/'),
   'the stored name is a plain filename — no path from the upload survives');
delete_candidate_photo($stored);
ok(!is_file($storedPath), 'deleting a candidate removes the photo file');
unlink($realPng);

[$pu3] = store_candidate_photo(['error' => UPLOAD_ERR_NO_FILE]);
ok($pu3 === true, 'a candidate with no photo at all is fine');

/* -------------------------------------------------------------------------- */
group('Photo path traversal');

delete_candidate_photo('../../../src/db.php');
ok(is_file(dirname(__DIR__) . '/src/db.php'),
   'a photo path trying to escape the uploads folder cannot delete a source file');

/* -------------------------------------------------------------------------- */
group('Mail never leaves the machine in log mode');

$logFile = (string)config('mail_log');
if (is_file($logFile)) { unlink($logFile); }
send_mail('somebody@example.org', 'Test subject', 'Test body');
ok(is_file($logFile), 'the log driver writes the message to a file');
$logged = (string)file_get_contents($logFile);
ok(str_contains($logged, 'somebody@example.org') && str_contains($logged, 'Test subject'),
   'with the recipient and subject intact for inspection');
eq('log', config('mail_driver'), 'and the default driver is the log, not a live transport');

/* ========================================================================== */

echo "\n" . str_repeat('─', 62) . "\n";
printf("\033[1m%d passed, %d failed\033[0m\n", $GLOBALS['pass'], $GLOBALS['fail']);

foreach ([$testDb, $testDb . '-wal', $testDb . '-shm', $logFile] as $f) {
    if (is_file($f)) { unlink($f); }
}

exit($GLOBALS['fail'] > 0 ? 1 : 0);
