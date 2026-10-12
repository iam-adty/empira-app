<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use Empira\Auth\Auth;
use Empira\Auth\AuthModule;
use Empira\Auth\User;
use Empira\Console;
use Empira\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The skeleton, proven to be a working application.
 *
 * This used to be 45 tests of a demo: sign-in, registration, rate limiting, a
 * dashboard, a nav bar. All of that code is gone, and so are the tests — a test
 * for a feature nobody ships is a test nobody reads and a suite that fails for
 * reasons unrelated to what it is meant to catch.
 *
 * What is left is the question a skeleton actually has to answer: *does this
 * boot, and does it serve?* That takes far fewer tests than it used to, and the
 * ones here are the ones whose absence would be a real problem — a page that
 * does not render, a module that does not install, a secret guard that does not
 * guard, a console that cannot build the schema.
 */
final class AppTest extends TestCase
{
    /**
     * One database for the whole class, thrown away afterwards.
     *
     * Per test would be tidier in principle and ruinous in practice: hashing a
     * user with bcrypt costs about half a second, so a fresh file per test
     * turned a 0.07s suite into a 22s one.
     */
    private static string $database = '';

    public static function setUpBeforeClass(): void
    {
        // The shared `tmp/`, for the same reason the framework's suite has one: a
        // failing run leaves its database somewhere you would think to look.
        // Two levels up, which in the workspace is above every package — and
        // inside the container the workspace is mounted at `/workspace`, so the
        // same string resolves on the host and in the runner.
        //
        // A clone of this application alone writes `tmp/` next to itself, one
        // directory above wherever it was cloned. Untidy, harmless, and the
        // reason this creates the folder rather than assuming one exists.
        $root = dirname(__DIR__, 2) . '/tmp';

        if (! is_dir($root)) {
            mkdir($root, 0o777, true);
        }

        self::$database = $root . '/empira-app-test-' . bin2hex(random_bytes(6)) . '.sqlite';

        // The schema is prepared here rather than by the application booting,
        // because the application does not migrate on boot — and that is the
        // point of it. A suite that depends on boot to build its database cannot
        // survive the change that stops boot migrating.
        //
        // So this is the deploy step, run once, exactly as it would be in
        // production: `empira migrate`, against a database that is not there yet.
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = self::$database;

        self::runConsole(['migrate']);
    }

    /**
     * Remove the database this class created.
     *
     * The workspace `tmp/` is shared by all three suites and is documented as
     * holding nothing between runs — `ls -A tmp` is the check. A SQLite file
     * left behind is a stale schema that a later run reads as its own, and a
     * directory nobody trusts is a directory nobody checks.
     *
     * This method went missing when the suite was reduced from 45 tests to 15
     * and the fixture was rebuilt, which is exactly the kind of loss that a
     * shrinking suite invites: the lines that are not a test are the first
     * things to look optional.
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$database !== '' && is_file(self::$database)) {
            unlink(self::$database);
        }

        self::$database = '';
    }

    protected function tearDown(): void
    {
        if (isset($_SESSION)) {
            $_SESSION = [];
        }

        unset($_ENV['APP_ENV'], $_ENV['APP_SECRET']);
    }

    // ------------------------------------------------------------- serving

    public function testTheFrontPageRendersForEveryone(): void
    {
        $response = App::boot()->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('htmx lives in PHP classes', $response->body());
    }

    /**
     * The document is a `Layout` and the page contributes a region, so the page
     * arrives as markup rather than as a response body with a template applied.
     */
    public function testThePageIsRenderedThroughItsLayout(): void
    {
        $body = App::boot()->handle(new Request('GET', '/'))->body();

        self::assertStringContainsString('<html', $body);
        self::assertStringContainsString('id="region-main"', $body, 'the page did not render its own region');
        self::assertStringContainsString('<style', $body, 'the layout emitted no stylesheet');
    }

    /**
     * A full request renders the whole document; an htmx request renders only
     * the region it named. Same route, same layout, two shapes — and the second
     * is the entire reason this framework exists.
     */
    public function testAnHtmxRequestGetsOnlyTheRegionItAskedFor(): void
    {
        $body = App::boot()->handle(new Request(
            'GET',
            '/',
            headers: ['hx-request' => 'true', 'hx-target' => 'region-main'],
        ))->body();

        self::assertStringContainsString('id="region-main"', $body);
        self::assertStringNotContainsString('<html', $body, 'a fragment should not carry the document');
    }

    public function testUnknownRoutesAre404(): void
    {
        self::assertSame(404, App::boot()->handle(new Request('GET', '/nope'))->status());
    }

