<?php

declare(strict_types=1);

/**
 * What the console boots.
 *
 * `vendor/bin/empira` looks for `bootstrap/app.php` in the working directory,
 * boots whatever it returns, and takes the commands it registered. That is how
 * `empira seed` exists at all: the framework has no idea what this
 * application's seeders are, and guessing would be worse than not having one.
 *
 * Returning the booted `Application` rather than `exit`ing is the whole contract
 * — the console needs the object, not the side effects. Calling `boot()` twice
 * in one process is fine.
 */

use App\App;

require __DIR__ . '/../vendor/autoload.php';

App::loadEnvironment(dirname(__DIR__));

return App::boot(csrf: false);