# Empira app

The reference application for [Empira](../principle) — a small page that exercises
components, routing, regions, actions and CSRF end to end.

It is deliberately small enough to read in one sitting, which is the point: it
exists so that "how do I wire this up?" has an answer you can look at.

## Run it

```bash
composer install
cp .env.example .env     # then set APP_SECRET to something of your own
composer serve           # http://localhost:8000
```

Or with the framework's own console, which handles the paths for you:

```bash
vendor/bin/empira serve
```

## Test it

```bash
composer test
```

The suite drives the app the way htmx would — rendering the page, then posting
to the signed endpoints its own markup advertises.

## Layout

```
app/
├── composer.json
├── phpunit.xml
├── Dockerfile            FrankenPHP in worker mode
├── docker/entrypoint.sh  starts FrankenPHP; worker or classic
├── docker-compose.yml    FrankenPHP + PostgreSQL
├── migrations/           versioned schema changes
├── public/index.php      the front controller, and the worker script
├── src/
│   ├── App.php           wiring: registry, database, auth, routes
│   ├── Css.php           the global stylesheet
│   ├── Auth/             UserRepository — registration
│   ├── Layouts/          SiteLayout — the master document
│   ├── Middleware/       RedirectGuests, RedirectIfAuthenticated
│   ├── Pages/            Home, Login, Register, Dashboard
│   ├── Routing/          AppRoutes — routes that are not pages
│   └── Components/       NavBar, LoginForm, RegisterForm, LogoutButton,
│                         UserCard, FormField, Widget
└── tests/AppTest.php     the app, end to end
```

## How a page is put together

A page declares its own URL, **and its own gate**, on the class that draws it:

```php
// src/Pages/DashboardLayout.php
#[Route('/dashboard', name: 'dashboard', methods: '*',
    middleware: [RedirectGuests::class])]
final class DashboardLayout extends SiteLayout
{
    protected function regions(): array
    {
        return [...parent::regions(), 'main' => fn(Region $r) => $r->component(UserCard::class)];
    }
}
```

Pages extend `SiteLayout`, which declares the document, the `nav` region and the
global stylesheet once — so a page cannot forget the navigation or spell it
differently.

`methods: '*'` is deliberate: *"this is for signed-in users"* should not depend
on which verb arrived. It is not sufficient on its own, though — forms post to
their own signed component URL, which no route middleware ever sees, so
`LoginForm` and `RegisterForm` check for that too.

## Authentication is components

The forms render and handle their own submission. An action asks for what it
needs by type, exactly as a controller does:

```php
#[Post]
public function login(Auth $auth, UserRepository $users): void
{
    $user = $users->byEmail($this->request()->string('email'));

    if ($user === null || !$users->verify($user, $this->request()->string('password'))) {
        // The same message either way: telling them apart turns the form into
        // an oracle for which addresses exist.
        $this->with('error', 'Those credentials did not match any account.');

        return;
    }

    $auth->login($user);

    $this->response()->redirect('/dashboard');
}
```

An action's return value is discarded — it responds by mutating props and the
response, which is what the swap is built from.

A component that nests another still has to be **registered**: `NavBar` renders
`LogoutButton` directly, but the token its button carries names the class and is
resolved through the registry when pressed.

## Styling

Global rules live in `Css::base()` and go in `<head>`. A component's own rules
live in its `styles()` and are emitted **inside its root element** — which is
the swap target — so they arrive again with every htmx swap, and win on order
against the global sheet.

## Running it

```bash
composer serve                      # SQLite, PHP's built-in server
docker compose up -d --wait         # PostgreSQL behind FrankenPHP, worker mode
```

The compose app runs in **worker mode** — the application boots once and serves
many requests from memory. `APP_RUNTIME=classic` runs the front controller afresh
per request instead, and `FRANKENPHP_WORKERS=1` forces a single worker, which is
how you check that nothing is carrying over between requests.

**You only need to build once.** `app/src` and `principle/src` are bind-mounted
into the container and the worker watches both, so an edit is an edit in the
running system — no `--build`, no rebuild to test a change. The image needs
rebuilding only when a `composer.json` changes, when the Dockerfile does, or on
a fresh clone.

A demo account is seeded so there is something to sign in as:

```
ada@example.com  /  correct horse battery staple
```

Images are `empira/app` and `empira/principle`; the containers are
`empira-app`, `empira-db` and `empira-principle-db`, plus the test runner as
`empira-principle` while a suite is in flight.

## Working against the local framework

`composer.json` requires `iam-adty/empira-principle` at `@dev`, and
[franzl/studio](https://packagist.org/packages/franzl/studio) links it from the
filesystem rather than Packagist:

```bash
vendor/bin/studio load ../principle
composer update iam-adty/empira-principle
```

Edit a component under `../principle/src` and reload the browser — the
symlink means there is no build step and no `vendor` copy to refresh.