# AGENTS.md

> **Working notes for the application.** Framework guidance lives in
> [`../principle/AGENTS.md`](../principle/AGENTS.md) — do not duplicate it here.

`iam-adty/empira-app` — the reference application. It exists so that "how do I
wire this up?" has an answer you can read in one sitting.

## Commands

```bash
composer test                       # 12 tests, 33 assertions
composer serve                      # http://localhost:8000
vendor/bin/empira serve             # the framework's console, same thing
composer validate                   # must stay clean
```

## Layout

```
app/
├── composer.json      requires iam-adty/empira-principle at dev-main
├── phpunit.xml
├── studio.json        links ../principle through franzl/studio
├── public/index.php   the only file the web server runs
├── src/
│   ├── App.php        wiring: registry, routes, error pages
│   ├── Auth/          DemoGuard — **not authentication**, see below
│   ├── Pages/         HomeLayout, AdminLayout — a page and its URL
│   ├── Routing/       AppRoutes — routes that are not pages
│   ├── Css.php        the stylesheet
│   └── Components/    Counter, TodoList, SearchBox
└── tests/AppTest.php  the app, end to end
```

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

- Three `#[Prop]` components with different verbs — `Counter` (`#[Post]`),
  `TodoList` (`#[Post]` + a legacy `actions()` list), `SearchBox` (`#[Get]`)
- A `Layout` whose three cards are **regions**, so each can be swapped alone
- Full-page vs fragment rendering from the same route
- CSRF inherited from a single `hx-headers` on `<body>`
- Stateless token round-trip (`Counter`) alongside session-backed state
  (`TodoList`)

## Conventions

- `App\` namespace under `src/`, PSR-4.
- Components extend `IamAdty\Empira\Component` and return an `Element`.
- `php bin/empira make:component Name` writes to `src/components/` and prints the
  `registry()->alias()` line you must then add.
- **A component that is not registered cannot be reached.** The alias line is not
  optional.

## Testing

`tests/AppTest.php` drives the app the way htmx would — render the page, extract
the signed URL from its own markup, post to it, assert on the fragment.

It boots with `App::boot()` and the app's **real** state store. That matters:
`TodoList` is stateful and needs a store that survives between requests. Under
the CLI `SessionState` falls back to an in-process array, which is exactly what
makes the round trip work.

```php
protected function setUp(): void
{
    $_SESSION = [];      // isolate tests from each other
}
```

Do not swap in `NullState` — two todo tests fail, because it persists nothing.

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

## The database, and the guard that is not authentication

`App::boot()` calls `$app->database()`, migrates, and seeds one user. It uses
`DB_*` from the environment, defaulting to a SQLite file in `storage/`, so
`composer serve` needs no services.

`src/Auth/DemoGuard.php` signs in **whoever names a user id in a query string**.
That is fine for a demonstration and catastrophic anywhere else. It exists so the
wiring — `$app->auth()`, `RequireAuth`, the guard resolving a `User` — can be
seen working without inventing a login. There is no password in this app for
exactly that reason. **Replace it before copying anything here.**

## Running it

```bash
composer serve                      # SQLite, PHP's built-in server
docker compose up --build           # PostgreSQL behind nginx and php-fpm
```

## `APP_SECRET`

`.env.example` ships a **demo placeholder**. Generate your own for anything real:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

It must be at least 16 bytes, and it must not change between deploys — changing
it invalidates every component link already sitting in a user's history.