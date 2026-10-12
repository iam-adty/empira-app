<?php

declare(strict_types=1);

namespace App;

use Empira\Application;
use Empira\Auth\AuthModule;
use Empira\Config\Env;
use Empira\Security\Csrf;
use Empira\State\SessionState;
use Empira\State\StateStore;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * A bare application, and the shortest thing that boots.
 *
 * Everything here is something a real application needs and cannot be assumed
 * to want: a secret, somewhere for records to go, somewhere for components to be
 * found, an error page, and the database settings. Nothing here is a *feature*.
 *
 * The interesting line is the module, and it is no longer in this file — it is in
 * `bootstrap/modules.php`, which says what this application is made of:
 *
 *     return [new AuthModule([new SessionGuard($app->session(), $users)])];
 *
 * Authentication used to be part of the framework, which meant every application
 * carried a users table whether it asked for one or not. It is a module now, and
 * this is what asking for one looks like — one line in one readable file, and
 * every file below it comes with the feature rather than with a README telling you
 * what to copy.
 *
 * A page is a route and nothing else: `#[Route]` on a layout class, with the
 * document built by {@see \Empira\View\Layout}.
 */
final class App
{
    /**
     * The demo secret, and the only value this application will start on
     * outside a local environment.
     *
     * It is public because it has to be recognisable, not secret — anyone can
     * read it here, which is exactly why {@see boot()} refuses it in production.
     */
    public const SECRET = 'dev-secret-change-me-please-32b';

    public static function boot(?StateStore $state = null, bool $csrf = true): Application
    {
        $app = new Application(
            secret: self::secret(),
            state: $state ?? new SessionState(),
            csrf: $csrf ? new Csrf() : null,
        );

        // A token may only resolve a reachable class, and reachability is a
        // namespace rather than a list: every component under `App\` answers its
        // own action URLs without anyone having named it here. That is what makes
        // nesting free — a component rendered only by another one used to have
        // to be registered too, and the only symptom was a 404 when somebody
        // pressed its button.
        //
        // `registry()->alias()` still exists, for a component whose token should
        // be short or survive the class moving namespaces.
        $app->components(__NAMESPACE__ . '\\');

        // A SQLite file by default, so `composer serve` needs no services. The
        // Docker compose overrides these through the environment.
        //
        // Note the schema is *not* migrated here. Migrating and seeding are
        // deploy steps — `empira migrate`, then `empira seed` — because boot is
        // per worker thread, and a boot that migrates is a boot that has eleven
        // threads racing each other through the same migration.
        self::publishDatabaseDefaults();

        // The ORM, mapped over this application's `src/` **and** the auth
        // module's directory, so `DoctrineUserProvider` can find a `User`.
        // `orm()` includes no directory of its own: a framework choosing its
        // application's schema is a decision made on the application's behalf.
        //
        // Drop this line and the module line together if you do not want
        // authentication. Neither is useful alone — a module with no mapped
        // entity cannot read a user, and a mapping with no module maps a table
        // nobody asks anything about.
        $app->orm(self::databaseConfig(), [__DIR__, AuthModule::entityPath()]);

        // What this application is made of, from `bootstrap/modules.php`.
        //
        // A closure and not a list of modules, because a module is built from
        // the container — the auth guard needs this application's session and
        // entity manager, both of which exist only now. It used to be one line
        // inline here, which scattered the answer to "what does this application
        // have?" through the middle of a boot method that also builds a logger
        // and discovers routes.
        //
        // Deleting the `return [...]` from that file is how an application drops
        // authentication, which is the point of it being a module.
        //
        // @var callable(Application): list<\Empira\Module> $modules
        $modules = require self::basePath('bootstrap/modules.php');

        $app->module(...$modules($app));

        // Where anything the framework or the app has to record goes. Before this
        // the framework logged nothing at all, so a genuine bug was re-thrown
        // into whatever PHP had configured for error output — which is usually
        // nothing.
        $app->log(self::logger());

        // Pages: every `#[Route]` under `src/`, ordered by specificity.
        $app->discover(__DIR__, __NAMESPACE__);

        $app->onHttpError(static fn(int $status, string $message): string => sprintf(
            '<!doctype html><meta charset="utf-8"><title>%d</title><h1>%d</h1><p>%s</p>',
            $status,
            $status,
            htmlspecialchars($message, ENT_QUOTES)
        ));

        return $app;
    }

