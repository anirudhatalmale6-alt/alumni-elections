<?php
declare(strict_types=1);

function admin_dashboard(): void
{
    $user = require_oversight();

    $rows = [];
    foreach (elections_list(true) as $e) {
        $rows[] = [
            'e'         => $e,
            'phase'     => election_phase($e),
            'positions' => count(positions_for((int)$e['id'])),
            'turnout'   => turnout((int)$e['id']),
        ];
    }

    $counts = [
        'eligible' => eligible_voter_count(),
        'pending'  => db_int("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
        'roll'     => db_int('SELECT COUNT(*) FROM alumni_roll'),
        'audit'    => audit_count(),
    ];

    render('admin/dashboard', ['rows' => $rows, 'counts' => $counts, 'user' => $user]);
}

/* ----------------------------------------------------------- elections --- */

function admin_election_new(): void
{
    require_admin();
    render('admin/election_form', ['e' => null, 'errors' => []]);
}

/** Shared validation for create and update. Returns [data, errors]. */
function election_input_from_post(): array
{
    $errors = [];

    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        $errors['title'] = 'Give the election a title.';
    }

    $tz = (string)($_POST['timezone'] ?? 'UTC');
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        $errors['timezone'] = 'Unknown timezone.';
        $tz = 'UTC';
    }

    $starts = local_to_utc((string)($_POST['starts_at'] ?? ''), $tz);
    $ends   = local_to_utc((string)($_POST['ends_at'] ?? ''), $tz);

    if (!$starts) {
        $errors['starts_at'] = 'Set when voting opens.';
    }
    if (!$ends) {
        $errors['ends_at'] = 'Set when voting closes.';
    }
    if ($starts && $ends && $ends <= $starts) {
        $errors['ends_at'] = 'Voting must close after it opens.';
    }

    $mode = (string)($_POST['results_mode'] ?? 'after_close');
    if (!in_array($mode, ['live', 'after_close', 'manual'], true)) {
        $mode = 'after_close';
    }

    return [[
        'title'        => $title,
        'description'  => trim((string)($_POST['description'] ?? '')),
        'timezone'     => $tz,
        'starts_at'    => $starts,
        'ends_at'      => $ends,
        'results_mode' => $mode,
    ], $errors];
}

function admin_election_create(): void
{
    $user = require_admin();
    [$d, $errors] = election_input_from_post();

    if ($errors) {
        render('admin/election_form', ['e' => $d + ['id' => null], 'errors' => $errors]);
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO elections (title, description, starts_at, ends_at, timezone, results_mode,
                                created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $d['title'], $d['description'], $d['starts_at'], $d['ends_at'],
        $d['timezone'], $d['results_mode'], (int)$user['id'], now(), now(),
    ]);
    $id = (int)db()->lastInsertId();

    audit('election.created', 'election', $id, ['title' => $d['title']]);
    flash('success', 'Election created. Add the positions and candidates next.');
    redirect('/admin/elections/' . $id);
}

function admin_election_edit(int $id): void
{
    require_admin();
    $e = election_find($id);
    if (!$e) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }
    render('admin/election_manage', [
        'e'         => $e,
        'phase'     => election_phase($e),
        'structure' => ballot_structure($id),
        'turnout'   => turnout($id),
        'errors'    => [],
    ]);
}

function admin_election_update(int $id): void
{
    require_admin();
    $e = election_find($id);
    if (!$e) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }

    [$d, $errors] = election_input_from_post();
    if ($errors) {
        render('admin/election_manage', [
            'e'         => array_merge($e, array_filter($d, static fn($v) => $v !== null)),
            'phase'     => election_phase($e),
            'structure' => ballot_structure($id),
            'turnout'   => turnout($id),
            'errors'    => $errors,
        ]);
        return;
    }

    $stmt = db()->prepare(
        'UPDATE elections SET title = ?, description = ?, starts_at = ?, ends_at = ?,
                              timezone = ?, results_mode = ?, updated_at = ?
          WHERE id = ?'
    );
    $stmt->execute([
        $d['title'], $d['description'], $d['starts_at'], $d['ends_at'],
        $d['timezone'], $d['results_mode'], now(), $id,
    ]);

    audit('election.updated', 'election', $id, [
        'before' => ['starts_at' => $e['starts_at'], 'ends_at' => $e['ends_at'], 'results_mode' => $e['results_mode']],
        'after'  => ['starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'], 'results_mode' => $d['results_mode']],
    ]);

    flash('success', 'Election updated.');
    redirect('/admin/elections/' . $id);
}

