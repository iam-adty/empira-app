<?php

declare(strict_types=1);

/**
 * Front controller — the only PHP file the web server runs.
 *
 * Serve it with the built-in server:
 *
 *     php -S localhost:8000 -t app/public app/public/index.php
 *
 * or, from inside this directory:
 *
 *     empira serve
 *
 * Every htmx request is intercepted inside `send()`, so the page routes
 * declared with `#[Route]` in `src/` only ever see ordinary page traffic.
 */

use App\App;

require dirname(__DIR__) . '/vendor/autoload.php';

App::loadEnvironment(dirname(__DIR__));

App::boot()->send();