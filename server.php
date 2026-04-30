<?php

/**
 * Router for PHP's built-in development server.
 *
 * This file allows routes like `/admin` to be handled by Laravel when running
 * `php artisan serve` (or `php -S ... server.php`).
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($uri !== '/' && is_file(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';