function admin_election_publish(int $id): void
{
    require_admin();
    $e = election_find($id);
    if (!$e) {
        redirect('/admin');
    }

    $publish = ($_POST['action'] ?? 'publish') === 'publish';
    $stmt = db()->prepare('UPDATE elections SET results_published_at = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$publish ? now() : null, now(), $id]);

    audit($publish ? 'election.results_published' : 'election.results_unpublished', 'election', $id, [
        'title' => $e['title'],
    ]);

    flash('success', $publish ? 'Results are now public.' : 'Results are hidden again.');
    redirect('/admin/elections/' . $id);
}

function admin_election_archive(int $id): void
{
    require_admin();
    $e = election_find($id);
    if (!$e) {
        redirect('/admin');
    }

    $archive = ((int)$e['is_archived']) === 0;
    $stmt = db()->prepare('UPDATE elections SET is_archived = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$archive ? 1 : 0, now(), $id]);

    audit($archive ? 'election.archived' : 'election.unarchived', 'election', $id, ['title' => $e['title']]);
    flash('success', $archive ? 'Election archived — it no longer shows on the public page.' : 'Election restored.');
    redirect('/admin/elections/' . $id);
}

/* ----------------------------------------------------------- positions --- */

/**
 * Elections that have started must not have their ballot paper rewritten
 * underneath the people already voting on it.
 */
function ballot_is_locked(array $e): bool
{
    return election_phase($e) !== 'upcoming'
        || db_int('SELECT COUNT(*) FROM vote_receipts WHERE election_id = ?', [(int)$e['id']]) > 0;
}

function admin_position_create(int $electionId): void
{
    require_admin();
    $e = election_find($electionId);
    if (!$e) {
        redirect('/admin');
    }
    if (ballot_is_locked($e)) {
        flash('error', 'Voting has opened on this election, so the ballot paper is locked. '
                     . 'Positions can only be added before it opens.');
        redirect('/admin/elections/' . $electionId);
    }

    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') {
        flash('error', 'A position needs a title.');
        redirect('/admin/elections/' . $electionId);
    }

    $order = db_int('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM positions WHERE election_id = ?',
                    [$electionId]);

    $stmt = db()->prepare(
        'INSERT INTO positions (election_id, title, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$electionId, $title, trim((string)($_POST['description'] ?? '')), $order, now()]);

    audit('position.created', 'position', (int)db()->lastInsertId(), ['election_id' => $electionId, 'title' => $title]);
    flash('success', 'Position "' . $title . '" added.');
    redirect('/admin/elections/' . $electionId);
}

function admin_position_delete(int $id): void
{
    require_admin();
    $p = position_find($id);
    if (!$p) {
        redirect('/admin');
    }
    $e = election_find((int)$p['election_id']);

    if ($e && ballot_is_locked($e)) {
        flash('error', 'Ballots have been cast against this position, so it cannot be deleted. '
                     . 'Deleting it would destroy votes that are already recorded.');
        redirect('/admin/elections/' . (int)$p['election_id']);
    }

    foreach (candidates_for($id) as $c) {
        delete_candidate_photo($c['photo_path']);
    }

    $stmt = db()->prepare('DELETE FROM positions WHERE id = ?');
    $stmt->execute([$id]);

    audit('position.deleted', 'position', $id, ['election_id' => (int)$p['election_id'], 'title' => $p['title']]);
    flash('success', 'Position removed.');
    redirect('/admin/elections/' . (int)$p['election_id']);
}

/* ---------------------------------------------------------- candidates --- */

function admin_candidate_create(int $positionId): void
{
    require_admin();
    $p = position_find($positionId);
    if (!$p) {
        redirect('/admin');
    }
    $electionId = (int)$p['election_id'];
    $e = election_find($electionId);

    if ($e && ballot_is_locked($e)) {
        flash('error', 'Voting has opened, so candidates can no longer be added to this ballot.');
        redirect('/admin/elections/' . $electionId);
    }

    $name = trim((string)($_POST['full_name'] ?? ''));
    if ($name === '') {
        flash('error', 'A candidate needs a name.');
        redirect('/admin/elections/' . $electionId);
    }

    [$ok, $err, $photo] = store_candidate_photo($_FILES['photo'] ?? []);
    if (!$ok) {
        flash('error', $err);
        redirect('/admin/elections/' . $electionId);
    }

    $order = db_int('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM candidates WHERE position_id = ?',
                    [$positionId]);

    $stmt = db()->prepare(
        'INSERT INTO candidates (position_id, full_name, headline, bio, photo_path, sort_order, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $positionId, $name,
        trim((string)($_POST['headline'] ?? '')),
        trim((string)($_POST['bio'] ?? '')),
        $photo ?: null, $order, now(),
    ]);

    audit('candidate.created', 'candidate', (int)db()->lastInsertId(), [
        'position_id' => $positionId, 'name' => $name, 'photo' => (bool)$photo,
    ]);
    flash('success', $name . ' added to ' . $p['title'] . '.');
    redirect('/admin/elections/' . $electionId);
}

function admin_candidate_update(int $id): void
{
    require_admin();
    $c = candidate_find($id);
    if (!$c) {
        redirect('/admin');
    }
    $electionId = (int)$c['election_id'];

    $name = trim((string)($_POST['full_name'] ?? ''));
    if ($name === '') {
        flash('error', 'A candidate needs a name.');
        redirect('/admin/elections/' . $electionId);
    }

    [$ok, $err, $photo] = store_candidate_photo($_FILES['photo'] ?? []);
    if (!$ok) {
        flash('error', $err);
        redirect('/admin/elections/' . $electionId);
    }

    if ($photo) {
        delete_candidate_photo($c['photo_path']);
    }

    $stmt = db()->prepare(
        'UPDATE candidates SET full_name = ?, headline = ?, bio = ?, photo_path = COALESCE(?, photo_path)
          WHERE id = ?'
    );
    $stmt->execute([
        $name,
        trim((string)($_POST['headline'] ?? '')),
        trim((string)($_POST['bio'] ?? '')),
        $photo ?: null,
        $id,
    ]);

    audit('candidate.updated', 'candidate', $id, ['name' => $name, 'new_photo' => (bool)$photo]);
    flash('success', 'Candidate profile updated.');
    redirect('/admin/elections/' . $electionId);
}

function admin_candidate_delete(int $id): void
{
    require_admin();
    $c = candidate_find($id);
    if (!$c) {
        redirect('/admin');
    }
    $electionId = (int)$c['election_id'];

    if (db_int('SELECT COUNT(*) FROM ballots WHERE candidate_id = ?', [$id]) > 0) {
        flash('error', 'Votes have already been cast for ' . $c['full_name']
                     . ', so this candidate cannot be deleted. Deleting would change a recorded result.');
        redirect('/admin/elections/' . $electionId);
    }

    delete_candidate_photo($c['photo_path']);
    $del = db()->prepare('DELETE FROM candidates WHERE id = ?');
    $del->execute([$id]);

    audit('candidate.deleted', 'candidate', $id, ['name' => $c['full_name'], 'election_id' => $electionId]);
    flash('success', $c['full_name'] . ' removed.');
    redirect('/admin/elections/' . $electionId);
}

/* ------------------------------------------------------------- turnout --- */

function admin_turnout(int $id): void
{
    require_oversight();
    $e = election_find($id);
    if (!$e) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }

    $perPosition = [];
    foreach (positions_for($id) as $p) {
        $submitted = db_int('SELECT COUNT(*) FROM vote_receipts WHERE position_id = ?', [(int)$p['id']]);
        $cast      = db_int('SELECT COUNT(*) FROM ballots WHERE position_id = ?', [(int)$p['id']]);

        $perPosition[] = [
            'position'    => $p,
            'submitted'   => $submitted,
            'cast'        => $cast,
            'abstentions' => max(0, $submitted - $cast),
        ];
    }

    // Hourly shape of the turnout. Times are kept in UTC here and rendered in
    // the election's timezone, so a late-night surge is not an artefact.
    $stmt = db()->prepare(
        "SELECT substr(cast_at, 1, 13) AS hour, COUNT(DISTINCT user_id) AS n
           FROM vote_receipts WHERE election_id = ?
          GROUP BY hour ORDER BY hour"
    );
    $stmt->execute([$id]);

    render('admin/turnout', [
        'e'           => $e,
        'phase'       => election_phase($e),
        'turnout'     => turnout($id),
        'perPosition' => $perPosition,
        'byHour'      => $stmt->fetchAll(),
        'integrity'   => verify_ballot_chain($id),
    ]);
}

