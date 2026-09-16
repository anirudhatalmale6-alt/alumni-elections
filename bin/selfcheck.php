<?php
declare(strict_types=1);

/**
 * Post-deployment exposure check.
 *
 *   php bin/console.php selfcheck https://elections.example.org
 *
 * Fetches the live site over HTTP the way a stranger would and reports whether
 * anything private is reachable: the database (which holds every alumnus's email
 * address and password hash), the mail log, the source, the tests, a stray .git
 * directory.
 *
 * This asks the question that actually matters — "can someone download it?" —
 * rather than assuming a config file is doing its job. Web server rules vary by
 * host, and the only honest way to know is to make the request.
 */

if (!function_exists('out')) {
    exit("Run this through bin/console.php selfcheck <url>\n");
}

$base = rtrim((string)($argv[2] ?? ''), '/');
if ($base === '' || !preg_match('#^https?://#', $base)) {
    fail("Usage: php bin/console.php selfcheck https://your-domain\n"
       . "       (the address alumni will actually type)");
}

/** Fetch a URL. Returns [status, body, error]. */
function probe(string $url): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 15,
            'follow_location' => 0,
            'ignore_errors'   => true,
            'header'          => "User-Agent: alumni-elections-selfcheck\r\n",
        ],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $e = error_get_last();
        return [0, '', trim((string)($e['message'] ?? 'request failed'))];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    return [$status, (string)$body, ''];
}

out('Checking ' . $base);
out(str_repeat('-', 62));

/* A positive control first. If the site itself is not reachable, then
   "nothing private is reachable" would be true for the boring reason that
   nothing at all is, and the whole report would be worthless. */

[$status, $body, $err] = probe($base . '/');
if ($status === 0 || $status >= 500) {
    out('');
    out('STOPPED: the site itself did not respond'
        . ($err !== '' ? ' (' . $err . ')' : ' (HTTP ' . $status . ')') . '.');
    out('Nothing below would mean anything until the site loads, so the check');
    out('has not been run. Fix the address or the deployment first.');
    exit(1);
}
$looksLikeApp = str_contains($body, 'alumni') || str_contains($body, 'Alumni')
             || str_contains($body, 'Elections');
out(sprintf('%-46s %s', 'site responds', 'HTTP ' . $status
    . ($looksLikeApp ? ' — and looks like the app' : ' — but does NOT look like the app')));

if (!$looksLikeApp) {
    out('');
    out('WARNING: something answered, but it is not this application.');
    out('Check that the address and the web root are right before trusting the rest.');
}

/* Things a stranger must never be able to download. */

$mustBeSealed = [
    '/data/elections.sqlite'  => 'the database — every email address and password hash',
    '/data/elections.sqlite-wal' => 'the database write-ahead log',
    '/data/mail.log'          => 'the mail log',
    '/data/config.local.php'  => 'your local configuration',
    '/src/config.php'         => 'source: configuration',
    '/src/db.php'             => 'source: database layer',
    '/src/auth.php'           => 'source: authentication',
    '/bin/console.php'        => 'the admin command line tool',
    '/tests/run.php'          => 'the test suite',
    '/.git/config'            => 'the git repository',
    '/.gitignore'             => 'repository metadata',
];

$exposed = [];   // downloadable right now
$inRoot  = [];   // reachable, served empty because the server executed it
$sealed  = 0;

out('');
out('Private paths (all of these must be refused):');

foreach ($mustBeSealed as $path => $what) {
    [$s, $b] = probe($base . $path);

    // A 200 that returns the site's own 404 page is fine — some hosts do that.
    $isAppErrorPage = $s === 200 && (str_contains($b, 'Page not found')
                                  || str_contains($b, 'nothing at'));

    if ($s !== 200 || $isAppErrorPage) {
        $sealed++;
        out(sprintf('  %-42s ok       (HTTP %d)', $path, $s));
        continue;
    }

    if ($b !== '') {
        $exposed[] = [$path, $what, strlen($b)];
        out(sprintf('  %-42s EXPOSED  (HTTP %d, %d bytes downloadable)', $path, $s, strlen($b)));
        continue;
    }

    /* HTTP 200 with an empty body on a .php file means the server ran it rather
       than handing over the text. Nothing leaked this second, but the file is
       plainly inside the web root — and the day a host upgrade or a broken PHP
       handler stops executing it, the source and anything in it is served as
       plain text. That is a real finding, not an "ok". */
    $inRoot[] = [$path, $what];
    out(sprintf('  %-42s IN WEB ROOT (HTTP %d, executed not served)', $path, $s));
}

/* Things that must work. */

out('');
out('Public paths (these must work):');
$mustWork = ['/' => 'home', '/login' => 'sign in', '/register' => 'registration'];
$broken   = [];
foreach ($mustWork as $path => $what) {
    [$s] = probe($base . $path);
    if ($s === 200) {
        out(sprintf('  %-42s ok       (HTTP %d)', $path . '  (' . $what . ')', $s));
    } else {
        $broken[] = $path;
        out(sprintf('  %-42s BROKEN   (HTTP %d)', $path . '  (' . $what . ')', $s));
    }
}

/* Is the connection encrypted? Passwords and ballots travel over it. */

out('');
if (str_starts_with($base, 'https://')) {
    out('  HTTPS                                      ok');
} else {
    out('  HTTPS                                      NOT IN USE');
    out('    Passwords and ballots would cross the network in the clear.');
    out('    Nearly every host offers a free certificate — turn it on before the election.');
}

/* Verdict. */

out('');
out(str_repeat('-', 62));

if (!$exposed && !$inRoot && !$broken) {
    out('PASS — nothing private is reachable and the public pages work.');
    exit(0);
}

if ($inRoot && !$exposed) {
    out('FAIL — ' . count($inRoot) . ' private file(s) sit inside the web root:');
    foreach ($inRoot as [$path, $what]) {
        out('   ' . $base . $path . '  (' . $what . ')');
    }
    out('');
    out('They came back empty because the server executed them instead of');
    out('sending the text, so nothing has leaked yet. But they are reachable, and');
    out('if PHP handling ever breaks — a host upgrade, a changed handler — the');
    out('source is served as plain text to anyone who asks. Move them out of the');
    out('web root; do not rely on them being run.');
}

if ($exposed) {
    out('FAIL — ' . count($exposed) . ' private path(s) can be downloaded by anyone:');
    foreach ($exposed as [$path, $what, $len]) {
        out('   ' . $base . $path . '  (' . $what . ')');
    }
    out('');
    out('Almost always this means the web root points at the project folder');
    out('instead of the public/ folder inside it. Either repoint the web root at');
    out('public/, or upload the contents of public/ into your public_html and put');
    out('everything else ABOVE it, outside the web root.');
}

if ($broken) {
    out('FAIL — ' . count($broken) . ' public page(s) did not load: ' . implode(', ', $broken));
}

exit(1);
