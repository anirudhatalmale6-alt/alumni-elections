<?php
declare(strict_types=1);

/**
 * Single entry point. Everything is routed through here so that authentication,
 * CSRF and the election window are checked in one place rather than per file.
 */

require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/mail.php';
require dirname(__DIR__) . '/src/audit.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/elections.php';
require dirname(__DIR__) . '/src/controllers/site.php';
require dirname(__DIR__) . '/src/controllers/account.php';
require dirname(__DIR__) . '/src/controllers/voting.php';
require dirname(__DIR__) . '/src/controllers/admin.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = '/' . trim((string)$path, '/');
if ($path === '/') {
    $path = '/';
}

$routes = [
    ['GET',  '#^/$#',                              'page_home'],
    ['GET',  '#^/elections/(\d+)$#',               'page_election'],
    ['GET',  '#^/elections/(\d+)/results$#',       'page_results'],
    ['GET',  '#^/candidates/(\d+)$#',              'page_candidate'],

    ['GET',  '#^/register$#',                      'get_register'],
    ['POST', '#^/register$#',                      'post_register'],
    ['GET',  '#^/verify$#',                        'get_verify'],
    ['GET',  '#^/login$#',                         'get_login'],
    ['POST', '#^/login$#',                         'post_login'],
    ['POST', '#^/logout$#',                        'post_logout'],
    ['GET',  '#^/forgot$#',                        'get_forgot'],
    ['POST', '#^/forgot$#',                        'post_forgot'],
    ['GET',  '#^/reset$#',                         'get_reset'],
    ['POST', '#^/reset$#',                         'post_reset'],
    ['GET',  '#^/account$#',                       'get_account'],

    ['GET',  '#^/elections/(\d+)/vote$#',          'get_vote'],
    ['POST', '#^/elections/(\d+)/vote$#',          'post_vote'],

    ['GET',  '#^/admin$#',                                 'admin_dashboard'],
    ['GET',  '#^/admin/elections/new$#',                   'admin_election_new'],
    ['POST', '#^/admin/elections$#',                       'admin_election_create'],
    ['GET',  '#^/admin/elections/(\d+)$#',                 'admin_election_edit'],
    ['POST', '#^/admin/elections/(\d+)$#',                 'admin_election_update'],
    ['POST', '#^/admin/elections/(\d+)/publish$#',         'admin_election_publish'],
    ['POST', '#^/admin/elections/(\d+)/archive$#',         'admin_election_archive'],
    ['POST', '#^/admin/elections/(\d+)/positions$#',       'admin_position_create'],
    ['POST', '#^/admin/positions/(\d+)/delete$#',          'admin_position_delete'],
    ['POST', '#^/admin/positions/(\d+)/candidates$#',      'admin_candidate_create'],
    ['POST', '#^/admin/candidates/(\d+)/update$#',         'admin_candidate_update'],
    ['POST', '#^/admin/candidates/(\d+)/delete$#',         'admin_candidate_delete'],
    ['GET',  '#^/admin/elections/(\d+)/turnout$#',         'admin_turnout'],
    ['GET',  '#^/admin/users$#',                           'admin_users'],
    ['POST', '#^/admin/users/(\d+)/status$#',              'admin_user_status'],
    ['POST', '#^/admin/users/(\d+)/role$#',                'admin_user_role'],
    ['GET',  '#^/admin/roll$#',                            'admin_roll'],
    ['POST', '#^/admin/roll$#',                            'admin_roll_add'],
    ['POST', '#^/admin/roll/(\d+)/delete$#',               'admin_roll_delete'],
    ['GET',  '#^/admin/audit$#',                           'admin_audit'],
];

try {
    foreach ($routes as [$verb, $pattern, $handler]) {
        if ($verb !== $method || !preg_match($pattern, $path, $m)) {
            continue;
        }
        if ($method === 'POST') {
            csrf_check();
        }
        array_shift($m);
        $handler(...array_map('intval', $m));
        exit;
    }

    // Path matched no route, or matched one under a different verb.
    http_response_code(404);
    render('error', [
        'title'   => 'Page not found',
        'message' => 'There is nothing at ' . $path . '.',
    ]);
} catch (Throwable $ex) {
    error_log('[alumni-elections] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    render('error', [
        'title'   => 'Something went wrong',
        'message' => 'The page could not be produced. The error has been written to the server log.',
        'detail'  => (getenv('APP_DEBUG') === '1') ? $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine() : null,
    ]);
}
