<?php

declare(strict_types=1);

namespace App;

use App\App\EnvSecret;
use App\App\Modules;
use App\App\Overrides;
use App\App\ProvidesLogger;
use App\App\ProvidesSecret;
use App\App\ProvidesStorage;
use App\App\SqliteStorage;
use App\App\StderrLogger;
use App\Pages\LoginLayout;
use Empira\Application;
use Empira\Auth\AuthModule;
use Empira\Config\Env;
use Empira\ConfigurableModule;
use Empira\Security\Csrf;
use Empira\State\SessionState;
use Empira\State\StateStore;
use ReflectionAttribute;
use ReflectionClass;

/**
 * The application.
 *
 * `Application` already defaults everything that has a defensible default, so
 * this class supplies only the decisions that are *this application's*: a
 * secret, a logger, a database, a module list and a page — and it says all five
 * in the two attributes above, where they can be read without reading a method.
 *
 * Nothing here is a feature. Authentication is a module, named in
 * `#[Modules]`; delete it from that attribute and this file, the `users` table
 * and the login page all stop existing, which is the only honest way for a
 * development tool to be optional.
 *
 * A page is a route and nothing else: `#[Route]` on a layout class, discovered
 * by scanning `src/`.
 */
#[Modules([AuthModule::class], login: LoginLayout::class)]
#[Overrides(secret: EnvSecret::class, logger: StderrLogger::class, storage: SqliteStorage::class)]
final class App extends Application
{
    /**
     * The web entry point, and the only one that enables CSRF.
     *
     * `public/index.php` calls this.
     */
    public static function boot(?StateStore $state = null): static
    {
        return new static($state, new Csrf());
    }

    /**
     * The console's entry point.
     *
     * Same wiring — `empira migrate` needs the modules and `empira route:list`
     * needs the routes, and a console that saw less than the web would be a
     * second, smaller application. The one difference is CSRF, which is a
     * property of a browser session and has no meaning here.
     *
     * Split from {@see boot()} rather than flagged inside it: `csrf: false` read
     * as an argument to a method named for the web was a contradiction, and the
     * next console-only concern would have arrived as a second boolean.
     */
    public static function bootConsole(?StateStore $state = null): static
    {
        return new static($state);
    }

    public function __construct(?StateStore $state = null, ?Csrf $csrf = null)
    {
        $overrides = self::attribute(Overrides::class);
        $modules = self::attribute(Modules::class);

        self::publishDatabaseDefaults(self::override($overrides->storage, ProvidesStorage::class)->path());

        parent::__construct(
            secret: self::override($overrides->secret, ProvidesSecret::class)->secret(),
            state: $state ?? new SessionState(),
            csrf: $csrf,
        );

        // Every component under `App\` answers its own action URLs without anyone
        // having named it, which is what makes nesting free.
        $this->components(__NAMESPACE__ . '\\');

        // The module's entity path is listed here rather than added by the
        // module: mapping an application's schema is a decision about that
        // application, and only it knows what else it is mapping.
        $this->orm([__DIR__, AuthModule::entityPath()]);

        $this->module(...$this->resolveModules($modules));

        $this->log(self::override($overrides->logger, ProvidesLogger::class)->logger());

        $this->discover(__DIR__, __NAMESPACE__);
    }

    /**
     * Load `.env` into `$_ENV`, before anything reads configuration from it.
     *
     * The keys this application owns are stripped **before** the file is read, so
     * a value the shell exported cannot win. That ordering is the whole
     * interesting part, and getting it backwards makes `.env` silently override
     * the deployment it was meant to be overridden by — or lets
     * `export APP_ENV=production` in one terminal change what a development
     * machine serves, leaving two answers to "what configuration is in effect".
     *
     * The cost is real: injected environment variables stop reaching the
     * application, because a value that never arrives in `$_ENV` cannot be
     * consulted. A deployment supplies a real `.env` file instead, which means
     * the secret is on disk and belongs in a file mode its owner cannot read.
     *
     * @var list<string>
     */
    private const CONFIG_KEYS = [
        'APP_ENV',
        'APP_SECRET',
        'LOG_STREAM',
        'LOG_LEVEL',
        'DB_DRIVER',
        'DB_DATABASE',
    ];

    public static function loadEnvironment(string $basePath): void
    {
        foreach (self::CONFIG_KEYS as $key) {
            unset($_ENV[$key]);
        }

        $_ENV = array_merge($_ENV, Env::fromFile($basePath . '/.env'));
    }

    /**
     * The modules named in the attribute, resolved and configured.
     *
     * Resolved through the container so a module can build itself from what the
     * framework has already bound — `AuthModule` is the case that matters, since
     * it needs this application's session and entity manager to build the guard
     * that makes signing in work at all.
     *
     * @return list<\Empira\Module>
     */
    private function resolveModules(Modules $modules): array
    {
        $options = ['login' => $modules->login];

        return array_map(function (string $class) use ($options): \Empira\Module {
            // `container()`, not `$this->container` — the framework's field is
            // private, so a subclass sees a null one that looks like it works.
            $module = $this->container()->get($class);

            // A module that takes options says so; one that does not simply has
            // none, and neither this file nor the framework has to know which.
            if ($module instanceof ConfigurableModule) {
                $module->configure($options);
            }

            return $module;
        }, $modules->modules);
    }

    /**
     * Write this application's database default where both readers will find it.
     *
     * The framework's own configuration defaults to `:memory:`. Left alone, a
     * migration against that is a database which vanishes on exit, followed by an
     * application querying a real one with no tables in it.
     */
    private static function publishDatabaseDefaults(string $path): void
    {
        foreach (['DB_DRIVER' => 'sqlite', 'DB_DATABASE' => $path] as $key => $value) {
            if (! isset($_ENV[$key]) || $_ENV[$key] === '') {
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * One named override, or a refusal saying exactly what is wrong.
     *
     * A class name in an attribute is a string until something checks it, so this
     * is where the check happens — before the application has built anything, so
     * a typo is a boot that stops with a message rather than a request that does.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param class-string $contract
     * @return T
     */
    private static function override(string $class, string $contract): object
    {
        if (! is_a($class, $contract, true)) {
            throw new \LogicException(sprintf(
                '#[Overrides] named %s, which does not implement %s.',
                class_exists($class) ? $class : sprintf('%s (no such class)', $class),
                $contract,
            ));
        }

        return new $class();
    }

    /**
     * This class's own attribute of the given type.
     *
     * `static::class` rather than `self::class`, so a subclass reads its own.
     */
    private static function attribute(string $name): object
    {
        $found = (new ReflectionClass(static::class))->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF);

        if ($found === []) {
            throw new \LogicException(sprintf(
                '%s must declare #[%s] — an application with neither has nothing to build itself from.',
                static::class,
                $name,
            ));
        }

        return $found[0]->newInstance();
    }
}