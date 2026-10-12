<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Auth\UserRepository;
use App\RateLimit\RateLimiter;
use App\Registration;
use Empira\Application;
use Empira\Auth\User;
use Empira\Config\DatabaseConfig;
use Empira\Console;
use Empira\Http\Request;
use Empira\Security\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Drives the application end to end, the way htmx would.
 *
 * Every request that submits a form uses the signed action URL extracted from
 * the page's own markup, because that is the only way a real browser reaches
 * it — the form does not post to the route that rendered it.
 */
final class AppTest extends TestCase
{
    /**
     * One database for the whole class, thrown away afterwards.
     *
     * Per test would be tidier in principle and ruinous in practice: booting
     * seeds a user, and hashing one with bcrypt costs about half a second, so a
     * fresh file per test turned a 0.07s suite into a 22s one.
     *
     * Sharing it is safe because every test signs in as itself and uses an
     * address generated for that test, so no two of them collide. What must not
     * leak is between *runs*, and the file is created fresh for each run.
     */
    private static string $database = '';

    public static function setUpBeforeClass(): void
    {
        // The workspace `tmp/`, shared with the framework's suite, for the same
        // reason it uses its own: a failing run leaves its database somewhere
        // you would think to look. Two levels up, because the folder sits above
        // both packages rather than inside either — which is also what makes it
        // resolve the same inside the container.
        $root = dirname(__DIR__, 2) . '/tmp';

        if (! is_dir($root)) {
            mkdir($root, 0o777, true);
        }

        self::$database = $root . '/empira-app-test-' . bin2hex(random_bytes(6)) . '.sqlite';

        // The schema is prepared here rather than by the application booting,
        // because the application no longer migrates on boot — and that is the
        // point of it. A test suite that depends on boot to build its database
        // cannot survive the change that stops boot migrating, and would go on
        // hiding the cost of anything else that runs at boot.
        //
        // So this is the deploy step, run once, exactly as it would be in
        // production: `empira migrate`, then `empira seed`, against a database
        // that is not there yet.
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = self::$database;

        self::runConsole(['migrate']);
        self::runConsole(['seed']);
    }

    /**
     * Run one console command and require that it succeeded.
     *
     * The console is driven rather than Doctrine or the repository being called
     * directly, so the suite exercises the path an operator actually types. A
     * test that reached past `empira migrate` and `empira seed` would keep
     * passing if those commands broke, which is the one thing worth checking.
     */
    /**
     * Run a console command, require that it succeeded, and hand back what it
     * printed.
     *
     * Takes argv as an array rather than a command line, because the console
     * parses argv elements and not a string: `seed --name=Grace Hopper` as one
     * element is one very long command name, and the space is where the
     * difference shows.
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
            // with no `users` table and then fail at the seed with "no such
            // table" — pointing at the database rather than at the wiring.
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

    public static function tearDownAfterClass(): void
    {
        if (self::$database !== '' && is_file(self::$database)) {
            unlink(self::$database);
        }
    }

    protected function setUp(): void
    {
        // A fresh session per test, but a shared database — see self::$database.
        $_SESSION = [];

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = self::$database;

        // Both limits are raised out of the way by default. This suite shares
        // one database, and several tests exist precisely to fail Ada's password
        // or to register a throwaway — so the default would let their own
        // attempts accumulate and lock each other out, and the suite would pass
        // or fail depending on order. The limit tests below lower one
        // deliberately.
        $_ENV['RATE_LIMIT_LOGIN_IP'] = '10000';
        $_ENV['RATE_LIMIT_LOGIN_EMAIL'] = '10000';
        $_ENV['RATE_LIMIT_REGISTER'] = '10000';
    }

    protected function tearDown(): void
    {
        unset($_ENV['DB_DRIVER'], $_ENV['DB_DATABASE']);

        // Cleared here rather than at the end of each test body, because a test
        // that fails on an assertion never reaches its own cleanup — and a
        // leaked RATE_LIMIT_LOGIN_EMAIL of "2" would silently lock Ada out of
        // every test that follows.
        unset(
            $_ENV['RATE_LIMIT_LOGIN_EMAIL'],
            $_ENV['RATE_LIMIT_LOGIN_IP'],
            $_ENV['RATE_LIMIT_WINDOW'],
            $_ENV['RATE_LIMIT_REGISTER'],
            $_ENV['RATE_LIMIT_REGISTER_WINDOW'],
            $_ENV['REGISTRATION'],
        );
    }

    /**
     * @return array{0: Application, 1: Csrf}
     */
    private function app(): array
    {
        // Booted exactly as the front controller does, so the test exercises the
        // real wiring.
        $app = App::boot();

        return [$app, $app->csrf()];
    }

