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
cp .env.example .env    # then paste a generated secret into APP_SECRET
composer serve          # every interface, port 8000
```

`.env` is required, not optional. `APP_SECRET` signs every component URL, and
there is no default for it — the application refuses to start until one is
present, because a fallback would be a public constant, which is a forgeable
constant. Generate one:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

`.env` is also the application's **only** source of configuration. Anything
exported in your shell is discarded before the file is read, so "what is
configured" has one answer you can read in one file. The trade is that a
deployment has to supply a real `.env` rather than injected environment
variables — see `App::CONFIG_KEYS`.

That is the framework's console rather than a raw `php -S`:
`vendor/bin/empira serve --host=0.0.0.0`. It prints what it started and re-runs
on request.

It binds **every interface**, so the page answers on `localhost:8000` and on this
machine's address on your network. A development server reachable only from the
box it runs on is awkward when the box is a VM, so the bind is deliberate.

**That means your secret is on the network.** Anything that can reach the port
can mint a signed component URL and have the application fetch any component with
any props. Nothing logs it and nothing 500s; the forged URL simply works. Use
`--host=127.0.0.1` on a network you do not control.

Pass `--host=127.0.0.1` to keep it on loopback.

Worker mode is optional and lives in its own package,
[empira-frankenphp](https://github.com/iam-adty/empira-frankenphp). Request
profiling is the same shape —
[empira-debug-tools](https://github.com/iam-adty/empira-debug-tools) — a module
that registers nothing at all outside a development environment. This
application installs neither and does not need to.

## Test it

You cannot, and that is the design.

This repository ships no test suite and no test tooling. It is the thing you
copy, so anything committed here is something you inherit without asking — and a
skeleton arrives with a working suite far more easily than it arrives without
one. The application it describes is small enough that the useful thing to check
is whether it boots and serves, which takes one command:

```bash
composer install
composer serve           # then open http://localhost:8000
```

For a real suite against this skeleton, use the workspace's scratch
application, which is generated from this repository and throws its edits away:
`dev/tools/app-test check` in the
[workspace](https://github.com/iam-adty/empira-dev).

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

A console command is the same bargain: it is one because it exists. Put it in
`src/Console/`, and `bootstrap/app.php` already discovers it — name it yourself
with `name()`, and the container supplies whatever the constructor asks for. A
command belonging to a package rather than to you is declared by that package, not
by you, through `Console\CommandProvider`.

See [AGENTS.md](https://github.com/iam-adty/empira-dev/blob/main/agents/app.md) for the wiring, the traps, and why there is no seed
command.