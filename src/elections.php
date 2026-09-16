<?php
declare(strict_types=1);

const BALLOT_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

/* --------------------------------------------------------------- reads --- */

function election_find(int $id): ?array
{
    return db_row('SELECT * FROM elections WHERE id = ?', [$id]);
}

/**
 * Elections in the order a reader actually wants them: whatever is open to vote
 * in right now first, then what is coming up soonest, then the finished ones
 * most recent first.
 */
function elections_list(bool $includeArchived = false): array
{
    $now = now();

    $sql = 'SELECT *,
                   CASE
                     WHEN ? >= starts_at AND ? < ends_at THEN 0
                     WHEN ? <  starts_at                 THEN 1
                     ELSE 2
                   END AS phase_rank
              FROM elections';
    if (!$includeArchived) {
        $sql .= ' WHERE is_archived = 0';
    }
    $sql .= ' ORDER BY phase_rank ASC,
                       CASE WHEN phase_rank = 1 THEN starts_at END ASC,
                       ends_at DESC,
                       id DESC';

    return db_all($sql, [$now, $now, $now]);
}

function positions_for(int $electionId): array
{
    $stmt = db()->prepare('SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order, id');
    $stmt->execute([$electionId]);
    return $stmt->fetchAll();
}

function position_find(int $id): ?array
{
    return db_row('SELECT * FROM positions WHERE id = ?', [$id]);
}

function candidates_for(int $positionId): array
{
    $stmt = db()->prepare('SELECT * FROM candidates WHERE position_id = ? ORDER BY sort_order, id');
    $stmt->execute([$positionId]);
    return $stmt->fetchAll();
}

function candidate_find(int $id): ?array
{
    return db_row(
        'SELECT c.*, p.title AS position_title, p.election_id, e.title AS election_title, e.timezone
           FROM candidates c
           JOIN positions p ON p.id = c.position_id
           JOIN elections e ON e.id = p.election_id
          WHERE c.id = ?',
        [$id]
    );
}

/** Every position of an election with its candidates nested under 'candidates'. */
function ballot_structure(int $electionId): array
{
    $out = [];
    foreach (positions_for($electionId) as $p) {
        $p['candidates'] = candidates_for((int)$p['id']);
        $out[] = $p;
    }
    return $out;
}

/* --------------------------------------------------------------- phase --- */

/** 'upcoming' | 'open' | 'closed', decided on the server clock, never the browser's. */
function election_phase(array $e, ?string $atUtc = null): string
{
    $at = $atUtc ?? now();
    if ($at < $e['starts_at']) {
        return 'upcoming';
    }
    if ($at >= $e['ends_at']) {
        return 'closed';
    }
    return 'open';
}

function phase_label(string $phase): string
{
    return ['upcoming' => 'Not open yet', 'open' => 'Voting open', 'closed' => 'Voting closed'][$phase] ?? $phase;
}

/**
 * May this viewer see the numbers?
 *  live         - as soon as voting opens, to everyone
 *  after_close  - automatically once the window ends
 *  manual       - only after an admin presses Publish
 * Admins and auditors always see them, so turnout can be watched during voting.
 */
function results_visible(array $e, ?array $viewer = null): bool
{
    $viewer ??= current_user();
    if ($viewer && in_array($viewer['role'], ['admin', 'auditor'], true)) {
        return true;
    }
    if ($e['results_published_at']) {
        return true;
    }
    $phase = election_phase($e);
    if ($e['results_mode'] === 'live' && $phase !== 'upcoming') {
        return true;
    }
    if ($e['results_mode'] === 'after_close' && $phase === 'closed') {
        return true;
    }
    return false;
}

/* ------------------------------------------------------------ receipts --- */

function has_voted_for_position(int $userId, int $positionId): bool
{
    return db_val('SELECT 1 FROM vote_receipts WHERE user_id = ? AND position_id = ?',
                  [$userId, $positionId]) !== null;
}

/** position_id => receipt row, for every position of this election this user has settled. */
function receipts_for_user(int $userId, int $electionId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM vote_receipts WHERE user_id = ? AND election_id = ?',
                    [$userId, $electionId]) as $r) {
        $out[(int)$r['position_id']] = $r;
    }
    return $out;
}

function has_voted_in_election(int $userId, int $electionId): bool
{
    return db_val('SELECT 1 FROM vote_receipts WHERE user_id = ? AND election_id = ? LIMIT 1',
                  [$userId, $electionId]) !== null;
}

/* --------------------------------------------------------- hash chain --- */

