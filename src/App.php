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
     * The configuration keys this application owns, read from `.env` and from
     * nowhere else.
     *
     * Declared rather than derived, because {@see loadEnvironment()} has to
     * decide what to discard before it has read anything, and the alternative —
     * keeping every inherited value that the file happens not to mention —
     * means the shell is still a configuration source, quietly, for exactly the
     * keys someone forgot to write down.
     *
     * Each of these is read somewhere: `APP_ENV` by installed modules that ask
     * whether this is a development environment, `APP_SECRET` by
     * {@see secret()}, `LOG_STREAM` and `LOG_LEVEL` by {@see logger()},
     * `DB_DRIVER` and `DB_DATABASE` by {@see publishDatabaseDefaults()} and again
     * by the framework's own database configuration.
     *
     * `EMPIRA_BOOTSTRAP` is absent and cannot be here: `Console\Bootstrap` reads
     * it to decide which file to require, so it is consulted before this
     * application has loaded anything at all.
     *
     * The cost of the rule is worth stating once. Injected environment variables
     * stop reaching the application — `docker run -e`, a Kubernetes
     * `envFrom:` Secret, `Environment=` in a unit file, a CI secret — because
     * the value never arrives in `$_ENV`. A deployment supplies a real `.env`
     * file instead, which means the secret is on disk and belongs in a file mode
     * its owner cannot read.
     */
    private const CONFIG_KEYS = [
        'APP_ENV',
        'APP_SECRET',
        'LOG_STREAM',
        'LOG_LEVEL',
        'DB_DRIVER',
        'DB_DATABASE',
    ];

    /**
     * The application, for serving web requests.
     *
     * This is the entry point `public/index.php` uses and the only one that
     * enables CSRF. The console has its own — {@see bootConsole()} — because a
     * command line has no cookies to protect and no session to protect them in.
     */
    public static function boot(?StateStore $state = null): Application
    {
        return self::build($state, new Csrf());
    }

    /**
     * The application, for the `empira` console.
     *
     * Same wiring as {@see boot()} — the ORM, the modules, the routes — because
     * `empira migrate` needs the modules and `empira route:list` needs the
     * routes, and a console that saw less than the web would be a second,
     * smaller application rather than the same one. The one difference is CSRF,
     * which is a property of a browser session and has no meaning here.
     *
     * Split from `boot()` rather than flagged inside it: `csrf: false` read as an
     * argument to a method named for the web was a contradiction, and the next
     * console-only concern would have arrived as a second boolean. Two named
     * entry points put the choice where it belongs.
     *
     * Command discovery is **not** here. It is in `bootstrap/app.php`, which is
     * the console's own entry — this class builds an application, and what an
     * application chooses to expose on a command line is the application's
     * business.
     */
    public static function bootConsole(?StateStore $state = null): Application
    {
        return self::build($state, null);
    }

    /**
     * The wiring, shared by both entry points.
     *
     * One copy, so the console can never drift from the web — which is the
     * failure that would be invisible until somebody ran `empira migrate` against
     * an application whose modules had not been registered, and got a schema
     * missing every table the module is responsible for.
     */
    private static function build(?StateStore $state, ?Csrf $csrf): Application
    {
        $app = new Application(
            secret: self::secret(),
            state: $state ?? new SessionState(),
            csrf: $csrf,
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

        // A SQLite file by default, so `composer serve` needs nothing installed
        // and nothing running. `DB_DATABASE` in the environment moves it.
        //
        // Note the schema is *not* migrated here. Migrating is a deploy step —
        // `empira migrate` — because boot is per worker thread, and a boot that
        // migrates is a boot that has eleven threads racing each other through
        // the same migration.
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
 * The real environment wins: a variable already set — by the shell, by a
 * process manager, by a test — is not overwritten by the file. That ordering is the only interesting
 * part, and getting it backwards makes a `.env` file silently override the
 * deployment it was supposed to be overridden by.
 */
    public static function loadEnvironment(string $basePath): void
    {
        // Stripped before the file is read, not after. Merging the other way
        // round — file first, inherited second, which is the dotenv convention —
        // means anything the shell exported wins, so `export APP_ENV=production`
        // in a terminal silently changes what a development machine serves. Two
        // answers to "what configuration is in effect", one of them invisible.
        //
        // Unsetting rather than merely overwriting is what makes the guarantee
        // complete: a key `.env` does not mention falls through to this
        // application's own default instead of being inherited from outside,
        // where a stale export would otherwise outlive the line that set it.
        foreach (self::CONFIG_KEYS as $key) {
            unset($_ENV[$key]);
        }

        $_ENV = array_merge($_ENV, Env::fromFile($basePath . '/.env'));
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
     * stderr rather than a file, because a log file needs rotating and cleaning
     * and nobody reads one during development. JSON rather than Monolog's default
     * line format, because these lines are meant to be read by something: `jq`
     * over a stream of records beats grepping a stack trace out of prose.
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
     * The secret, required.
     *
     * The failure this guards against is silent, which is why it is a boot check
     * and not a log line: a component token is
     * `base64url(payload).base64url(hmac)` and the payload carries the component
     * class and its props, so with the secret in the open anyone can mint a
     * signed URL for any component with any props they like. Nothing 500s,
     * nothing is logged, and the forged URL simply works.
     *
     * ## Why there is no fallback
     *
     * There was one, and it was a constant in this file — a demo value the
     * application started on, permitted outside a local environment only. That
     * was worse than it looks. The value was public in three places (this file,
     * `.env.example`, and every clone on GitHub), so "change it before
     * deploying" was a step every deployment had to remember, and the guard that
     * was supposed to catch the ones that forgot depended on `APP_ENV` being set
     * correctly. Set neither and you were serving forgeable component URLs on
     * every interface `composer serve` binds.
     *
     * So the secret is now read from `.env` and there is nothing to fall back to.
     * A missing one stops the application in every environment, which makes the
     * first run of a fresh clone one step longer and every deploy one safer.
     *
     * The 16-byte floor is {@see \Empira\TokenCodec}'s, enforced here as well so
     * that a too-short value is reported as what it is — a configuration mistake
     * — rather than as a generic argument error from a constructor two layers
     * down.
     */
    private static function secret(): string
    {
        $secret = (string) ($_ENV['APP_SECRET'] ?? '');

        if (strlen($secret) >= 16) {
            return $secret;
        }

        throw new \RuntimeException(sprintf(
            'APP_SECRET is %s. Generate one with: php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\' '
            . 'and put it in %s beside the rest of .env.example. It must be at least 16 bytes, and it '
            . 'must not change between deploys — changing it invalidates every component link already '
            . 'in a user\'s history.',
            $secret === '' ? 'not set' : sprintf('too short (%d bytes, 16 needed)', strlen($secret)),
            self::basePath('.env'),
        ));
    }

    /**
     * Database settings, from the environment — which {@see
     * publishDatabaseDefaults()} has already filled in.
     *
     * Two settings, because SQLite is the only driver. `DB_HOST`, `DB_PORT`,
     * `DB_USERNAME` and `DB_PASSWORD` went when the framework's support for
     * PostgreSQL and MySQL did; `DB_HOST` defaulted to `db`, which was the name
     * of a compose service, so it was already meaningless to anyone else.
     *
     * @return array<string, mixed>
     */
    private static function databaseConfig(): array
    {
        return [
            'driver' => $_ENV['DB_DRIVER'] ?? 'sqlite',
            'database' => $_ENV['DB_DATABASE'] ?? dirname(__DIR__) . '/storage/app.sqlite',
        ];
    }

    /**
     * Write this application's database defaults into `$_ENV` before anything
     * reads them.
     *
     * There were once two readers with two sets of defaults — the runtime wanted
     * `storage/app.sqlite`, the migration command read the same variables through
     * the framework and got `:memory:` — and the migration against `:memory:` was
     * a database that vanished on exit, followed by an application querying a real
     * one with no `users` table in it. One reader, one set of defaults, called
     * first.
     */
    private static function publishDatabaseDefaults(): void
    {
        $defaults = [
            'DB_DRIVER' => 'sqlite',
            'DB_DATABASE' => dirname(__DIR__) . '/storage/app.sqlite',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
                $_ENV[$key] = $value;
            }
        }
    }
}