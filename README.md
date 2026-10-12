# Empira app

A bare skeleton for [Empira](https://github.com/iam-adty/empira-principle): the smallest application that boots
and serves, and the answer to "how do I wire this up?".

It is three files of source — [`src/App.php`](src/App.php) does the wiring,
[`src/Layouts/SiteLayout.php`](src/Layouts/SiteLayout.php) is the document, and
[`src/Pages/HomeLayout.php`](src/Pages/HomeLayout.php) is the one page.

It used to be a demo of sign-in, registration, a dashboard, rate limiting and a
nav bar. That is gone, and the reason is the second half of "how do I wire this
up?": a skeleton you copy arrives with someone else's decisions already made —
a rate limiter you did not ask for, a session strategy you now have to unpick,
demo credentials seeded on first boot. Every one of those is a thing to *remove*,
and removal is slower than never having it.

For worked examples, read the packages' own suites: authentication in
[empira-auth](https://github.com/iam-adty/empira-auth), components and routing in the framework.

## Run it

```bash
composer install
composer serve           # http://localhost:8000
```

Or with the framework's console, which handles the paths for you:

```bash
vendor/bin/empira serve
```

With Docker — nginx and php-fpm against PostgreSQL, and no image to build:

```bash
docker compose up -d      # http://localhost:8080
docker compose down -v
```

Both server images are stock and the workspace is bind-mounted, so an edit in
`src/` or in a sibling library's `src/` is live in the running container. The one
sharp edge is that `php:8.4-fpm-alpine` has no `pdo_pgsql`, and the fix is an
explicit `-d extension=` flag on the `php-fpm` binary — the compose file explains
why it has to be a flag and not a pool setting.

Worker mode is optional and lives in its own package,
[empira-frankenphp](https://github.com/iam-adty/empira-frankenphp). This
application does not install it and does not need to.

## Test it

```bash
composer test
```

16 tests, and they are all one question: **does this boot, and does it serve?**
The page renders, an htmx request gets only its region, an unknown route 404s,
the installed module publishes its façade and brings its own table, and the
placeholder-secret guard still refuses to start outside a local environment.

## What you would add

Everything, by existing under `src/`:

```php
#[Route('/todos', name: 'todos')]
final class TodosLayout extends SiteLayout { … }

#[Route('/admin', middleware: [RequireAuth::class])]
final class AdminLayout extends SiteLayout { … }

$app->module(new SomeOtherModule());
```

A page is a route and nothing else. `App::boot()` discovers everything under
`src/`, so a class that declares `#[Route]` appears without being registered —
which is also why a component rendered only by another component needs no
registration of its own.

See [AGENTS.md](https://github.com/iam-adty/empira-dev/blob/main/agents/app.md) for the wiring, the traps, and why there is no seed
command.