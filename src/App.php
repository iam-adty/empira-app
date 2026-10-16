<?php

declare(strict_types=1);

namespace App;

use App\Pages\LoginLayout;
use Empira\Application;
use Empira\Application\EnvSecret;
use Empira\Application\Modules;
use Empira\Application\Overrides;
use Empira\Application\SqliteStorage;
use Empira\Application\StderrLogger;
use Empira\Auth\AuthModule;
use Empira\Auth\LoginPage;
use Empira\Security\Csrf;
use Empira\State\SessionState;
use Empira\State\StateStore;

/**
 * The application.
 *
 * `Application` already defaults everything that has a defensible default, so
 * this class supplies only the decisions that are *this application's*: a
 * secret, a logger, a database, a module list and a page — and it says all five
 * in the attributes above, where they can be read without reading a method.
 *
 * Nothing here is a feature. Authentication is a module, named in `#[Modules]`;
 * delete it from that attribute and this file, the `users` table and the login
 * page all stop existing, which is the only honest way for a development tool to
 * be optional.
 *
 * A page is a route and nothing else: `#[Route]` on a layout class, discovered
 * by scanning `src/`.
 *
 * ## What is *not* here, and why
 *
 * The machinery that reads these attributes — `attribute()`, `override()`,
 * `basePath()`, `installModules()` — lives on `Application`. It was written
 * twice, identically, in this file and in the scratch application's own `App`,
 * and drifted twice: a missing `loadEnvironment()`, and `orm()` handed entity
 * paths in its configuration argument. Nothing here is application-specific,
 * so nothing here is duplicated.
 */
#[Modules(AuthModule::class)]
#[LoginPage(LoginLayout::class)]
#[Overrides(secret: EnvSecret::class, logger: StderrLogger::class, storage: SqliteStorage::class)]
final class App extends Application
{
    /**
     * The configuration keys this application owns, read from `.env` and from
     * nothing else.
     *
     * A list rather than "whatever is in the file" so that a key the file does
     * not mention falls through to this application's own default instead of
     * being inherited from outside.
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

        // The framework's default is a relative path, because it cannot know
        // where this application is. `basePath()` is it finding out.
        self::publishDatabaseDefaults(self::basePath(
            self::override($overrides->storage, \Empira\Application\ProvidesStorage::class)->path(),
        ));

        parent::__construct(
            secret: self::override($overrides->secret, \Empira\Application\ProvidesSecret::class)->secret(),
            state: $state ?? new SessionState(),
            csrf: $csrf,
        );

        // Every component under `App\` answers its own action URLs without anyone
        // having named it, which is what makes nesting free.
        $this->components(__NAMESPACE__ . '\\');

        // The module's entity path is listed here rather than added by the
        // module: mapping an application's schema is a decision about that
        // application, and only it knows what else it is mapping.
        // `null` first, explicitly: `orm()` takes the database config as its
        // first argument and the entity paths second, so a call that looks
        // right and omits the null passes the paths as the configuration.
        // Nothing errors — DatabaseConfig finds no `driver` and no `database`,
        // supplies its own defaults, and the application opens `:memory:` while
        // every query against it answers. The comment is here because the
        // signature invites that mistake.
        $this->orm(null, [__DIR__, AuthModule::entityPath()]);

        $this->installModules(self::attribute(Modules::class)->modules);

        $this->log(self::override($overrides->logger, \Empira\Application\ProvidesLogger::class)->logger());

        $this->discover(__DIR__, __NAMESPACE__);

        // A page rather than a bare status line, for every error the framework
        // raises — a missing route, a refused action, a bad token. The view sets
        // the body and nothing else: the framework keeps the status and the
        // headers, so a 405 still carries its `Allow` and a 429 its
        // `Retry-After`.
        //
        // Dropping this is not a simplification. Without it the body is the
        // exception's message alone — "Not Found" — which is a fact about the
        // failure rather than a page somebody can be shown.
        $this->onHttpError(static fn (int $status, string $message): string => sprintf(
            '<!doctype html><meta charset="utf-8"><title>%d</title><h1>%d</h1><p>%s</p>',
            $status,
            $status,
            htmlspecialchars($message, ENT_QUOTES),
        ));
    }

    /**
     * Load `.env`, naming the keys this application owns.
     *
     * One line over the framework's method, and the reason it is here: the keys
     * are this application's. The framework cannot know that `LOG_LEVEL` is
     * configuration and `NOT_A_REAL_KEY` is not, and passing a list rather than
     * reading the file's keys is what keeps that true — a plain merge would strip
     * nothing and leave an exported variable standing.
     *
     * Both entry points call this with no arguments, because this class already
     * knows where it is — `static::basePath()` reads it off this file, and
     * `EMPIRA_APP_ROOT` overrides that for a deployment laid out differently.
     * The mechanism — the ordering, the merge, the refusal to guess — is
     * {@see Application::loadEnvironment()}.
     *
     * The signature is the parent's rather than a narrower one, because PHP will
     * not let a subclass reduce what it accepts. `$keys` is therefore *added* to
     * this application's own, which is the only useful direction anyway: a
     * caller naming an extra key wants it stripped alongside the rest.
     *
     * @param list<string> $keys further keys to strip before reading the file
     */
    public static function loadEnvironment(array $keys = [], ?string $basePath = null): void
    {
        parent::loadEnvironment([...self::CONFIG_KEYS, ...$keys], $basePath ?? static::basePath());
    }
}