/* --------------------------------------------------------------- users --- */

function admin_users(): void
{
    require_admin();

    $q      = trim((string)($_GET['q'] ?? ''));
    $status = (string)($_GET['status'] ?? '');

    $sql    = 'SELECT * FROM users WHERE 1=1';
    $params = [];

    if ($q !== '') {
        $sql .= ' AND (email_norm LIKE ? OR lower(full_name) LIKE ?)';
        $like = '%' . mb_strtolower($q, 'UTF-8') . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($status, ['pending', 'active', 'suspended'], true)) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    render('admin/users', ['users' => $stmt->fetchAll(), 'q' => $q, 'status' => $status]);
}

function admin_user_status(int $id): void
{
    $me = require_admin();

    $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        redirect('/admin/users');
    }

    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, ['pending', 'active', 'suspended'], true)) {
        flash('error', 'Unknown status.');
        redirect('/admin/users');
    }
    if ((int)$u['id'] === (int)$me['id'] && $status !== 'active') {
        flash('error', 'You cannot suspend your own administrator account.');
        redirect('/admin/users');
    }

    $upd = db()->prepare('UPDATE users SET status = ?, updated_at = ? WHERE id = ?');
    $upd->execute([$status, now(), $id]);

    audit('user.status_changed', 'user', $id, [
        'email' => $u['email'], 'from' => $u['status'], 'to' => $status,
    ]);

    if ($status === 'active' && $u['status'] === 'pending') {
        mail_account_approved($u);
    }

    flash('success', $u['email'] . ' is now ' . $status . '.');
    redirect('/admin/users');
}

