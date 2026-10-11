# AGENTS.md

> **Working notes for the application.** Framework guidance lives in
> [`../principle/AGENTS.md`](../principle/AGENTS.md) — do not duplicate it here.

`iam-adty/empira-app` — the reference application. It exists so that "how do I
wire this up?" has an answer you can read in one sitting.

## Commands

```bash
composer test                       # 27 tests, 85 assertions
composer serve                      # http://localhost:8000
vendor/bin/empira migrate           # the schema, before anything serves
vendor/bin/empira seed              # the demo account
composer validate                   # must stay clean
```

## Layout

```
app/
├── composer.json      requires iam-adty/empira-principle at dev-main
├── phpunit.xml
├── studio.json        links ../principle through franzl/studio
├── Dockerfile         FrankenPHP, worker mode
├── docker/entrypoint.sh  starts FrankenPHP; picks worker or classic
├── docker-compose.yml FrankenPHP + PostgreSQL
├── migrations/        Doctrine migrations: Version*.php
├── bootstrap/app.php  what `bin/empira` boots to find this app's commands
├── public/index.php   front controller *and* worker script
├── src/
│   ├── App.php        wiring: registry, ORM, auth, routes
│   ├── Css.php        the global stylesheet
│   ├── Auth/          UserRepository — registration
│   ├── Console/       Seed — a command this app supplies
│   ├── Layouts/       SiteLayout — the master document
│   ├── Middleware/    RedirectGuests, RedirectIfAuthenticated
│   ├── Pages/         Home, Login, Register, Dashboard
│   ├── Routing/       AppRoutes — routes that are not pages
│   └── Components/    NavBar, LoginForm, RegisterForm, LogoutButton,
│                      UserCard, FormField, Widget
├── tests/AppTest.php  the app, end to end
└── tools/test         runs the suite, and refuses a truncated run
```

Scratch space is `../tmp`, above both packages rather than inside either, and it
is mounted into every container — so `tests/AppTest.php` reaches it as
`dirname(__DIR__, 2) . '/tmp'` on the host and the same string inside the
container. Nothing in `/tmp` ever holds a fixture.

## The framework is linked, not installed

`composer.json` requires `iam-adty/empira-principle: dev-main`, and
`franzl/studio` symlinks it from `../principle`:

```bash
vendor/bin/studio load ../principle
composer update iam-adty/empira-principle
```

Check the link is live:

```bash
ls -la vendor/iam-adty/     # empira-principle -> ../../../principle/
```

**An edit under `principle/src` is live immediately** — no rebuild, no reinstall.
If you do not see a change, check that symlink first; do not reinstall.

## Things this app demonstrates

- A page that declares **its own URL and its own gate**, on one attribute
- A **master layout** (`SiteLayout`) with pages extending it, so the document,
  the `nav` region and the global sheet are declared once
- **Authentication in components**: `LoginForm::login(Auth, UserRepository)` —
  an action taking its dependencies by type, as a controller does
- A component rendering another (`NavBar` → `LogoutButton`, forms → `FormField`)
- **Per-component styling**, emitted inside the swap target so it survives an
  htmx swap
- Full-page vs fragment rendering from the same route
- CSRF inherited from a single `hx-headers` on `<body>`
- Session-cookie auth with fixation defence, and a repository that writes users

## Three things that will bite you

- **Composition is free.** `child()` hands back a component and `children()`
  reports what a component composed. Nothing needs registering for a nested
  component to answer its own action URLs — `$app->components(__NAMESPACE__)`
  in `App::boot()` is the whole of it. An `alias()` is still worth adding for a
  token that should be short or survive the class moving namespaces.
- **`Component::validate()` returns an `ErrorBag`; it does not throw.** Wrapping
  it in `try { … } catch (ValidationException)` silently lets invalid input
  reach the database.
- **The framework's source is mounted live, but the `vendor` *volume* is not.**
  `../principle/src` is bind-mounted over the copy the image baked in, so an edit
  under `principle/src` is an edit in the running container — no rebuild, and the
  worker restarts on its own. The autoloader is PSR-4, so new framework files
  resolve too. What is *not* live is anything outside `src/`, and the `vendor`
  named volume is seeded from the image once and then never refreshed. So a
  changed `composer.json` — here or in the framework — still needs
  `docker compose down -v && docker compose up -d --build`. Confirm which you
  are in with `docker compose exec app grep -c someNewMethod vendor/iam-adty/empira-principle/src/…`.
- **Never rebuild to test a code change.** Every PHP file in either package is
  mounted and watched. Rebuilding for a code change is the symptom of a mount
  that has gone wrong, not the remedy.
- **Boot does not migrate or seed, on purpose.** `empira migrate` and
  `empira seed` are deploy steps, run by `docker/entrypoint.sh` before FrankenPHP
  starts (`APP_MIGRATE_ON_START=false` turns that off). Boot is per worker
  thread, so a boot that migrates has every thread racing through the same
  migration — that is what `App::bootLock()` used to paper over, and the lock is
  gone now that nothing runs at boot. Do not move the seeder back.