function last_ballot_hash(PDO $pdo, int $electionId): string
{
    $stmt = $pdo->prepare('SELECT hash FROM ballots WHERE election_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$electionId]);
    $h = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $h !== false ? (string)$h : BALLOT_GENESIS;
}

function ballot_hash(string $prev, int $electionId, int $positionId, int $candidateId, string $castAt, string $nonce): string
{
    return hash('sha256', implode('|', [$prev, $electionId, $positionId, $candidateId, $castAt, $nonce]));
}

/**
 * Recompute the whole chain for an election.
 * Returns ['ok' => bool, 'checked' => int, 'broken_at' => ?int].
 * If a row was edited or removed straight in the database, the recomputed hash
 * stops matching from that row onwards and this reports the first bad id.
 */
function verify_ballot_chain(int $electionId): array
{
    $prev    = BALLOT_GENESIS;
    $checked = 0;

    foreach (db_all('SELECT * FROM ballots WHERE election_id = ? ORDER BY id', [$electionId]) as $b) {
        $expected = ballot_hash(
            $prev,
            (int)$b['election_id'],
            (int)$b['position_id'],
            (int)$b['candidate_id'],
            (string)$b['cast_at'],
            (string)$b['nonce']
        );
        if ($b['prev_hash'] !== $prev || !hash_equals($expected, (string)$b['hash'])) {
            return ['ok' => false, 'checked' => $checked, 'broken_at' => (int)$b['id']];
        }
        $prev = (string)$b['hash'];
        $checked++;
    }

    return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
}

/* -------------------------------------------------------------- voting --- */

/**
 * Record one voter's ballot for an election.
 *
 * $choices maps position_id => candidate_id, or position_id => 0 to abstain.
 * Every position on the paper is settled in one submission: a skipped position
 * is recorded as an abstention, so a voter cannot come back later and fill it
 * in after seeing partial numbers. That mirrors handing in a paper ballot.
 *
 * Returns [ok, error|'', receiptCode|''].
 */
function cast_ballot(array $election, array $user, array $choices): array
{
    if (!can_vote($user)) {
        return [false, 'This account is not eligible to vote.', ''];
    }
    if (election_phase($election) !== 'open') {
        return [false, 'Voting is not open for this election.', ''];
    }

    $electionId = (int)$election['id'];
    $structure  = ballot_structure($electionId);
    if (!$structure) {
        return [false, 'This election has no positions to vote on yet.', ''];
    }

    // Validate every choice against what is actually on this election's ballot
    // before touching the database.
    $validated = [];
    $anyPick   = false;

    foreach ($structure as $pos) {
        $pid    = (int)$pos['id'];
        $picked = (int)($choices[$pid] ?? 0);

        if ($picked === 0) {
            $validated[$pid] = 0;       // abstention
            continue;
        }

        $ok = false;
        foreach ($pos['candidates'] as $c) {
            if ((int)$c['id'] === $picked) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return [false, 'That candidate is not standing for the position you tried to vote on.', ''];
        }
        $validated[$pid] = $picked;
        $anyPick = true;
    }

    if (!$anyPick) {
        return [false, 'Choose at least one candidate, or use the Abstain option on each position deliberately.', ''];
    }

    $userId  = (int)$user['id'];
    $receipt = strtoupper(bin2hex(random_bytes(5)));
    $castAt  = now();
    $pdo     = db();

    // Take the write lock up front, so two ballots arriving at the same moment
    // serialise here instead of colliding halfway through. begin_write retries
    // while another writer holds the lock rather than failing the voter.
    if (!begin_write($pdo)) {
        return [false, 'The site is busy recording other ballots at the moment. '
                     . 'Your vote has NOT been counted yet — please submit again in a few seconds.', ''];
    }

    try {
        $prev = last_ballot_hash($pdo, $electionId);

        $insReceipt = $pdo->prepare(
            'INSERT INTO vote_receipts (election_id, position_id, user_id, receipt_code, cast_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insBallot = $pdo->prepare(
            'INSERT INTO ballots (election_id, position_id, candidate_id, cast_at, nonce, prev_hash, hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($validated as $pid => $candidateId) {
            // The receipt goes in first. Its UNIQUE(election, position, user)
            // is what actually stops a second vote — a refresh, a double click
            // or two tabs racing all land on this constraint.
            $insReceipt->execute([$electionId, $pid, $userId, $receipt, $castAt]);

            if ($candidateId > 0) {
                $nonce = bin2hex(random_bytes(8));
                $hash  = ballot_hash($prev, $electionId, $pid, $candidateId, $castAt, $nonce);
                $insBallot->execute([$electionId, $pid, $candidateId, $castAt, $nonce, $prev, $hash]);
                $prev  = $hash;
            }
        }

        $pdo->exec('COMMIT');
    } catch (PDOException $ex) {
        $pdo->exec('ROLLBACK');
        if (str_contains($ex->getMessage(), 'UNIQUE') || (string)$ex->getCode() === '23000') {
            return [false, 'A vote has already been recorded for this account in this election. '
                         . 'Each alumnus votes once.', ''];
        }
        throw $ex;
    }

    // Logged without the choices: the audit trail proves a ballot was accepted,
    // it must not record who it was for.
    audit('ballot.cast', 'election', $electionId, [
        'receipt'   => $receipt,
        'positions' => count($validated),
    ]);

    return [true, '', $receipt];
}

/* -------------------------------------------------------------- tally ---- */

function eligible_voter_count(): int
{
    return db_int(
        "SELECT COUNT(*) FROM users
          WHERE role = 'voter' AND status = 'active' AND email_verified_at IS NOT NULL"
    );
}

/** Turnout for an election: distinct voters who submitted, against the eligible roll. */
function turnout(int $electionId): array
{
    $voted    = db_int('SELECT COUNT(DISTINCT user_id) FROM vote_receipts WHERE election_id = ?',
                       [$electionId]);
    $eligible = eligible_voter_count();
    $pct      = $eligible > 0 ? round($voted * 100 / $eligible, 1) : 0.0;

    return ['voted' => $voted, 'eligible' => $eligible, 'pct' => $pct];
}

/**
 * Result for one position: every candidate with their count (including zero),
 * highest first, plus abstentions and the ballots-submitted total.
 */
function tally_position(int $positionId): array
{
    $counts = [];
    foreach (db_all(
        'SELECT candidate_id, COUNT(*) AS n FROM ballots WHERE position_id = ? GROUP BY candidate_id',
        [$positionId]
    ) as $row) {
        $counts[(int)$row['candidate_id']] = (int)$row['n'];
    }

    $rows  = [];
    $votes = 0;
    foreach (candidates_for($positionId) as $c) {
        $n = $counts[(int)$c['id']] ?? 0;   // candidates with no votes must still appear
        $votes += $n;
        $c['votes'] = $n;
        $rows[] = $c;
    }

    $submitted = db_int('SELECT COUNT(*) FROM vote_receipts WHERE position_id = ?', [$positionId]);

    usort($rows, static fn(array $a, array $b) => $b['votes'] <=> $a['votes']
        ?: strcmp((string)$a['full_name'], (string)$b['full_name']));

    foreach ($rows as &$r) {
        $r['pct'] = $votes > 0 ? round($r['votes'] * 100 / $votes, 1) : 0.0;
    }
    unset($r);

    // A tie only counts as a tie if somebody actually voted.
    $leaders = [];
    if ($votes > 0) {
        $top = (int)$rows[0]['votes'];
        foreach ($rows as $r) {
            if ((int)$r['votes'] === $top) {
                $leaders[] = $r['full_name'];
            }
        }
    }

    return [
        'rows'        => $rows,
        'votes'       => $votes,
        'submitted'   => $submitted,
        'abstentions' => max(0, $submitted - $votes),
        'leaders'     => $leaders,
        'tied'        => count($leaders) > 1,
    ];
}

/* ------------------------------------------------------------- photos ---- */

/**
 * Accept a candidate photo. The file is decoded and re-encoded with GD rather
 * than moved, so whatever the extension claims, only a real image survives.
 * Returns [ok, error|'', relativePath|''].
 */
function store_candidate_photo(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [true, '', ''];   // no photo supplied is fine
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'The photo did not upload correctly (error code ' . (int)$file['error'] . ').', ''];
    }

    $maxBytes = (int)config('photo_max_mb') * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return [false, 'That photo is larger than ' . config('photo_max_mb') . ' MB.', ''];
    }

    $tmp  = (string)$file['tmp_name'];
    $info = @getimagesize($tmp);
    if (!$info) {
        return [false, 'That file is not a readable image.', ''];
    }

    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
        IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
        default        => false,
    };
    if (!$src) {
        return [false, 'Only JPEG, PNG, GIF or WebP photos are accepted.', ''];
    }

    $maxPx = (int)config('photo_max_px');
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $maxPx / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $dir = (string)config('upload_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'cand_' . bin2hex(random_bytes(8)) . '.jpg';
    $ok   = imagejpeg($dst, $dir . '/' . $name, 85);
    imagedestroy($dst);

    if (!$ok) {
        return [false, 'The photo could not be saved on the server.', ''];
    }
    return [true, '', $name];
}

function candidate_photo_url(?string $photo): ?string
{
    if (!$photo) {
        return null;
    }
    return rtrim((string)config('upload_url'), '/') . '/' . $photo;
}

function delete_candidate_photo(?string $photo): void
{
    if (!$photo) {
        return;
    }
    // Defend against a stored value trying to walk out of the upload folder.
    $base = basename($photo);
    $path = rtrim((string)config('upload_dir'), '/') . '/' . $base;
    if (is_file($path)) {
        @unlink($path);
    }
}