    private function get(Application $app, string $path, array $headers = [])
    {
        return $app->handle(new Request('GET', $path, headers: $headers));
    }

    /**
     * Post to a component action, the way htmx does.
     *
     * @param array<string, mixed> $body
     */
    private function submit(Application $app, string $html, string $action, array $body, string $verb = 'POST')
    {
        return $app->handle(new Request(
            $verb,
            $this->actionUrl($html, $action, $verb),
            body: $body,
            headers: ['HX-Request' => 'true', 'X-CSRF-Token' => $app->csrf()?->token() ?? ''],
        ));
    }

    /**
     * The signed URL for one component action, read out of the rendered markup.
     */
    private function actionUrl(string $html, string $action, string $verb = 'post'): string
    {
        // The markup says hx-post; the verb may arrive here as POST.
        preg_match_all(sprintf('/hx-%s="([^"]+)"/', strtolower($verb)), $html, $matches);

        foreach ($matches[1] ?? [] as $url) {
            $decoded = html_entity_decode($url);

            if (str_ends_with($decoded, '/' . $action)) {
                return $decoded;
            }
        }

        $this->fail(sprintf('No hx-%s URL ending in "/%s" was rendered.', strtolower($verb), $action));
    }

    private function uniqueEmail(): string
    {
        return sprintf('user-%s@example.com', bin2hex(random_bytes(6)));
    }

    // ---------------------------------------------------------------- pages

    public function testTheHomePageRendersForEveryone(): void
    {
        [$app] = $this->app();

        $body = $this->get($app, '/')->body();

        $this->assertStringContainsString('Empira', $body);
        // A guest is offered both ways in, and no account panel yet.
        $this->assertStringContainsString('href="/login"', $body);
        $this->assertStringContainsString('href="/register"', $body);
        $this->assertStringNotContainsString('Your account', $body);
    }

    public function testThePlaceholderSecretIsFineWhenNothingSaysOtherwise(): void
    {
        // Pinned because it is a decision, not an accident: an unset APP_ENV
        // counts as local. Someone tidying this up into "unset means not local"
        // would break every developer's first run — and would be trading one
        // silent failure for a different one.
        unset($_ENV['APP_ENV'], $_ENV['APP_SECRET']);

        $this->assertNotNull(App::boot(csrf: false));
    }

    public function testThePlaceholderSecretRefusesToStartOutsideLocal(): void
    {
        unset($_ENV['APP_SECRET']);
        $_ENV['APP_ENV'] = 'production';

        try {
            // A component token is base64url(payload).base64url(hmac) and the
            // payload carries the component class and its props, so the
            // placeholder in the open means anyone can mint a signed URL for
            // any component with any props. Nothing would have logged this, and
            // the forged URL would simply have worked.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Refusing to start with the placeholder APP_SECRET');

            App::boot(csrf: false);
        } finally {
            unset($_ENV['APP_ENV']);
        }
    }

    public function testARealSecretIsAcceptedOutsideLocal(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_SECRET'] = bin2hex(random_bytes(32));

        try {
            $this->assertNotNull(App::boot(csrf: false));
        } finally {
            unset($_ENV['APP_ENV'], $_ENV['APP_SECRET']);
        }
    }

    public function testTheApplicationAndTheConsoleAgreeOnWhichDatabaseThisIs(): void
    {
        // The bug this pins: `App::boot()` defaulted to storage/app.sqlite while
        // `empira migrate` defaulted to `:memory:`, so `composer setup`
        // migrated a database that vanished on exit and then seeded a real one
        // with no `users` table in it.
        //
        // Booting publishes the application's defaults into `$_ENV`; this asks
        // the framework's own reader — the one the console uses — what it would
        // connect to, and requires the same answer the runtime uses.
        $configured = $_ENV['DB_DATABASE'];

        App::boot();

        $fromConsoleReader = DatabaseConfig::fromEnvironment()->string('database');

        self::assertSame($configured, $fromConsoleReader);

        // And it is a file that exists, not `:memory:` — which would vanish the
        // moment the process ended and report success on the way out.
        self::assertFileExists($fromConsoleReader);
    }

