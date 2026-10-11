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
├── routes.php         every route the app answers
├── public/index.php   the only file the web server runs
├── src/
│   ├── App.php        wiring: registry, routes, error pages
│   ├── Layout.php     the document and its regions
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

## Known gap

`routes.php` lives at the project root rather than in `src/`. Attribute-based
routing, which would let a layout declare its own URL and delete that file, is
planned in [`../principle/docs/planned-routing.md`](../principle/docs/planned-routing.md).

## `APP_SECRET`

`.env.example` ships a **demo placeholder**. Generate your own for anything real:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

It must be at least 16 bytes, and it must not change between deploys — changing
it invalidates every component link already sitting in a user's history.