    /**
     * The application's own error view, which is what a real 404 page is. It
     * replaces the body and must not disturb the status — a view that changed
     * the response's meaning would turn every refusal into a 200.
     */
    public function testTheErrorViewRendersWithoutChangingTheStatus(): void
    {
        $response = App::boot()->handle(new Request('GET', '/nope'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('404', $response->body());
    }

    // ------------------------------------------------------------- the module

    /**
     * The line this skeleton exists to show: authentication is installed, and
     * the framework binds its own read-only context without being told to.
     */
    public function testTheAuthModuleIsInstalledAtBoot(): void
    {
        $app = App::boot();

        self::assertCount(1, $app->modules());
        self::assertInstanceOf(AuthModule::class, $app->modules()[0]);
        self::assertCount(1, $app->guards(), 'the module registered no guard');
    }

    /**
     * The module's façade resolves by type, because the module binds it — not
     * the framework. If that binding went away with the module, this is the test
     * that would notice, and the symptom in production is a container that
     * cannot build a type-hinting controller.
     */
    public function testTheModulePublishesItsOwnAuthFacade(): void
    {
        $app = App::boot();
        $app->handle(new Request('GET', '/'));

        self::assertInstanceOf(Auth::class, $app->container()->get(Auth::class));
    }

    /**
     * The `users` table ships with the module, so the schema arrived without
     * this application writing a migration. Proved by the deploy step above
     * succeeding against a database that did not exist — if the module's
     * migrations were not discovered, `users` would be absent and this would
     * throw rather than return null.
     */
    public function testTheModuleBroughtItsTableWithIt(): void
    {
        $app = App::boot();

        self::assertTrue(
            $app->entities()->getConnection()->createSchemaManager()->tablesExist(['users']),
            'the auth module\'s migration did not run — a table it owns is missing'
        );
    }

    public function testAUserCanBePersistedThroughTheModulesEntityMapping(): void
    {
        $app = App::boot();

        $app->entities()->persist($user = new User('skeleton@example.com', 'Skeleton'));
        $app->entities()->flush();

        self::assertNotNull($user->id(), 'the User entity was not mapped, or not flushed');

        $app->entities()->clear();

        self::assertSame(
            'skeleton@example.com',
            $app->entities()->getRepository(User::class)->find($user->id())?->email()
        );
    }

    /**
     * `AuthModule::entityPath()` is load-bearing in a way that is easy to miss,
     * because Doctrine separates **discovery** from **lookup**.
     *
     * `getClassMetadata(User::class)` reflects on the class you *name*, so it
     * works whether or not the entity's directory was ever registered — which
     * is why the test above still passes with the path omitted, and why a
     * missing path can look like it costs nothing.
     *
     * `getAllClassNames()` is the discovery half, and it scans the registered
     * directories. It is what the schema tooling reads. So the failure mode of
     * forgetting the path is not an exception at request time; it is a schema
     * tool that quietly omits a table an entity depends on, and generates DDL
     * against a database that does not match the mapping.
     *
     * This test therefore asserts on discovery, which is the half that breaks.
     */
    public function testTheModuleEntityDirectoryIsDiscoveredNotMerelyReachable(): void
    {
        $discovered = self::discoveredClasses([dirname(__DIR__) . '/src', AuthModule::entityPath()]);

        self::assertContains(
            User::class,
            $discovered,
            'the module\'s entity directory was not registered, so schema tooling cannot see User'
        );
    }

    /**
     * Every class the given entity paths can *discover*, which is the set the
     * schema tooling works from.
     *
     * Built from the framework's own public factory rather than by reflecting
     * into Doctrine's `EntityManager`, which keeps the assertion honest: it asks
     * the configuration what it would scan, not what a particular manager
     * happens to have cached.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function discoveredClasses(array $paths): array
    {
        return \Empira\Persistence\EntityManagerFactory::configuration($paths)
            ->getMetadataDriverImpl()
            ->getAllClassNames();
    }

    // ------------------------------------------------------------- the secret

    public function testThePlaceholderSecretIsFineWhenNothingSaysOtherwise(): void
    {
        self::assertSame(200, App::boot()->handle(new Request('GET', '/'))->status());
    }

    public function testThePlaceholderSecretRefusesToStartOutsideLocal(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to start with the placeholder APP_SECRET');

        App::boot();
    }

    public function testARealSecretIsAcceptedOutsideLocal(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_SECRET'] = bin2hex(random_bytes(32));

        self::assertSame(200, App::boot()->handle(new Request('GET', '/'))->status());
    }

    // ------------------------------------------------------------- the console

    /**
     * The deploy step, driven the way an operator types it.
     *
     * Run twice on purpose: a deploy step that is not idempotent fails the
     * second time a pipeline retries it, and a suite that only ever runs it once
     * cannot tell the difference between "works" and "works once".
     */
    public function testMigratingIsIdempotent(): void
    {
        self::assertStringContainsString('Nothing to migrate', self::runConsole(['migrate'])['output']);
    }

    /**
     * This application registers no commands of its own, so `list` shows only the
     * framework's. Worth a test because it is the check that a leftover
     * `$app->command(…)` did not survive the strip — a `seed` for demo
     * credentials would be exactly the kind of thing that should not be here.
     */
    public function testThisApplicationRegistersNoCommandsOfItsOwn(): void
    {
        $output = self::runConsole(['list'])['output'];

        self::assertStringNotContainsString('seed', $output);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Run one console command, require that it succeeded, and hand back what it
     * printed.
     *
     * Takes argv as an array rather than a command line, because the console
     * parses argv elements and not a string.
     *
     * @param list<string> $argv
     *
     * @return array{code: int, output: string}
     */
    private static function runConsole(array $argv): array
    {
        $previous = getcwd();
        $stream = fopen('php://memory', 'r+b');
        $output = new Console\Output($stream, false);

        try {
            chdir(dirname(__DIR__));

            $console = new Console\Application();

            // Exactly what `bin/empira` does, and the reason is not tidiness:
            // `migrate` plans across the migrations *modules* contribute, and it
            // learns those from the booted application. A test that drove the
            // console without handing it the booted app would migrate a schema
            // with no `users` table — a failure that points at the database
            // rather than at the wiring that was missing.
            $booted = Console\Bootstrap::boot(dirname(__DIR__));

            if ($booted !== null) {
                foreach ($booted->commands() as $name => $projectCommand) {
                    $console->project($name, $projectCommand);
                }

                $console->booted($booted);
            }

            $exit = $console->run(array_merge(['empira'], $argv), $output);
        } finally {
            chdir((string) $previous);
        }

        rewind($stream);
        $printed = trim((string) stream_get_contents($stream));
        fclose($stream);

        self::assertSame(0, $exit, sprintf('`empira %s` should succeed: %s', implode(' ', $argv), $printed));

        return ['code' => $exit, 'output' => $printed];
    }
}