    public function testTheHealthEndpointStillAnswersJson(): void
    {
        [$app] = $this->app();

        $this->assertSame('{"ok":true}', $this->get($app, '/healthz')->body());
    }

    public function testAGenuineBugIsRecordedOnStderrBeforeItIsRethrown(): void
    {
        // What a broken component does. It is re-thrown by design, so this is
        // the path a worker has to report on; the question is whether anything
        // was written down first, and before this there was not.
        $stderr = $this->captureLog(static function (Application $app): void {
            $app->fallback(static function (): string {
                throw new \LogicException('a deliberate bug');
            });

            try {
                $app->handle(new Request('GET', '/boom'));
            } catch (\LogicException) {
                // expected — the framework re-throws a bug on purpose
            }
        });

        $this->assertNotEmpty($stderr, 'nothing was written to the log');

        $record = json_decode(trim($stderr), true);

        // Asserted on the decoded record rather than the raw line, so this
        // fails if the format drifts instead of quietly matching a substring.
        $this->assertSame('ERROR', $record['level_name']);
        $this->assertSame('a deliberate bug', $record['message']);
        $this->assertSame('LogicException', $record['context']['exception']['class']);
        $this->assertSame('GET', $record['context']['method']);
        $this->assertSame('/boom', $record['context']['path']);
    }

    public function testAnOrdinaryRequestLogsNothing(): void
    {
        // A log line per request would bury the failures worth reading.
        $stderr = $this->captureLog(static function (Application $app): void {
            $app->handle(new Request('GET', '/'));
        });

        $this->assertSame('', trim($stderr));
    }

    /**
     * Boot the application against a log file the test can read, and hand back
     * what landed in it.
     *
     * `LOG_STREAM` has to be set *before* `App::boot()`, because that is when
     * the handler is built — the level and the stream are read once, at boot,
     * and a logger cannot be re-pointed afterwards.
     *
     * @param callable(Application): void $then
     */
    private function captureLog(callable $then): string
    {
        $path = dirname(__DIR__, 2) . '/tmp/app-test-log.json';
        $previous = $_ENV['LOG_STREAM'] ?? null;
        $_ENV['LOG_STREAM'] = $path;

        try {
            $then(App::boot());

            // Monolog opens the stream lazily, so a file that was never
            // created is the strongest possible statement that nothing was
            // logged — there was no line to read, not merely an empty one.
            return is_file($path) ? (string) file_get_contents($path) : '';
        } finally {
            $_ENV['LOG_STREAM'] = $previous;
            @unlink($path);
        }
    }

    public function testUnknownRoutes404(): void
    {
        [$app] = $this->app();

        $this->assertSame(404, $this->get($app, '/nope')->status());
    }

    public function testTheNavBarOffersBothWaysInToAGuest(): void
    {
        [$app] = $this->app();

        $body = $this->get($app, '/')->body();

        $this->assertStringContainsString('Sign in', $body);
        $this->assertStringContainsString('Create account', $body);
    }

    // ---------------------------------------------------------------- gates

