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
composer serve          # loopback, port 8000
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

It binds **loopback only**, so the page answers on `localhost:8000` and nowhere
else. That is the default on purpose: `APP_SECRET` signs every component URL, so
a development server reachable from the network is one where anything that can
reach the port can mint a signed component URL and have the application fetch any
component with any props. Nothing logs it and nothing 500s; the forged URL simply
works. Loopback is where you want it by default; reaching it deliberately is the
part you have to ask for.

**To reach it from another machine**, pass the address:

```bash
vendor/bin/empira serve --host=0.0.0.0
```

That answers on this machine's network address as well as loopback. Use it when
the box is a VM you reach from elsewhere, and not on a network you do not
control.

Worker mode is optional and lives in its own package,
[empira-frankenphp](https://github.com/iam-adty/empira-frankenphp). Request
profiling is the same shape —
[empira-debug-tools](https://github.com/iam-adty/empira-debug-tools) — a module
that registers nothing at all outside a development environment. This
application installs neither and does not need to.

### htmx is committed, not fetched

`public/htmx.min.js` is htmx 2.0.4, committed here rather than pulled from a CDN
on every page load. There is no build step and no network dependency at runtime.

The framework's own default is still a CDN URL, because a library cannot know
where an application keeps its webroot — assuming the skeleton would break anyone
who did not install from it. `SiteLayout::htmxUrl()` is the override that points
here.

To change versions, replace `public/htmx.min.js` and update the hash recorded in
`public/htmx.LICENSE`, which is how an upgrade gets verified rather than assumed.
htmx is Zero-Clause BSD, so keeping it costs nothing.

Anything else you drop into `public/` is served too — but only because
`public/index.php` returns `false` for an existing file when it is running as the
built-in server's router script. Without that, `php -S` hands every request,
static ones included, to your application, and it 404s its own assets. That is
invisible under nginx, which serves `public/` before PHP runs, and total here.

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