    /**
 * Load `.env` into `$_ENV`, before anything reads configuration from it.
 *
 * The real environment wins: a variable already set — by Docker, by a shell, by
 * a test — is not overwritten by the file. That ordering is the only interesting
 * part, and getting it backwards makes a `.env` file silently override the
 * deployment it was supposed to be overridden by.
 */
    public static function loadEnvironment(string $basePath): void
    {
        $_ENV = array_merge(Env::fromFile($basePath . '/.env'), $_ENV);
    }

    /**
     * The application directory, for reaching files that are not autoloaded.
     *
     * Everything under `src/` is found by the autoloader and the route
     * discoverer, so this is for the few things that are neither: `bootstrap/`,
     * `.env`, and the storage directory. One method rather than a repeated
     * `dirname(__DIR__)`, because the one place it is wrong is a path that
     * silently resolves somewhere else.
     */
    private static function basePath(string $relative = ''): string
    {
        return dirname(__DIR__) . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /**
     * A Monolog logger writing JSON to stderr.
     *
     * stderr rather than a file, because a container has nowhere to keep a log
     * across a restart and `docker compose logs` is where anybody actually
     * looks. JSON rather than Monolog's default line format, because these lines
     * are meant to be read by something: `jq` over a stream of records beats
     * grepping a stack trace out of prose.
     *
     * Nothing here catches its own failure. A logger that dies mid-write is a
     * smaller problem than the 500 it caused, and `Application::logFailure()`
     * already refuses to let a broken logger replace the exception it was
     * recording.
     */
    private static function logger(): LoggerInterface
    {
        $handler = new StreamHandler(
            $_ENV['LOG_STREAM'] ?? 'php://stderr',
            self::logLevel(),
            bubble: true,
        );

        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, appendNewline: true));

        return new Logger('app', [$handler]);
    }

    /**
     * The configured level, falling back rather than refusing to boot.
     *
     * A bad `LOG_LEVEL` is a typo, and a typo should not stop the application
     * from serving traffic — it should log at the default and say so, once.
     */
    private static function logLevel(): Level
    {
        try {
            return Level::fromName(strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'info')));
        } catch (\Throwable) {
            return Level::Info;
        }
    }

    /**
     * The secret, refusing the placeholder outside a local environment.
     *
     * The failure this guards against is silent, which is why it is a boot check
     * and not a log line: a component token is
     * `base64url(payload).base64url(hmac)` and the payload carries the component
     * class and its props, so with the secret in the open anyone can mint a
     * signed URL for any component with any props they like. Nothing 500s,
     * nothing is logged, and the forged URL simply works.
     *
     * **This is opt-in, and worth being plain about.** An unset `APP_ENV` counts
     * as local, so the guard fires when somebody sets `APP_ENV=production` and
     * forgets the secret — not when they set neither.
     */
    private static function secret(): string
    {
        $secret = (string) ($_ENV['APP_SECRET'] ?? self::SECRET);

        if ($secret !== self::SECRET || self::isLocal()) {
            return $secret;
        }

        throw new \RuntimeException(sprintf(
            'Refusing to start with the placeholder APP_SECRET outside a local environment. '
            . 'Generate one with: php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\' and set it in '
            . 'APP_SECRET. It must be at least 16 bytes, and it must not change between deploys — '
            . 'changing it invalidates every component link already in a user\'s history.'
        ));
    }

    private static function isLocal(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'local') === 'local';
    }

    /**
     * Database settings, from the environment — which {@see
     * publishDatabaseDefaults()} has already filled in.
     *
     * @return array<string, mixed>
     */
    private static function databaseConfig(): array
    {
        return [
            'driver' => $_ENV['DB_DRIVER'] ?? 'sqlite',
            'database' => $_ENV['DB_DATABASE'] ?? dirname(__DIR__) . '/storage/app.sqlite',
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'port' => (int) ($_ENV['DB_PORT'] ?? 0),
            'username' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Write this application's database defaults into `$_ENV` before anything
     * reads them.
     *
     * There were once two readers with two sets of defaults — the runtime wanted
     * `storage/app.sqlite`, the migration command read the same variables through
     * the framework and got `:memory:` — and `composer setup` migrated a database
     * that vanished on exit and then seeded a real one with no `users` table in
     * it. One reader, one set of defaults, called first.
     */
    private static function publishDatabaseDefaults(): void
    {
        $defaults = [
            'DB_DRIVER' => 'sqlite',
            'DB_DATABASE' => dirname(__DIR__) . '/storage/app.sqlite',
            'DB_HOST' => 'db',
            'DB_PORT' => '0',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
                $_ENV[$key] = $value;
            }
        }
    }
}