<?php

declare(strict_types=1);

/**
 * Front controller — and, under FrankenPHP worker mode, the worker script.
 *
 * Classic mode, one request per process:
 *
 *     php -S localhost:8000 -t app/public app/public/index.php
 *
 *     empira serve
 *
 * Worker mode (`APP_RUNTIME=worker`) boots the application **once** and keeps
 * it in memory, so the container, the route table, the migrations and the
 * seeder's bcrypt all cost one boot rather than one per request. Everything
 * below the `send()` call is the runtime: a loop over requests the process
 * will handle without ever going back to the top of this file.
 *
 * That only works because one Application is safe to serve many requests —
 * per-request session, context, format and route parameters. See
 * `Session::detach()` and `tests/WorkerTest.php` in the framework.
 *
 * Every htmx request is intercepted inside the framework, so the page routes
 * declared with `#[Route]` in `src/` only ever see ordinary page traffic.
 */

use App\App;
use IamAdty\Empira\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

App::loadEnvironment(dirname(__DIR__));

$app = App::boot();

if (getenv('APP_RUNTIME') !== 'worker') {
    // `send()` emits the response and exits, which is right for a process that
    // has nothing else to do afterwards.
    $app->send();
}

$handler = static function () use ($app): void {
    try {
        // `emit()`, not `send()`. An `exit` here would take the worker down,
        // FrankenPHP would restart it, and the process would serve exactly one
        // request — correct, and none of the point.
        $app->handle(Request::capture())->emit();
    } catch (\Throwable $e) {
        // `set_exception_handler` only fires when the script ends, which in a
        // worker is never. A genuine bug the framework chose to re-throw has to
        // be answered here, or the worker dies with it and FrankenPHP restarts
        // straight back into the same bug.
        fwrite(STDERR, $e . "\n");

        http_response_code(500);
        echo 'Internal Server Error';
    }
};

// Recycling the worker is the price of keeping it: PHP was not built to run
// forever, and code outside this application leaks in ways it cannot reach.
// MAX_REQUESTS trades a little latency every so often for a bounded process.
$maxRequests = (int) (getenv('MAX_REQUESTS') ?: 0);

for ($handled = 0; !$maxRequests || $handled < $maxRequests; $handled++) {
    if (!frankenphp_handle_request($handler)) {
        break;
    }

    // Collected here rather than whenever the engine feels like it, so a
    // long-lived worker is not paused in the middle of somebody's response.
    gc_collect_cycles();
}
