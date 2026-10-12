<?php

declare(strict_types=1);

/**
 * The module registry — the file that answers "what is this application made
 * of?".
 *
 * It returns a closure rather than an array of modules, and the reason is
 * ordering. A module is built from the application's own container:
 * `AuthModule` needs a session and an entity manager, and both of those exist
 * only after `Application::orm()` and `Application::state()` have run. An array
 * literal would have to be built before either of them, so the registry hands
 * back a *recipe* instead of a *result*, and `App::boot()` calls it at the
 * moment the container is ready.
 *
 *     return function (Application $app): array {
 *         return [
 *             new AuthModule([
 *                 new SessionGuard($app->session(), new DoctrineUserProvider($app->entities())),
 *             ]),
 *         ];
 *     };
 *
 * What this buys is that the answer is in one readable file. `App::boot()` used
 * to carry the module line inline, which meant the list of what an application
 * had was scattered through the middle of a boot method that also built a
 * logger, discovered routes and published database defaults. Adding a module
 * meant reading the boot method to find the one line that mattered.
 *
 * Note what a module *cannot* do here: it cannot add an ORM directory of its
 * own. `App::boot()` maps `src/` and the auth entity path before calling this,
 * because a module's migrations and the entity they describe must be visible to
 * the same `EntityManagerFactory`. A module that needs an entity path added says
 * so by exporting one — `AuthModule::entityPath()` — and the application lists
 * it where the mapping happens. That is a deliberate seam, not an oversight: the
 * framework cannot map an application's schema on a module's behalf.
 */

use Empira\Application;
use Empira\Auth\AuthModule;
use Empira\Auth\DoctrineUserProvider;
use Empira\Auth\SessionGuard;

return function (Application $app): array {
    // Authentication, installed rather than switched on.
    //
    // The guard is built from the application's *own* session — the same one the
    // rest of the app writes to, not a fresh one. A guard reading a session
    // nothing writes is a login form that reports "wrong password" forever, and
    // it looks correct in review because the type is right.
    //
    // The provider holds no entity: it is handed an EntityManager that
    // `Application::handle()` clears between requests, so a user loaded for one
    // request belongs to that request and not to a worker.
    return [
        new AuthModule([
            new SessionGuard($app->session(), new DoctrineUserProvider($app->entities())),
        ]),
    ];
};