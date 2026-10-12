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

require dirname(__DIR__) . '/vendor/autoload.php';

App::loadEnvironment(dirname(__DIR__));

// `send()` emits the response and ends the script, which is what a process with
// nothing else to do afterwards should do. Under a worker this is the wrong
// call — it would take the worker down and serve exactly one request — which is
// why `respond()` exists, and why the loop that calls it is no longer here.
App::boot()->send();