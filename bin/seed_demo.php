<?php
declare(strict_types=1);

/**
 * Sample data so the site can be clicked through immediately.
 * Run through the console: php bin/console.php seed-demo
 */

if (!function_exists('out')) {
    exit("Run this through bin/console.php seed-demo\n");
}

$pdo = db();

/* --- accounts ------------------------------------------------------------ */

upsert_account('admin@alumni.test',   'admin12345',   'admin',   'Ruth Adeyemi');
upsert_account('auditor@alumni.test', 'auditor12345', 'auditor', 'Samuel Okafor');

$voters = [
    ['voter1@alumni.test',  'Priya Menon',      '2009'],
    ['voter2@alumni.test',  'Daniel Osei',      '2011'],
    ['voter3@alumni.test',  'Aisha Bello',      '2014'],
    ['voter4@alumni.test',  'Marcus Lindqvist', '2007'],
    ['voter5@alumni.test',  'Chen Wei',         '2016'],
    ['voter6@alumni.test',  'Fatima Zahra',     '2012'],
    ['voter7@alumni.test',  'Tomás Ferreira',   '2010'],
    ['voter8@alumni.test',  'Grace Mwangi',     '2018'],
];

$rollStmt = $pdo->prepare('INSERT OR IGNORE INTO alumni_roll (email_norm, note, created_at) VALUES (?, ?, ?)');

foreach ($voters as [$email, $name, $year]) {
    upsert_account($email, 'voter12345', 'voter', $name);
    $upd = $pdo->prepare('UPDATE users SET grad_year = ? WHERE email_norm = ?');
    $upd->execute([$year, normalize_email($email)]);
    $rollStmt->execute([normalize_email($email), 'demo seed', now()]);
}
$rollStmt->execute([normalize_email('admin@alumni.test'), 'demo seed', now()]);

/* --- an election that is open right now ---------------------------------- */

$opens  = gmdate('Y-m-d H:i:s', time() - 86400);      // opened yesterday
$closes = gmdate('Y-m-d H:i:s', time() + (86400 * 6)); // closes in six days

$stmt = $pdo->prepare(
    'INSERT INTO elections (title, description, starts_at, ends_at, timezone, results_mode,
                            created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    'Alumni Association Executive 2026',
    "Elections for the three executive posts of the Alumni Association for the 2026–2028 term.\n"
    . "Every registered alumnus has one vote per position. Voting closes automatically at the "
    . "time shown below and the results are published the moment it does.",
    $opens, $closes, 'Africa/Lagos', 'after_close', now(), now(),
]);
$electionId = (int)$pdo->lastInsertId();
out('Created election #' . $electionId . ' (open now).');

$ballot = [
    ['President', 'Chairs the association and represents alumni to the university.', [
        ['Ngozi Eze',        'Class of 2005 · Chair, Lagos chapter',
         "Three terms on the chapter committee and eight years running the mentoring scheme.\n\n"
         . "I want the association to be useful to people in their first five years out, not only at reunions. "
         . "That means a working mentor register, a small hardship fund, and accounts published every quarter."],
        ['Robert Adeniyi',   'Class of 1998 · Former treasurer',
         "I ran the association's accounts for four years and left it with a surplus for the first time.\n\n"
         . "My priority is the endowment: a transparent investment policy, an annual report every member can read, "
         . "and scholarships awarded on a published rubric rather than by committee preference."],
        ['Halima Sule',      'Class of 2013 · Founder, alumni tech network',
         "The association has 11,000 members on paper and about 300 who do anything.\n\n"
         . "I would fix that with regional chapters that have real budgets, an events calendar people can "
         . "actually find, and a membership database that is not a spreadsheet."],
    ]],
    ['Secretary', 'Keeps the records, minutes and the membership register.', [
        ['Peter Kimani',     'Class of 2010 · Records officer',
         "Minutes circulated within 48 hours, a register that is reconciled monthly, and an archive "
         . "that members can search. Unglamorous, but it is the job."],
        ['Sandra Duarte',    'Class of 2015 · Communications volunteer',
         "I have written the newsletter for three years. I would merge it with the register so that "
         . "we stop emailing 4,000 dead addresses and start reaching the people who are actually there."],
    ]],
    ['Treasurer', 'Responsible for the accounts, the endowment and the annual audit.', [
        ['Ibrahim Danjuma',  'Class of 2008 · Chartered accountant',
         "Quarterly management accounts, an external audit every year, and every expense over a set "
         . "threshold published line by line. No exceptions for the executive."],
        ['Lucy Nwosu',       'Class of 2017 · Finance analyst',
         "The endowment has sat in a current account for six years. I would put a written investment "
         . "policy to the membership for a vote and then follow it."],
        ['Kwame Boateng',    'Class of 2003 · Small business owner',
         "I have run payroll for 40 staff for a decade. I will keep the books boring, on time, and open."],
    ]],
];

