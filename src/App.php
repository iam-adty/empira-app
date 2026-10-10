<?php

declare(strict_types=1);

namespace App;

use App\Components\Counter;
use App\Components\SearchBox;
use App\Components\TodoList;
use IamAdty\Empira\Application;
use IamAdty\Empira\Config\Env;
use IamAdty\Empira\Security\Csrf;
use IamAdty\Empira\State\SessionState;
use IamAdty\Empira\State\StateStore;

/**
 * The application's wiring, in one place so `public/index.php` and the test
 * suite exercise exactly the same thing.
 *
 * The page itself is a route; the document lives in {@see Layout}, and the
 * three cards inside it are regions that htmx can swap one at a time.
 */
final class App
{
    public const SECRET = 'dev-secret-change-me-please-32b';

    public static function boot(?StateStore $state = null, bool $csrf = true): Application
    {
        $app = new Application(
            secret: $_ENV['APP_SECRET'] ?? self::SECRET,
            state: $state ?? new SessionState(),
            csrf: $csrf ? new Csrf() : null,
        );

        // A token may only name a class the registry knows, so components are
        // registered here rather than discovered.
        $app->registry()
            ->alias('counter', Counter::class)
            ->alias('todos', TodoList::class)
            ->alias('search', SearchBox::class);

        (require __DIR__ . '/../routes.php')($app->routes());

        $app->onHttpError(static fn(int $status, string $message): string => sprintf(
            '<!doctype html><meta charset="utf-8"><title>%d</title><h1>%d</h1><p>%s</p>',
            $status,
            $status,
            htmlspecialchars($message, ENT_QUOTES)
        ));

        return $app;
    }

    /**
     * Load `.env` into `$_ENV` before anything reads configuration from it.
     */
    public static function loadEnvironment(string $basePath): void
    {
        $_ENV = array_merge(Env::fromFile($basePath . '/.env'), $_ENV);
    }
}