- **The entrypoint's migrate is a compromise a single container forces.** It is
  once per *container start*, not once per deploy, and a scaled deployment runs
  it several times. That is safe because Doctrine sees the migrations already
  applied. Where a migration genuinely depends on running exactly once, it
  belongs in a deploy job that finishes before the container starts.
- **`respond()` in the worker loop, `send()` in classic mode.** `send()` exits,
  which takes the worker down and earns you one request per process.
  `public/index.php` branches on `APP_RUNTIME` for exactly that reason: worker
  mode calls `respond()`, which emits and returns, and classic mode calls
  `send()`, which exits because there is nothing to come back to.

## Conventions

- `App\` namespace under `src/`, PSR-4.
- Components extend `IamAdty\Empira\Component` and return an `Element`.
- An action **returns void**; it responds by mutating props and the response.
- `php bin/empira make:component Name` writes to `src/components/` and prints the
  `registry()->alias()` line you must then add.

## Testing

`tests/AppTest.php` drives the app the way htmx would — render the page,
extract the signed URL from its own markup, post to it, assert on the response.

`composer test` goes through `tools/test`, which checks that the run **finished**
rather than trusting PHPUnit's exit status. That status cannot report truncation:
an `exit` anywhere in the code ends the process before PHPUnit calculates one, so
a suite can stop after a handful of tests and still return 0. The script refuses a
run with no summary line, and `phpunit.xml` sets `failOnEmptyTestSuite` so a run
that matched nothing is a failure rather than a pass.

```php
protected function setUp(): void
{
    $_SESSION = [];   // isolate sessions

    $_ENV['DB_DATABASE'] = self::$database;   // shared, see below
}
```

**One database per test class, not per test.** Hashing the seeded user with
bcrypt costs about half a second, and a fresh file per test turned a 0.07s suite
into 22s. Sharing it is safe because every test signs in as itself and uses an
address generated for that test. The file is created per *run*, so nothing leaks
between runs.

`setUpBeforeClass()` builds it by running the real commands — `empira migrate`,
then `empira seed` — rather than by booting the application and hoping it
prepared the schema. That is deliberate: a suite that depends on boot to create
its database cannot survive the change that stopped boot migrating, and would go
on hiding the cost of anything else that runs at boot. It also means the tests
exercise the console an operator actually types.

## Before committing

```bash
composer test
composer validate
```

If you changed the framework, test both repos — this app is the integration test:

```bash
cd ../principle && composer test
```

## How routes are declared

There is no `routes.php`. A page declares its own URL with `#[Route]` on the
layout class, and `App::boot()` calls `$app->discover(__DIR__, __NAMESPACE__)`.
Routes that are not pages — no layout, no markup — go in
`src/Routing/AppRoutes.php`, a `RouteRegistrar` passed to `$app->registrar()`.

**Both mechanisms exist on purpose.** Discovery is for pages, where the URL and
the thing that draws it belong in one file; the registrar is for everything
else, where the route is not a page and should not pretend to be one.

## The database

`App::boot()` calls `$app->orm()` and reads `DB_*` from the environment,
defaulting to a SQLite file in `storage/`, so `composer serve` needs no services.

`publishDatabaseDefaults()` runs first and writes this application's defaults
into `$_ENV` before anything else reads them. There were once two readers with
two sets of defaults — the runtime wanted `storage/app.sqlite`, the migration
command read the same variables through the framework and got `:memory:` — and
`composer setup` migrated a database that vanished on exit and then seeded a
real one with no `users` table in it.

Migrating and seeding are **deploy steps**, not boot:

```bash
php vendor/bin/empira migrate        # Doctrine, with its own version ledger
php vendor/bin/empira seed           # this app's, registered at boot
```

`seed` exists because `App::boot()` calls `$app->command('seed', …)`, and
`bin/empira` finds it through `bootstrap/app.php`. The seeder is idempotent,
because a deploy step is allowed to be run twice — a retried pipeline should not
fail on a seed that already happened.

A seeded account, so there is something to sign in as:

```
ada@example.com  /  correct horse battery staple
```

## Account switching is closed on purpose

`RedirectIfAuthenticated` turns a signed-in visitor away from `/login` and
`/register`, so the only way to become a different user is to sign out first.
That is deliberate — the absence of this middleware is not an oversight, and
neither is the absence of a "switch account" link.

## Running it

```bash
composer serve                      # SQLite, PHP's built-in server
docker compose up --build           # PostgreSQL behind FrankenPHP
```

The compose app runs in **worker mode**: one boot, many requests per process,
across **2 × CPU** workers by default. `APP_RUNTIME=classic` drops back to a fresh
runtime per request, which is the comparison to make when something behaves
differently. `FRANKENPHP_WORKERS=1` forces a single worker — a diagnostic, not a
setting. It matters because at 2 × CPU a state leak only surfaces when two
consecutive requests land on the same worker, about one time in 32, so it hides
from you exactly when you are looking for it.

## `APP_SECRET`

`.env.example` ships a **demo placeholder**. Generate your own for anything real:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

It must be at least 16 bytes, and it must not change between deploys — changing
it invalidates every component link already sitting in a user's history.