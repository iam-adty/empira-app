<?php

declare(strict_types=1);

namespace App;

use App\Auth\UserRepository;
use IamAdty\Empira\Application;
use IamAdty\Empira\Auth\DatabaseUserProvider;
use IamAdty\Empira\Auth\SessionGuard;
use IamAdty\Empira\Config\Env;
use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\Migrator;
use IamAdty\Empira\Security\Csrf;
use IamAdty\Empira\State\SessionState;
use IamAdty\Empira\State\StateStore;
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
    public const SECRET = 'dev-secret-change-me-please-32b';

    public static function boot(?StateStore $state = null, bool $csrf = true): Application
    {
        $app = new Application(
            secret: $_ENV['APP_SECRET'] ?? self::SECRET,
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
        $app->database(self::databaseConfig());

        // Migrate and seed on boot, which is a demo convenience and not a thing
        // to copy: a real application migrates as a deploy step.
        self::prepareDatabase($app);

        // Who is asking: a session cookie, which is an ambient credential and
        // therefore the thing CSRF protection exists for. The components do
        // their own CSRF check, so no global middleware is wired here.
        $users = new DatabaseUserProvider($app->db());

        $app->auth(new SessionGuard($app->session(), $users));

        // The repository the form components ask for by type.
        $app->container()->instance(UserRepository::class, new UserRepository($app->db(), $users));

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
    private static function logLevel(): Level
    {
        try {
            return Level::fromName(strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'info')));
        } catch (\UnhandledMatchError) {
            return Level::Debug;
        }
    }

    /**
     * Database settings, from the environment when there is one.
     *
     * @return array<string, mixed>
     */
    private static function databaseConfig(): array
    {
        $driver = $_ENV['DB_DRIVER'] ?? 'sqlite';

        return [
            'driver' => $driver,
            'database' => $_ENV['DB_DATABASE'] ?? dirname(__DIR__) . '/storage/app.sqlite',
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'port' => (int) ($_ENV['DB_PORT'] ?? 0),
            'username' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
        ];
    }

    /**
     * Apply migrations, then put one user in so the demo has somebody to be.
     *
     * The seeder is idempotent, but only in sequence: "is Ada here?" followed by
     * "insert Ada" is fine twice running and hopeless in parallel, which is
     * exactly what a worker does. So the pair is taken under {@see bootLock()}.
     */
    private static function prepareDatabase(Application $app): void
    {
        $connection = $app->db()->connection();

        // Asked of the connection rather than re-read from the environment:
        // `$_ENV['DB_DRIVER'] ?? 'sqlite' === 'sqlite'` binds as
        // `$_ENV['DB_DRIVER'] ?? true`, so the SQLite branch would have run —
        // and issued a SQLite-only PRAGMA — against PostgreSQL.
        if ($connection->grammar()->driver() === 'sqlite') {
            $file = (string) ($connection->fetchOne('PRAGMA database_list')['file'] ?? '');
            $directory = dirname($file);

            if ($file !== '' && !is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }
        }

        $unlock = self::bootLock($connection);

        try {
            (new Migrator($connection, dirname(__DIR__) . '/migrations'))->run();

            $users = new DatabaseUserProvider($app->db());

            if ($users->byCredential('ada@example.com') === null) {
                $app->db()->insert('users', [
                    'email' => 'ada@example.com',
                    'name' => 'Ada Lovelace',
                    'password_hash' => $users->hash('correct horse battery staple'),
                ]);
            }
        } finally {
            $unlock();
        }
    }

    /**
     * Serialise boot across the worker threads.
     *
     * Worker mode is not one boot. FrankenPHP starts a thread per CPU, and every
     * one of them runs this file, so a dozen threads can all reach the seeder
     * having all found it empty — and eleven of them die on the unique index,
     * each taking its worker down with it.
     *
     * A lock puts boot back to happening once. The first thread through
     * migrates and seeds; the rest wait, and then find the work already done.
     * PostgreSQL takes an advisory lock, which is exactly the primitive it has
     * for this. SQLite has no server to arbitrate, so it takes `flock()` on a
     * file beside the database — which is the same guarantee from the other end.
     *
     * @return callable(): void release the lock
     */
    private static function bootLock(Connection $connection): callable
    {
        if ($connection->grammar()->driver() === 'sqlite') {
            $file = (string) ($connection->fetchOne('PRAGMA database_list')['file'] ?? '');
            $path = ($file !== '' ? dirname($file) : dirname(__DIR__) . '/storage') . '/.boot.lock';

            $handle = @fopen($path, 'c');

            if ($handle !== false && flock($handle, LOCK_EX)) {
                return static function () use ($handle): void {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                };
            }
        }

        // Any value will do, so long as this application uses it and nothing
        // else does. The name is this app's, as a number.
        $connection->execute('SELECT pg_advisory_lock(404915811)');

        return static function () use ($connection): void {
            $connection->execute('SELECT pg_advisory_unlock(404915811)');
        };
    }

    /**
     * Load `.env` into `$_ENV` before anything reads configuration from it.
     */
    public static function loadEnvironment(string $basePath): void
    {
        $_ENV = array_merge(Env::fromFile($basePath . '/.env'), $_ENV);
    }
}