function admin_user_role(int $id): void
{
    $me = require_admin();

    $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        redirect('/admin/users');
    }

    $role = (string)($_POST['role'] ?? '');
    if (!in_array($role, ['admin', 'voter', 'auditor'], true)) {
        flash('error', 'Unknown role.');
        redirect('/admin/users');
    }
    if ((int)$u['id'] === (int)$me['id'] && $role !== 'admin') {
        flash('error', 'You cannot remove your own administrator role — '
                     . 'ask another admin to do it, so the site is never left without one.');
        redirect('/admin/users');
    }
    if ($role !== 'voter' && has_voted_for_any($id)) {
        flash('error', $u['email'] . ' has already voted, so this account must stay a voter for the '
                     . 'audit trail to make sense. Create a separate account for the oversight role.');
        redirect('/admin/users');
    }

    $upd = db()->prepare('UPDATE users SET role = ?, updated_at = ? WHERE id = ?');
    $upd->execute([$role, now(), $id]);

    audit('user.role_changed', 'user', $id, ['email' => $u['email'], 'from' => $u['role'], 'to' => $role]);
    flash('success', $u['email'] . ' is now ' . $role . '.');
    redirect('/admin/users');
}

function has_voted_for_any(int $userId): bool
{
    return db_val('SELECT 1 FROM vote_receipts WHERE user_id = ? LIMIT 1', [$userId]) !== null;
}

/* ---------------------------------------------------------------- roll --- */

function admin_roll(): void
{
    require_admin();
    $rows = db()->query('SELECT * FROM alumni_roll ORDER BY email_norm')->fetchAll();

    // Which roll entries have turned into real accounts.
    $registered = [];
    foreach (db()->query('SELECT email_norm FROM users')->fetchAll(PDO::FETCH_COLUMN) as $em) {
        $registered[$em] = true;
    }

    render('admin/roll', [
        'rows'       => $rows,
        'registered' => $registered,
        'mode'       => (string)config('registration'),
    ]);
}

function admin_roll_add(): void
{
    $me   = require_admin();
    $blob = (string)($_POST['emails'] ?? '');

    // Accept one per line, comma separated, or pasted out of a spreadsheet.
    $parts = preg_split('/[\s,;]+/u', $blob) ?: [];

    $added = 0;
    $dupes = 0;
    $bad   = [];

    $stmt = db()->prepare(
        'INSERT OR IGNORE INTO alumni_roll (email_norm, note, added_by, created_at) VALUES (?, ?, ?, ?)'
    );
    $note = trim((string)($_POST['note'] ?? ''));

    foreach ($parts as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $bad[] = $raw;
            continue;
        }
        $stmt->execute([normalize_email($raw), $note, (int)$me['id'], now()]);
        if ($stmt->rowCount() > 0) {
            $added++;
        } else {
            $dupes++;
        }
    }

    audit('roll.imported', 'alumni_roll', null, ['added' => $added, 'duplicates' => $dupes, 'rejected' => count($bad)]);

    $msg = $added . ' address' . ($added === 1 ? '' : 'es') . ' added to the roll.';
    if ($dupes) {
        $msg .= ' ' . $dupes . ' were already there.';
    }
    flash('success', $msg);

    if ($bad) {
        flash('error', count($bad) . ' entr' . (count($bad) === 1 ? 'y was' : 'ies were')
                     . ' not a valid email address and were skipped: '
                     . implode(', ', array_slice($bad, 0, 10)) . (count($bad) > 10 ? ' …' : ''));
    }

    redirect('/admin/roll');
}

function admin_roll_delete(int $id): void
{
    require_admin();
    $row = db_row('SELECT * FROM alumni_roll WHERE id = ?', [$id]);
    if (!$row) {
        redirect('/admin/roll');
    }

    db_run('DELETE FROM alumni_roll WHERE id = ?', [$id]);

    audit('roll.removed', 'alumni_roll', $id, ['email' => $row['email_norm']]);
    flash('success', $row['email_norm'] . ' removed from the roll. '
                   . 'Any account they already created still exists — suspend it separately if that is what you want.');
    redirect('/admin/roll');
}

/* --------------------------------------------------------------- audit --- */

function admin_audit(): void
{
    require_oversight();
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = 100;
    $offset = ($page - 1) * $per;

    render('admin/audit', [
        'entries' => audit_page($per, $offset),
        'total'   => audit_count(),
        'page'    => $page,
        'per'     => $per,
    ]);
}
