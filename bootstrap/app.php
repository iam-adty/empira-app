<?php

declare(strict_types=1);

/**
 * What the console boots.
 *
 * `vendor/bin/empira` looks for `bootstrap/app.php` in the working directory and
 * boots whatever it returns. This application registers no commands of its own —
 * the `migrate` you run here is the framework's, and `migrate` is the only thing
 * a skeleton needs. An application with seeders or importers would register them
 * in this file or one it requires, which is how the framework stays ignorant of
 * what any particular project wants to *do*.
 *
 * Returning the booted `Application` rather than `exit`ing is the whole contract
 * — the console needs the object, not the side effects. Calling `boot()` twice
 * in one process is fine.
 */

use App\App;

require __DIR__ . '/../vendor/autoload.php';

App::loadEnvironment(dirname(__DIR__));

return App::boot(csrf: false);