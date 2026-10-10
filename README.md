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
├── routes.php            every route the app answers
├── public/index.php      the only file the web server runs
├── src/
│   ├── App.php           wiring: registry, routes, error pages
│   ├── Layout.php        the document, and its regions
│   ├── Css.php           the stylesheet
│   └── Components/       Counter, TodoList, SearchBox
└── tests/AppTest.php     the app, end to end
```

## How a page is put together

A page is a route pointing at a layout:

```php
// routes.php
$routes->get('/', fn(Request $request, Application $app) => $app->view(Layout::class), 'home');
```

The layout declares **regions**, and each region is one card htmx can swap on its
own:

```php
// src/Layout.php
protected function regions(): array
{
    return [
        'counter' => fn(Region $region) => $region
            ->element('section')->class('card')
            ->append($region->component(Counter::class)),
    ];
}
```

The same route answers both kinds of request. A browser gets the whole document;
a request carrying `HX-Request` gets only the region htmx named. The handler
never has to know which it is serving.

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