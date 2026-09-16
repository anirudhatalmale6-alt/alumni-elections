<?php
declare(strict_types=1);

function get_vote(int $id): void
{
    $user = require_login();
    $e    = election_find($id);

    if (!$e || $e['is_archived']) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }

    $phase = election_phase($e);

    if (!can_vote($user)) {
        render('vote/blocked', [
            'e'      => $e,
            'reason' => $user['role'] !== 'voter'
                ? 'You are signed in as ' . $user['role'] . '. Only voter accounts cast ballots — '
                . 'this keeps the people running the election out of the result.'
                : 'Your account is not active yet. Confirm your email address, or wait for an administrator to approve you.',
        ]);
        return;
    }

    if ($phase !== 'open') {
        render('vote/blocked', [
            'e'      => $e,
            'reason' => $phase === 'upcoming'
                ? 'Voting has not opened yet. It opens ' . fmt_dt($e['starts_at'], $e['timezone']) . '.'
                : 'Voting closed ' . fmt_dt($e['ends_at'], $e['timezone']) . '.',
        ]);
        return;
    }

    $receipts = receipts_for_user((int)$user['id'], $id);
    if ($receipts) {
        render('vote/already', ['e' => $e, 'receipt' => reset($receipts)]);
        return;
    }

    render('vote/ballot', [
        'e'         => $e,
        'structure' => ballot_structure($id),
        'user'      => $user,
    ]);
}

function post_vote(int $id): void
{
    $user = require_login();
    $e    = election_find($id);

    if (!$e || $e['is_archived']) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }

    // Choices arrive as position[<position_id>] = <candidate_id|0>.
    $raw     = (array)($_POST['position'] ?? []);
    $choices = [];
    foreach ($raw as $pid => $cid) {
        $choices[(int)$pid] = (int)$cid;
    }

    [$ok, $error, $receipt] = cast_ballot($e, $user, $choices);

    if (!$ok) {
        render('vote/blocked', ['e' => $e, 'reason' => $error]);
        return;
    }

    render('vote/done', ['e' => $e, 'receipt' => $receipt]);
}
