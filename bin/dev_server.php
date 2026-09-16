<?php
/**
 * Router for PHP's built-in web server, which does not read .htaccess.
 *
 *   php -S localhost:8099 -t public bin/dev_server.php
 *
 * Apache/LiteSpeed/nginx on the real host use public/.htaccess instead; this
 * file is only for local development.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = dirname(__DIR__) . '/public' . $path;

// Let the built-in server hand back real files (CSS, uploaded photos) itself.
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false;
}

require dirname(__DIR__) . '/public/index.php';
