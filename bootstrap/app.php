<?php

declare(strict_types=1);

/**
 * What the console boots.
 *
 * `vendor/bin/empira` looks for `bootstrap/app.php` in the working directory and
 * boots whatever it returns. The web entry is `public/index.php`, which calls
 * `App::boot()`; this one calls `App::bootConsole()`. Two entry points rather
 * than one with a flag, because "serve web" and "run a command" are different
 * jobs and the difference belongs in a name.
 *
 * Returning the booted `Application` rather than `exit`ing is the whole contract
 * — the console needs the object, not the side effects. Calling it twice in one
 * process is fine.
 *
 * ## Commands are found, not listed
 *
 * `discoverCommands()` below is the entire registration. A class under
 * `src/Console/` that implements `Console\Command` is a command, named by its own
 * `name()`, built with whatever it asks the container for. Writing the class is
 * the whole install step — which is the point: adding a command should not mean
 * editing a bootstrap file that other commands also live in.
 *
 * This application ships none, and that is deliberate. A skeleton is what you
 * copy, and a demo command is a decision the person copying it did not make. The
 * directory is here anyway so the convention is visible rather than something to
 * discover from documentation.
 *
 * An application with seeders or importers writes them as commands here, or —
 * if they belong to an installed package — the module declares them through
 * `Console\CommandProvider` and this line needs no change.
 */

use App\App;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Where this application is — the same name and the same value that
 * `public/index.php` defines, and it has to be defined again here because the
 * console never loads that file. Two entry points rather than one with a flag,
 * for the same reason there are two of those.
 */
define('__ROOT_DIR__', dirname(__DIR__));

App::loadEnvironment();

$app = App::bootConsole();

$app->discoverCommands(dirname(__DIR__) . '/src/Console', 'App\\Console');

return $app;