$posStmt = $pdo->prepare(
    'INSERT INTO positions (election_id, title, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?)'
);
$canStmt = $pdo->prepare(
    'INSERT INTO candidates (position_id, full_name, headline, bio, sort_order, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);

$order = 0;
foreach ($ballot as [$ptitle, $pdesc, $cands]) {
    $posStmt->execute([$electionId, $ptitle, $pdesc, $order++, now()]);
    $pid = (int)$pdo->lastInsertId();
    $co  = 0;
    foreach ($cands as [$cname, $headline, $bio]) {
        $canStmt->execute([$pid, $cname, $headline, $bio, $co++, now()]);
    }
    out('  position: ' . $ptitle . ' (' . count($cands) . ' candidates)');
}

/* --- an election that has not opened yet --------------------------------- */

$stmt->execute([
    'Class of 2016 Reunion Committee',
    'Choosing the committee that will organise the ten-year reunion.',
    gmdate('Y-m-d H:i:s', time() + (86400 * 14)),
    gmdate('Y-m-d H:i:s', time() + (86400 * 21)),
    'Africa/Lagos', 'manual', now(), now(),
]);
$upcomingId = (int)$pdo->lastInsertId();
$posStmt->execute([$upcomingId, 'Committee Chair', 'Leads the reunion committee.', 0, now()]);
$pid = (int)$pdo->lastInsertId();
foreach ([['Zainab Idris', 'Class of 2016'], ['Oliver Grant', 'Class of 2016']] as $i => [$n, $h]) {
    $canStmt->execute([$pid, $n, $h, 'Standing for chair of the reunion committee.', $i, now()]);
}
out('Created election #' . $upcomingId . ' (opens in two weeks).');

/* --- cast a handful of real ballots through the real code path ----------- */

$election = election_find($electionId);
$structure = ballot_structure($electionId);

$pattern = [
    [0, 0, 0], [0, 1, 1], [2, 0, 0], [0, 0, 2], [1, 1, 0],
];

// The last three voters are left with an unused ballot so the voting flow can
// be walked through on a fresh install without resetting anything.
$cast = 0;
foreach (array_slice($voters, 0, count($pattern)) as $i => [$email]) {
    $u = find_user_by_email($email);
    $choices = [];
    foreach ($structure as $k => $pos) {
        $pick = $pattern[$i][$k] ?? 0;
        $choices[(int)$pos['id']] = (int)($pos['candidates'][$pick]['id'] ?? 0);
    }
    [$ok, $err] = cast_ballot($election, $u, $choices);
    if ($ok) {
        $cast++;
    } else {
        out('  ballot for ' . $email . ' rejected: ' . $err);
    }
}
out($cast . ' demo ballots cast.');

$chain = verify_ballot_chain($electionId);
out('Chain check: ' . ($chain['ok'] ? 'intact' : 'BROKEN at #' . $chain['broken_at'])
    . ' (' . $chain['checked'] . ' ballots).');

out('');
out('Sign in with:');
out('  admin@alumni.test   / admin12345     (admin)');
out('  auditor@alumni.test / auditor12345   (auditor, read-only)');
out('  voter1@alumni.test  / voter12345     (has already voted)');
out('  voter6@alumni.test  / voter12345     (has NOT voted — use this to try the ballot)');
out('  voter7 and voter8 @alumni.test also still have their ballots, same password.');
