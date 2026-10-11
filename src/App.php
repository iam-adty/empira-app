<?php

declare(strict_types=1);

namespace App;

use App\Auth\DemoGuard;
use App\Auth\UserRepository;
use App\Components\LoginForm;
use App\Components\LogoutButton;
use App\Components\NavBar;
use App\Components\RegisterForm;
use App\Components\UserCard;
use App\Components\Widget;
use IamAdty\Empira\Application;
use IamAdty\Empira\Auth\DatabaseUserProvider;
use IamAdty\Empira\Auth\SessionGuard;
use IamAdty\Empira\Config\Env;
use IamAdty\Empira\Data\Migrator;
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
            ->alias('navbar', NavBar::class)
            // Registered even though nothing renders it directly: NavBar nests
            // it, and a nested component's action token is still resolved
            // through the registry when the action fires.
            ->alias('logout', LogoutButton::class)
            ->alias('login', LoginForm::class)
            ->alias('register', RegisterForm::class)
            ->alias('user', UserCard::class)
            ->alias('widget', Widget::class);

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
     * The seeder is idempotent: booting twice must not fail on a unique index.
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

        (new Migrator($connection, dirname(__DIR__) . '/migrations'))->run();

        $users = new DatabaseUserProvider($app->db());

        if ($users->byCredential('ada@example.com') === null) {
            $app->db()->insert('users', [
                'email' => 'ada@example.com',
                'name' => 'Ada Lovelace',
                'password_hash' => $users->hash('correct horse battery staple'),
            ]);
        }
    }

    /**
     * Load `.env` into `$_ENV` before anything reads configuration from it.
     */
    public static function loadEnvironment(string $basePath): void
    {
        $_ENV = array_merge(Env::fromFile($basePath . '/.env'), $_ENV);
    }
}