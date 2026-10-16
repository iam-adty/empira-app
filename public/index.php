<?php

declare(strict_types=1);

/**
 * The front controller.
 *
 * One request, one process, one response — which is what `send()` means, and it
 * is correct for every classic runtime: nginx and apache with php-fpm, the
 * built-in server, anything that starts a fresh PHP process to answer a request.
 *
 * There is no runtime branch here, and there used to be one. This file branched
 * on `APP_RUNTIME` to serve requests in a loop when FrankenPHP asked it to, which
 * meant every application on every other runtime shipped a loop it never ran and a
 * dependency on a runtime it did not use. Worker mode is a package now —
 * `iam-adty/empira-frankenphp` — and its `worker.php` holds the loop.
 *
 * The per-request resets the framework does — the session's, and the ORM's
 * identity map — look like worker-only precautions and are not. They cost
 * nothing here and they are the reason the same application is safe under both.
 */

use App\App;

/**
 * Hand an existing file in `public/` back to the web server, and only here.
 *
 * `empira serve` starts `php -S … -t public public/index.php`, which makes THIS
 * FILE the router script — and PHP runs a router script at the start of every
 * single request, not just the ones that miss a file. Falling through to serve
 * the requested resource is something the router has to ask for explicitly, by
 * returning false. Without this, a static file in `public/` is answered by the
 * application, which has no route for it and 404s.
 *
 * That is not hypothetical: `public/htmx.min.js` is how this was found.
 *
 * Three details that matter:
 *
 *   * `return false` works only here, at the top level of the router script. It
 *     cannot live in a function, and it must come before any output.
 *   * The query string is stripped, or `/htmx.min.js?v=2` fails is_file() and
 *     404s — which would look intermittent rather than missing.
 *   * Under nginx and php-fpm this file is only reached for paths the web
 *     server has already decided are not static, so the guard is inert there.
 *
 * The directory index is deliberately excluded: `public/` answering its own
 * index would take routing away from the front controller.
 */
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($path !== null && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Where this application is, for everything that has to reach the filesystem:
 * `.env`, `storage/`, `bootstrap/`.
 *
 * Defined here because this is the entry point, and an entry point is the only
 * party that knows. The framework will not fall back to `getcwd()` — the working
 * directory is whatever the process manager or the operator started the process
 * in, and reading configuration from there is a configuration file the
 * application did not write.
 *
 * **`bootstrap/app.php` defines it too, and that is not duplication.** The
 * console never loads this file: `vendor/bin/empira` requires
 * `bootstrap/app.php` and boots from that. Two entry points, two constants,
 * same name — which is what makes `Application::basePath()` answer the same in
 * `empira migrate` as it does in a request.
 */
// Short-circuited because this file can be reached twice in one process — a
// worker that includes the front controller after a test already did — and an
// unconditional `define()` warns, and is a fatal error in PHP 9.
defined('__ROOT_DIR__') || define('__ROOT_DIR__', dirname(__DIR__));

App::loadEnvironment();

// `send()` emits the response and ends the script, which is what a process with
// nothing else to do afterwards should do. Under a worker this is the wrong
// call — it would take the worker down and serve exactly one request — which is
// why `respond()` exists, and why the loop that calls it is no longer here.
App::boot()->send();