    public function testADashboardSendsAGuestToSignIn(): void
    {
        [$app] = $this->app();

        $response = $this->get($app, '/dashboard');

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->headers()['Location']);
        // Both headers, so a boosted htmx link navigates too.
        $this->assertSame('/login', $response->headers()['HX-Redirect']);
    }

    /**
     * The gate is about who you are, not which verb you used.
     */
    public function testTheDashboardGateCoversEveryVerb(): void
    {
        [$app] = $this->app();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $response = $app->handle(new Request($verb, '/dashboard'));

            $this->assertSame(302, $response->status(), $verb . ' should be redirected, not served');
            $this->assertSame('/login', $response->headers()['HX-Redirect'], $verb);
        }
    }

    public function testASignedInVisitorCannotReachTheLoginOrRegisterPages(): void
    {
        [$app] = $this->app();

        $this->signIn($app);

        foreach (['/login', '/register'] as $path) {
            foreach (['GET', 'POST', 'PUT'] as $verb) {
                $response = $app->handle(new Request($verb, $path));

                $this->assertSame(302, $response->status(), $verb . ' ' . $path);
                $this->assertSame('/dashboard', $response->headers()['HX-Redirect'], $verb . ' ' . $path);
            }
        }
    }

    // ---------------------------------------------------------------- register

    public function testTheRegisterFormRendersForAGuest(): void
    {
        [$app] = $this->app();

        $body = $this->get($app, '/register')->body();

        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="password_confirmation"', $body);
    }

    // ------------------------------------------------ closing registration

    public function testRegistrationIsOpenUnlessSomethingSaysOtherwise(): void
    {
        unset($_ENV['REGISTRATION']);

        [$app] = $this->app();

        self::assertTrue(Registration::isOpen());
        self::assertSame(200, $this->get($app, '/register')->status());
        self::assertStringContainsString('Create account', $this->get($app, '/')->body());
        self::assertStringContainsString('Create an account', $this->get($app, '/login')->body());
    }

    public function testClosedRegistrationIsARouteThatDoesNotExist(): void
    {
        $_ENV['REGISTRATION'] = 'closed';

        [$app] = $this->app();

        // 404 rather than "sign-ups are closed". The second is kinder to the
        // one person who trips over it and tells everyone else there is an
        // account system here worth trying again.
        self::assertSame(404, $this->get($app, '/register')->status());
    }

    public function testClosedRegistrationTakesItsLinksWithIt(): void
    {
        $_ENV['REGISTRATION'] = 'closed';

        [$app] = $this->app();

        // A navigation bar is where every visitor is guaranteed to look. Leaving
        // the link and 404-ing its destination advertises a route that does not
        // exist.
        self::assertStringNotContainsString('Create account', $this->get($app, '/')->body());
        self::assertStringNotContainsString('Create an account', $this->get($app, '/login')->body());

        // And the sign-in link stays — it is still how anybody gets in.
        self::assertStringContainsString('/login', $this->get($app, '/')->body());
    }

    public function testAFormHeldOpenBeforeRegistrationClosedStillCannotPost(): void
    {
        // The trap. The action posts to its own signed component URL, which the
        // page route never sees — so closing the page while leaving the endpoint
        // open would leave anybody who had the form open able to sign up.
        [$app] = $this->app();

        // Captured while registration is still open, which is the whole scenario.
        $html = $this->get($app, '/register')->body();
        $email = $this->uniqueEmail();

        $_ENV['REGISTRATION'] = 'closed';

        [$app] = $this->app();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Late Arrival',
            'email' => $email,
            'password' => 'a real password',
            'password_confirmation' => 'a real password',
        ]);

        self::assertSame(0, $this->countUsers($app, $email), 'the account was created anyway');
        self::assertStringNotContainsString('Dashboard', $response->headers()['HX-Redirect'] ?? '');
    }

    public function testRegistrationIsRateLimitedPerAddress(): void
    {
        $_ENV['RATE_LIMIT_REGISTER'] = '2';
        $_ENV['RATE_LIMIT_REGISTER_WINDOW'] = '3600';

        [$app] = $this->app();

        for ($i = 0; $i < 2; $i++) {
            // Registration signs the user in, and a signed-in visitor is
            // redirected away from the form — so the session is dropped between
            // attempts or the second one has no form to submit.
            $_SESSION = [];

            $this->submit($app, $this->get($app, '/register')->body(), 'register', [
                'name' => 'Filler',
                'email' => $this->uniqueEmail(),
                'password' => 'a real password',
                'password_confirmation' => 'a real password',
            ]);
        }

        self::assertTrue(
            $this->rateLimiter($app)->isBlocked(RateLimiter::key('register:ip', 'unknown'), 2, 3600),
            'two registrations should have spent the allowance'
        );
    }

    public function testAFormThatFailedItsOwnRulesIsNotCountedAsAnAttempt(): void
    {
        // A validation failure is not somebody creating accounts; counting it
        // would make one typo look like an attack and lock a real person out.
        [$app] = $this->app();

        // Cleared first: this suite shares one database and its "unknown"
        // address key, so anything an earlier test recorded is still on it.
        $this->rateLimiter($app)->clear(RateLimiter::key('register:ip', 'unknown'));

        for ($i = 0; $i < 3; $i++) {
            $this->submit($app, $this->get($app, '/register')->body(), 'register', [
                'name' => 'x',
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'different',
            ]);
        }

        self::assertFalse(
            $this->rateLimiter($app)->isBlocked(RateLimiter::key('register:ip', 'unknown'), 3, 3600),
            'invalid submissions were counted against the registration limit'
        );
    }

    public function testRegisteringCreatesAnAccountAndSignsIn(): void
    {
        [$app] = $this->app();
        $email = $this->uniqueEmail();

        $html = $this->get($app, '/register')->body();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Grace Hopper',
            'email' => $email,
            'password' => 'correct horse',
            'password_confirmation' => 'correct horse',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertSame('/dashboard', $response->headers()['HX-Redirect'] ?? null);

        $user = $this->findUser($app, $email);
        $this->assertNotNull($user, 'the account was written');
        $this->assertSame('Grace Hopper', $user->name());

        // …and the new account is immediately the signed-in one.
        $this->assertStringContainsString('Grace Hopper', $this->get($app, '/dashboard')->body());
    }

    public function testRegisteringWithABadEmailReRendersTheFormWithTheReason(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/register')->body();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Someone',
            'email' => 'not-an-email',
            'password' => 'correct horse',
            'password_confirmation' => 'correct horse',
        ]);

        // 200, not 422: the visitor keeps the form and is told what was wrong.
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('valid email', $response->body());
        // The field they typed is kept, so it need not be retyped.
        $this->assertStringContainsString('not-an-email', $response->body());
    }

    public function testAMismatchedConfirmationIsReported(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/register')->body();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Someone',
            'email' => $this->uniqueEmail(),
            'password' => 'correct horse',
            'password_confirmation' => 'battery staple',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('confirmation does not match', $response->body());
    }

    public function testAShortPasswordIsReported(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/register')->body();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Someone',
            'email' => $this->uniqueEmail(),
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $this->assertStringContainsString('at least 8', $response->body());
    }

    public function testRegisteringAnEmailThatExistsSaysSoAndWritesNothing(): void
    {
        [$app] = $this->app();
        $email = $this->uniqueEmail();

        // Written through the repository rather than the form, because the form
        // signs the new user in — and /register turns a signed-in visitor away,
        // so there would be no form left to submit.
        $app->container()->get(UserRepository::class)->register('First', $email, 'correct horse');

        $before = $this->countUsers($app, $email);

        $html = $this->get($app, '/register')->body();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Impostor',
            'email' => $email,
            'password' => 'correct horse',
            'password_confirmation' => 'correct horse',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('already registered', $response->body());
        $this->assertSame($before, $this->countUsers($app, $email), 'no second row was written');
    }

    // ---------------------------------------------------------------- login

    public function testSigningInWithTheWrongPasswordShowsTheFormAgain(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/login')->body();

        $response = $this->submit($app, $html, 'login', [
            'email' => 'ada@example.com',
            'password' => 'wrong',
        ]);

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('did not match', $response->body());
        $this->assertNull($response->headers()['HX-Redirect'] ?? null);
    }

    public function testAnUnknownEmailAndAWrongPasswordSayTheSameThing(): void
    {
        [$app] = $this->app();

        $unknown = $this->submit(
            $app,
            $this->get($app, '/login')->body(),
            'login',
            ['email' => $this->uniqueEmail(), 'password' => 'whatever']
        )->body();

        $wrong = $this->submit(
            $app,
            $this->get($app, '/login')->body(),
            'login',
            ['email' => 'ada@example.com', 'password' => 'wrong']
        )->body();

        // If these differ, the form becomes an oracle for which emails exist.
        $this->assertSame(
            $this->messageIn($unknown),
            $this->messageIn($wrong)
        );
    }

    public function testThePasswordIsNeverEchoedBack(): void
    {
        [$app] = $this->app();

        $response = $this->submit(
            $app,
            $this->get($app, '/login')->body(),
            'login',
            ['email' => 'ada@example.com', 'password' => 'hunter2-wrong']
        );

        $this->assertStringNotContainsString('hunter2-wrong', $response->body());
    }

    // -------------------------------------------------- what the log records

    public function testTheLogSaysWhyASignInFailedWhenTheVisitorCannotBeTold(): void
    {
        // One message for the visitor, three distinguishable reasons for us. This
        // is the only way to answer "was this one person struggling, or many?"
        // — which a log recording merely "did not match" cannot.
        $unknown = $this->captureLog(function (Application $app): void {
            $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => $this->uniqueEmail(),
                'password' => 'whatever',
            ]);
        });

        self::assertStringContainsString('login failed: no such user', $unknown);
        self::assertStringContainsString('"level_name":"WARNING"', $unknown);

        // The password is never logged, on either branch.
        $wrong = $this->captureLog(function (Application $app): void {
            $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => 'ada@example.com',
                'password' => 'hunter2-wrong',
            ]);
        });

        self::assertStringContainsString('login failed: password did not match', $wrong);
        self::assertStringNotContainsString('hunter2-wrong', $wrong);
    }

    // ------------------------------------------------------ being shut out

    public function testTooManyFailuresShutTheAccountOutAndThenLetItExpire(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_EMAIL'] = '3';
        $_ENV['RATE_LIMIT_WINDOW'] = '900';

        [$app] = $this->app();
        $email = 'ada@example.com';

        for ($i = 0; $i < 3; $i++) {
            $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => $email,
                'password' => 'wrong',
            ]);
        }

        // The fourth is refused *before the password is checked*, so it is refused
        // whatever the password was.
        //
        // Asserted on the log rather than on the response, because the response
        // is the same one message either way — a test that watched the body
        // would pass with the limiter switched off entirely, which is precisely
        // the test that was supposed to prove it works.
        $blocked = $this->captureLog(function (Application $app) use ($email): void {
            $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => $email,
                'password' => 'correct horse battery staple',
            ]);
        });

        self::assertStringContainsString('login blocked: rate limited', $blocked);
        self::assertStringNotContainsString('password did not match', $blocked);

        // …and the window really reopens, rather than staying blocked until
        // something resets it. Forced by leaving a row whose window closed in
        // the past, because waiting fifteen minutes is not a test.
        //
        // Cleared first, then recorded with an old timestamp: `record()` resets
        // only when the timestamp *it* is given is past the window, so recording
        // in the past on top of a live row would increment it rather than age
        // it.
        $key = RateLimiter::key('login:email', $email);
        $limiter = $this->rateLimiter($app);
        $limiter->clear($key);
        $limiter->record($key, 900, time() - 901);

        $this->signIn($app);

        self::assertStringContainsString(
            'Ada Lovelace',
            $this->get($app, '/dashboard')->body(),
            'the lockout should have expired'
        );
    }

    public function testASuccessfulSignInForgetsTheFailures(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_EMAIL'] = '3';

        [$app] = $this->app();
        $email = 'ada@example.com';
        $limiter = $this->rateLimiter($app);

        for ($i = 0; $i < 2; $i++) {
            $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => $email,
                'password' => 'wrong',
            ]);
        }

        $this->signIn($app);

        // Without this the limiter punishes correct passwords: three honest
        // sign-ins in a quarter hour would lock somebody out of their own
        // account, and the only way to discover that would be to be that person.
        self::assertFalse($limiter->isBlocked(RateLimiter::key('login:email', $email), 3, 900));

        unset($_ENV['RATE_LIMIT_LOGIN_EMAIL']);
    }

    public function testAShutOutSwapReRendersTheFormRatherThanAnsweringWithABareStatus(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_EMAIL'] = '1';

        [$app] = $this->app();

        $this->submit($app, $this->get($app, '/login')->body(), 'login', [
            'email' => 'ada@example.com',
            'password' => 'wrong',
        ]);

        // htmx: the swap target is the form, so a bare status body would land in
        // the middle of somebody's page.
        //
        // The log is asserted alongside the body, because the body alone is
        // identical whether the request was refused or merely got the password
        // wrong — and a swap test that watched only the body would pass with the
        // limiter disabled.
        [$response, $logged] = $this->captureLogPair(function (Application $app): \Empira\Http\Response {
            return $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => 'ada@example.com',
                'password' => 'anything',
            ]);
        });

        self::assertStringContainsString('login blocked: rate limited', $logged);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('class="card auth-form"', $response->body());
        self::assertSame(
            'Those credentials did not match any account.',
            $this->messageIn($response->body()),
            'a refused swap must not say "too many attempts"'
        );
    }

    /**
     * Run something and hand back both what it returned and what it logged.
     *
     * Two things at once, because most of what matters here is the difference
     * between them: a response that says the right thing while the log says
     * nothing, and a log that says the right thing while the response does not.
     *
     * @template T
     *
     * @param callable(Application): T $then
     *
     * @return array{T, string}
     */
    private function captureLogPair(callable $then): array
    {
        $result = null;
        $logged = $this->captureLog(static function (Application $app) use ($then, &$result): void {
            $result = $then($app);
        });

        return [$result, $logged];
    }

    public function testAShutOutPlainRequestAnswers429WithRetryAfter(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_EMAIL'] = '1';

        [$app] = $this->app();
        $this->rateLimiter($app)->record(RateLimiter::key('login:email', 'ada@example.com'), 900);

        // Not a swap: a scripted POST or a link, which gets the protocol's answer
        // rather than a fragment of somebody's form.
        $response = $app->handle(new Request(
            'POST',
            $this->actionUrl($this->get($app, '/login')->body(), 'login', 'post'),
            body: ['email' => 'ada@example.com', 'password' => 'correct horse battery staple'],
            headers: ['X-CSRF-Token' => $app->csrf()?->token() ?? ''],
        ));

        self::assertSame(429, $response->status());
        self::assertSame('900', $response->headers()['Retry-After'] ?? null);

        unset($_ENV['RATE_LIMIT_LOGIN_EMAIL']);
    }

    private function rateLimiter(Application $app): RateLimiter
    {
        return $app->container()->get(RateLimiter::class);
    }

    // ---------------------------------------------------------------- seeding

    public function testSeedingPutsEveryDemoAccountIn(): void
    {
        [$app] = $this->app();

        foreach (['ada@example.com' => 'Ada Lovelace', 'test@example.com' => 'Test User'] as $email => $name) {
            $user = $this->findUser($app, $email);

            self::assertNotNull($user, sprintf('%s was not seeded', $email));
            self::assertSame($name, $user->name());
        }
    }

    public function testBothSeededAccountsCanActuallySignIn(): void
    {
        [$app] = $this->app();

        // Asserted on the dashboard rather than on the account existing, because
        // a seeded row that cannot be signed into is the failure that matters
        // and it is not visible from a fixture check.
        foreach (['ada@example.com' => 'correct horse battery staple', 'test@example.com' => 'test1234'] as $email => $password) {
            $_SESSION = [];

            $this->submit($app, $this->get($app, '/login')->body(), 'login', [
                'email' => $email,
                'password' => $password,
            ]);

            self::assertStringContainsString(
                'Your account',
                $this->get($app, '/dashboard')->body(),
                sprintf('%s could not sign in', $email)
            );
        }
    }

    public function testSeedingTwiceChangesNothingAndStillSucceeds(): void
    {
        // A deploy step is allowed to be run twice: a retried pipeline must not
        // fail on a seed that already happened.
        [$app] = $this->app();
        $before = $this->findUser($app, 'ada@example.com')?->id();

        $result = self::runConsole(['seed']);

        self::assertSame(0, $result['code'], $result['output']);

        [$app] = $this->app();

        self::assertSame($before, $this->findUser($app, 'ada@example.com')?->id());
    }

    public function testSeedingOneNamedAccountNamesItCorrectly(): void
    {
        $email = $this->uniqueEmail();

        $result = self::runConsole([
            'seed',
            '--email=' . $email,
            '--name=Grace Hopper',
            '--password=hunter2please',
        ]);

        self::assertSame(0, $result['code'], $result['output']);

        [$app] = $this->app();
        $user = $this->findUser($app, $email);

        self::assertNotNull($user);

        // `--name` exists because the name used to be hardcoded, so anything you
        // seeded came out called Ada Lovelace.
        self::assertSame('Grace Hopper', $user->name());
    }

    public function testSigningInWithTheRightCredentialsRedirectsToTheDashboard(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/login')->body();

        $response = $this->submit($app, $html, 'login', [
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $this->assertSame('/dashboard', $response->headers()['HX-Redirect'] ?? null);
        $this->assertStringContainsString('Ada Lovelace', $this->get($app, '/dashboard')->body());
    }

    public function testTheNavBarShowsTheSignedInUser(): void
    {
        [$app] = $this->app();

        $this->signIn($app);

        $body = $this->get($app, '/')->body();

        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringContainsString('Sign out', $body);
        $this->assertStringNotContainsString('Create account', $body);
    }

    // ---------------------------------------------------------------- logout

    public function testSigningOutReturnsToTheFrontPage(): void
    {
        [$app] = $this->app();

        $this->signIn($app);

        $body = $this->get($app, '/dashboard')->body();

        $response = $this->submit($app, $body, 'logout', []);

        $this->assertSame('/', $response->headers()['HX-Redirect'] ?? null);

        // And the dashboard is a guest's again.
        $this->assertSame(302, $this->get($app, '/dashboard')->status());
    }

    // ------------------------------------------------- gates the route misses

    public function testASignedInUserPostingTheLoginActionIsTurnedAway(): void
    {
        [$app] = $this->app();
        $email = $this->uniqueEmail();

        // Through the repository, because the register form would sign this user
        // in — and then the guest fetch below would be turned away.
        $app->container()->get(UserRepository::class)->register('Someone Else', $email, 'correct horse');

        // This is the URL a page rendered *before* signing in would still hold.
        // No route middleware ever sees it, so the action itself has to check —
        // which is why the form is fetched while still a guest.
        $html = $this->get($app, '/login')->body();

        $this->signIn($app, 'ada@example.com');

        $response = $this->submit($app, $html, 'login', [
            'email' => $email,
            'password' => 'correct horse',
        ]);

        $this->assertSame('/dashboard', $response->headers()['HX-Redirect'] ?? null);

        // Still Ada: the session was not quietly switched to the other account.
        $this->assertStringContainsString('Ada Lovelace', $this->get($app, '/dashboard')->body());
    }

    public function testASignedInUserPostingTheRegisterActionWritesNothing(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/register')->body();

        $this->signIn($app);

        $email = $this->uniqueEmail();

        $response = $this->submit($app, $html, 'register', [
            'name' => 'Impostor',
            'email' => $email,
            'password' => 'correct horse',
            'password_confirmation' => 'correct horse',
        ]);

        $this->assertSame('/dashboard', $response->headers()['HX-Redirect'] ?? null);
        $this->assertSame(0, $this->countUsers($app, $email), 'no account was created');
    }

    // ---------------------------------------------------------------- styling

    public function testAComponentBringsItsOwnStylesheet(): void
    {
        [$app] = $this->app();

        $body = $this->get($app, '/login')->body();

        // The global sheet is in <head>; the form's is inside the form, which is
        // the swap target, so it comes back when htmx re-renders the form alone.
        $this->assertStringContainsString('--surface:', $body);
        $this->assertStringContainsString('.auth-form .field', $body);
    }

    public function testTheGlobalSheetIsInTheHeadAndTheComponentSheetIsNot(): void
    {
        [$app] = $this->app();

        $html = $this->get($app, '/login')->body();

        [$head, $body] = explode('</head>', $html, 2) + ['', ''];

        $this->assertStringContainsString('--surface:', $head, 'global rules belong in <head>');
        $this->assertStringNotContainsString('.auth-form .field', $head);
        $this->assertStringContainsString('.auth-form .field', $body, "a component's own rules ride with it");
    }

    public function testEveryHtmxAttributeIsGenerated(): void
    {
        [$app] = $this->app();

        // A guest's front page is plain links, so the page that *does* issue
        // requests is the sign-in form.
        $html = $this->get($app, '/login')->body();

        // No hx-post may be hand-written; they all come from ->action().
        preg_match_all('/(hx-[a-z-]+)=/', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? [], 'the page should issue some htmx requests');

        foreach ($matches[1] as $attribute) {
            $this->assertStringContainsString('/empira/', $html, $attribute . ' should point at a signed endpoint');
        }
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(Application $app, string $email = 'ada@example.com'): void
    {
        $html = $this->get($app, '/login')->body();

        $this->submit($app, $html, 'login', [
            'email' => $email,
            'password' => 'correct horse battery staple',
        ]);
    }

    /**
     * A user straight from the ORM, bypassing the request that made it.
     *
     * The EntityManager is cleared at the start of every request, so reading
     * through the application's own manager after a response would test the
     * identity map rather than the database. A fresh manager per lookup reads
     * what is actually there.
     */
    private function findUser(Application $app, string $email): ?User
    {
        return $app->container()->get(UserRepository::class)->byEmail($email);
    }

    private function countUsers(Application $app, string $email): int
    {
        return $this->findUser($app, $email) === null ? 0 : 1;
    }

    /**
     * The error paragraph, so two responses can be compared on what they say.
     */
    private function messageIn(string $html): string
    {
        preg_match('/class="form-error"[^>]*>([^<]*)</', $html, $matches);

        return trim($matches[1] ?? '');
    }
}