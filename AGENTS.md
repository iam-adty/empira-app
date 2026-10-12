# AGENTS.md

> **Working notes for the application.** Framework guidance lives in
> [`../principle/AGENTS.md`](../principle/AGENTS.md) — do not duplicate it here.

`iam-adty/empira-app` — a **bare skeleton**: the smallest application that boots
and serves, and the answer to "how do I wire this up?".

## Commands

```bash
composer test                       # 15 tests, 26 assertions
composer serve                      # http://localhost:8000
vendor/bin/empira migrate           # the schema, before anything serves
composer validate                   # must stay clean
```

There is no `seed` command. See [Account switching](#there-is-no-seed) below.

## Layout

```
app/
├── composer.json      requires empira-principle and empira-auth, both path-linked
├── phpunit.xml
├── studio.json        links ../principle through franzl/studio
├── Dockerfile         FrankenPHP, worker mode
├── docker/entrypoint.sh  migrates, then starts FrankenPHP
├── docker-compose.yml FrankenPHP + PostgreSQL
├── migrations/        empty, on purpose — see the .gitkeep
├── bootstrap/app.php  what `bin/empira` boots
├── public/index.php   front controller *and* worker script
├── src/
│   ├── App.php        all the wiring, in one readable method
│   ├── Layouts/       SiteLayout — the document and its regions
│   └── Pages/         HomeLayout — the one page
└── tests/AppTest.php  15 tests: does it boot, does it serve
```

Scratch space is `../tmp`, above every package rather than inside one, and it is
mounted into every container — so `tests/AppTest.php` reaches it as
`dirname(__DIR__, 2) . '/tmp'` on the host and the same string inside the
container. Nothing in `/tmp` ever holds a fixture.

## This is a skeleton on purpose

It used to be a demo: sign-in, registration, a dashboard, rate limiting, a nav
bar, eight components, four layouts, four pages, and a seeder. All of that is
gone.

Not because it was wrong, but because a skeleton you copy arrives with someone
else's decisions already made — a rate limiter you did not ask for, a session
strategy you now have to unpick, a `users` table seeded with credentials on
first boot. Every one of those is a thing to *remove*, and removal is slower
than never having it. What is left is a boot method you can read in one sitting,
a page, and a layout.

The trade is real and worth stating: **this app no longer demonstrates what the
framework can do.** The auth module's own suite demonstrates authentication; the
framework's 473 tests demonstrate components, routing, regions, sessions, CSRF
and the ORM. If you want a worked example of a feature end to end, read that
package's tests rather than looking for it here.

## The two lines that matter

Everything an application needs beyond the obvious is these two, and they are
meant to be read together:

```php
$app->orm(self::databaseConfig(), [__DIR__, AuthModule::entityPath()]);

$app->module(new AuthModule([
    new SessionGuard($app->session(), new DoctrineUserProvider($app->entities())),
]));
```

**Drop both and you have an application with no authentication** — which is the
point. Authentication used to be inside the framework, which meant every
application carried a users table whether it asked for one or not. It is a module
now, and asking for it is a line you can delete.

**Do not drop one without the other.** A module with no mapped entity cannot
read a user. A mapping with no module maps a table nobody asks anything about.

## `AuthModule::entityPath()` is load-bearing

Doctrine separates **discovery** from **lookup**. `getClassMetadata(User::class)`
reflects on the class you name, so it works whether or not `User`'s directory was
registered — which means forgetting the entity path changes nothing at request
time, and 14 of this app's tests passed without it.

What breaks is `getAllClassNames()`, which is what the **schema tooling** reads.
The failure of a missing path is not an exception; it is a `SchemaTool` quietly
generating DDL that omits a table an entity depends on.

`testTheModuleEntityDirectoryIsDiscoveredNotMerelyReachable` asserts on the
discovery half, and exists for exactly that reason. If you add an entity in
another package, that test is where the path gets registered.

## Migrations

`migrations/` is **empty, and that is intentional**. `empira migrate` refuses to
run without the directory — correctly, since a project with no migrations should
be told so rather than having the command report success against a directory it
never found.

The migrations that are real come from modules:

```
Empira\Migrations\Auth\Version20261011000001   auth/migrations
Empira\Migrations\Auth\Version20261012000001   auth/migrations
```

A module registers its migration directory at boot, and `migrate` plans across
all of them in one sequence against one version ledger — so ordering between a
module's table and your column on that table has one answer. Drop a
`Version*.php` in `migrations/` and it is sequenced alongside them, not after.

Each module's directory needs **its own** namespace, matching the `namespace`
line inside its files. Sharing the project's `Empira\Migrations` means two files
declaring the same class name, and which one Doctrine runs depends on which
`require` reached the definition first.

## There is no seed

The seeder is gone, and the entrypoint no longer calls one. A skeleton has no
accounts to invent, and **a deploy step that seeds demo credentials into anything
reachable is the wrong default to ship in a file somebody copies.**

If you want one:

```php
$app->command('seed', new Seed($repository));   // in App::boot()
```

and add `php vendor/bin/empira seed` back to `docker/entrypoint.sh`. Make it
idempotent — a deploy step is allowed to be run twice, because a retried pipeline
should not fail on a seed that already happened.

## What the tests cover, and why there are only 15

The suite went from 45 tests to 15 because most of them tested features that no
longer exist. A test for a feature nobody ships is a test nobody reads and a
suite that fails for reasons unrelated to what it is meant to catch.

What is left is the question a skeleton actually has to answer: *does this boot,
and does it serve?* — the page renders, an htmx request gets only its region, an
unknown route 404s, the error view does not change the status, the module
installs and publishes its façade, its table arrived without an application
migration, the entity directory is discoverable, the secret guard guards, and
`migrate` is idempotent.

`testThisApplicationRegistersNoCommandsOfItsOwn` is a leftover-detector: it
asserts `seed` is gone, so a command that should not be here cannot quietly
return.

## The framework is linked, not installed

Both dependencies are Composer path packages, symlinked by `franzl/studio`:

```bash
ls -la vendor/iam-adty/     # empira-auth -> ../../../auth/   empira-principle -> ../../../principle/
```

**An edit under `principle/src` or `auth/src` is live immediately** — no rebuild,
no reinstall. If a change is not visible, check the symlinks first; do not
reinstall.

Both are mounted into the container. Forgetting the second one costs an hour: the
image has a copy, the container has a stale one, and the symptom is a change that
"did not take". A changed `composer.json` in any of the three packages *does* need
`docker compose down -v && docker compose up -d --build`.

## Things that will bite you

- **A region gets its id from `$region->element()`, not from what your callback
  returns.** Returning a plain `$this->hx->div()` renders a div with no id, so
  `hx-target` finds nothing and an htmx swap silently targets nothing. The id is
  `region-<name>`, so target `region-main`, not `main`.
- **`App::boot()` runs per worker thread.** It does not migrate and does not seed,
  and that is deliberate: a boot that migrates has every thread racing through the
  same migration. Do not move either back into boot.
- **The `SessionGuard` must be built from `$app->session()`** — the application's
  own session, the one everything else writes to. A guard holding a session of
  its own reads a store nobody writes, and every sign-in fails as "wrong
  password". It looks correct in review because the type is right.
- **`respond()` in the worker loop, `send()` in classic mode.** `send()` exits,
  which takes the worker down and earns you one request per process.
  `public/index.php` branches on `APP_RUNTIME` for that reason.

## `APP_SECRET`

`.env.example` ships a **demo placeholder**. Generate your own for anything real:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

It must be at least 16 bytes, and must not change between deploys — changing it
invalidates every component link already in a user's history.

`App::boot()` **refuses to start** on the placeholder unless the environment is
local. The failure this guards against is silent, which is why it is a boot check
rather than a log line: a component token is
`base64url(payload).base64url(hmac)` and the payload carries the component class
and its props, so with the secret in the open anyone can mint a signed URL for
any component with any props they like. Nothing 500s, nothing is logged, and the
forged URL simply works.

**It is opt-in, and that is worth being plain about.** An unset `APP_ENV` counts
as local, so the guard fires when somebody sets `APP_ENV=production` and forgets
the secret — *not* when they set neither.

## Before committing

```bash
composer test
composer validate
```

If you changed the framework or the auth module, test those too — they are
separate packages with their own suites:

```bash
cd ../principle && composer test
cd ../auth && composer test
```