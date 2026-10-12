<?php

declare(strict_types=1);

namespace App;

use App\Auth\UserRepository;
use App\Console\Seed;
use App\RateLimit\RateLimiter;
use Doctrine\DBAL\DriverManager;
use Empira\Application;
use Empira\Auth\DoctrineUserProvider;
use Empira\Auth\SessionGuard;
use Empira\Config\Config;
use Empira\Config\DatabaseConfig;
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
 * The application's wiring, in one place so `public/index.php` and the test
 * suite exercise exactly the same thing.
 *
 * The page itself is a route; the document lives in {@see Layout}, and the
 * three cards inside it are regions that htmx can swap one at a time.
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

        // A worker is per-thread and this stack runs 32 of them, so the rate
        // limiter's state has to live where every thread can reach it. Binding
        // it here means a component action can ask for it by type, the same way
        // it asks for `Auth`.
        //
        // It gets its own connection rather than the ORM's: the unit of work is
        // cleared between requests, and a limiter that shared it would have its
        // counters cleared out from under it.
        $app->container()->instance(RateLimiter::class, new RateLimiter(
            DriverManager::getConnection(
                DatabaseConfig::dbalParameters(new Config(self::databaseConfig()))
            )
        ));

        // The ORM, mapped over `src/` — this application's entities, plus the
        // framework's own `Auth` ones, which `orm()` always includes so that
        // `DoctrineUserProvider` can find a user.
        $app->orm(self::databaseConfig(), [__DIR__]);

        // Who is asking: a session cookie, which is an ambient credential and
        // therefore the thing CSRF protection exists for. The components do
        // their own CSRF check, so no global middleware is wired here.
        //
        // The guard holds the *provider*, not an entity: the provider is handed
        // an EntityManager that `Application::handle()` clears between requests,
        // so a user loaded here belongs to this request and not to the worker.
        $users = new DoctrineUserProvider($app->entities());

        $app->auth(new SessionGuard($app->session(), $users));

        $repository = new UserRepository($app->entities(), $users);

        // The repository the form components ask for by type.
        $app->container()->instance(UserRepository::class, $repository);

        // The framework cannot know what this application's seeders look like,
        // so it registers them and the console collects them after booting us.
        $app->command('seed', new Seed($repository));

        // Routes that are not pages: no layout, registered deliberately.
        $app->registrar(Routing\AppRoutes::class);

        // Where anything the framework or the app has to record goes. Before this
        // the framework logged nothing at all, so a genuine bug was re-thrown
        // into whatever PHP had configured for error output — which is usually
        // nothing.
        $app->log(self::logger());

        // Pages: every `#[Route]` under `src/`. Ordering is by specificity, so
        // `/register` is matched before `/r/{anything}` should one ever exist.
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
     * `Level::fromName()` throws on anything it does not recognise, so a typo
     * in LOG_LEVEL would otherwise take the whole application down at boot —
     * and a typo in an environment variable is exactly the sort of thing that
     * should not be able to stop a deploy. An unrecognised level logs
     * everything instead: too much beats not starting.
     */
    /**
     * The token secret, refusing to run on the placeholder in production.
     *
     * The failure this guards against is silent, which is what makes it worth a
     * boot check rather than a log line. A component token is
     * `base64url(payload).base64url(hmac)` and the payload carries the component
     * class and its props — so with the secret in the open, anyone can mint a
     * signed URL for any component carrying any props they like. Nothing
     * 500s, nothing is written to any log, and the forged URL simply works.
     *
     * Refusing to start is the only honest signal left.
     *
     * **This is opt-in, deliberately.** An unset `APP_ENV` counts as local, so
     * the guard fires when someone sets `APP_ENV=production` and forgets the
     * secret — not when they set neither. That is the same bargain Laravel and
     * Symfony make, and it is worth being plain about what it does not catch: a
     * deployment that configures nothing at all still starts on the
     * placeholder. `docker-compose.yml` therefore takes `APP_ENV` from the
     * environment, so the one flag that turns this on is easy to set.
     *
     * @throws \RuntimeException when the placeholder would be used in production
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

    /**
     * Whether this is a development machine.
     *
     * Local **unless told otherwise**: unset is not a claim to be anything in
     * particular, and refusing to start a developer's first run over a variable
     * they have never heard of would be a worse failure than the one being
     * guarded against.
     */
    private static function isLocal(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'local') === 'local';
    }

    private static function logLevel(): Level
    {
        try {
            return Level::fromName(strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'info')));
        } catch (\UnhandledMatchError) {
            return Level::Debug;
        }
    }

    /**
     * Write this application's database defaults into `$_ENV`, without
     * overwriting anything that was already there.
     *
     * This exists because there were two readers of `DB_*` and two sets of
     * defaults. `App::boot()` wanted `storage/app.sqlite`; `empira migrate`,
     * reading the same variables through the framework, defaulted to `:memory:`
     * — so `composer setup` migrated a database that vanished on exit and then
     * seeded a real one that had no `users` table.
     *
     * The application owns the defaults, because only the application knows
     * where its SQLite file lives. It publishes them once, at boot, and
     * everything downstream — the runtime connection, the migration command, the
     * seeder — reads the same answer. `composer setup` boots the application
     * through `bootstrap/app.php`, which is how it inherits them too.
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
     * Load `.env` into `$_ENV` before anything reads configuration from it.
     */
    public static function loadEnvironment(string $basePath): void
    {
        $_ENV = array_merge(Env::fromFile($basePath . '/.env'), $_ENV);
    }
}