<?php
declare(strict_types=1);

function page_home(): void
{
    $user      = current_user();
    $elections = elections_list();

    $cards = [];
    foreach ($elections as $e) {
        $phase = election_phase($e);
        $cards[] = [
            'e'          => $e,
            'phase'      => $phase,
            'positions'  => count(positions_for((int)$e['id'])),
            'voted'      => $user ? has_voted_in_election((int)$user['id'], (int)$e['id']) : false,
            'results_ok' => results_visible($e, $user),
        ];
    }

    render('home', ['cards' => $cards, 'user' => $user]);
}

function page_election(int $id): void
{
    $e = election_find($id);
    if (!$e || $e['is_archived']) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }

    $user = current_user();
    render('election', [
        'e'          => $e,
        'phase'      => election_phase($e),
        'structure'  => ballot_structure($id),
        'user'       => $user,
        'receipts'   => $user ? receipts_for_user((int)$user['id'], $id) : [],
        'results_ok' => results_visible($e, $user),
        'turnout'    => (is_admin() || is_auditor()) ? turnout($id) : null,
    ]);
}

function page_results(int $id): void
{
    $e = election_find($id);
    if (!$e) {
        http_response_code(404);
        render('error', ['title' => 'Election not found', 'message' => 'That election does not exist.']);
        return;
    }

    $user  = current_user();
    $phase = election_phase($e);

    if (!results_visible($e, $user)) {
        render('results_sealed', ['e' => $e, 'phase' => $phase]);
        return;
    }

    $sections = [];
    foreach (positions_for($id) as $p) {
        $sections[] = ['position' => $p, 'tally' => tally_position((int)$p['id'])];
    }

    render('results', [
        'e'         => $e,
        'phase'     => $phase,
        'sections'  => $sections,
        'turnout'   => turnout($id),
        'integrity' => verify_ballot_chain($id),
        'user'      => $user,
    ]);
}

function page_candidate(int $id): void
{
    $c = candidate_find($id);
    if (!$c) {
        http_response_code(404);
        render('error', ['title' => 'Candidate not found', 'message' => 'That candidate profile does not exist.']);
        return;
    }
    render('candidate', ['c' => $c]);
}
