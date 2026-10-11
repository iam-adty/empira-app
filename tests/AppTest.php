<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Auth\UserRepository;
use IamAdty\Empira\Application;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Security\Csrf;
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
        self::$database = sys_get_temp_dir() . '/empira-app-test-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    }

    protected function tearDown(): void
    {
        unset($_ENV['DB_DRIVER'], $_ENV['DB_DATABASE']);
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

    public function testTheHealthEndpointStillAnswersJson(): void
    {
        [$app] = $this->app();

        $this->assertSame('{"ok":true}', $this->get($app, '/healthz')->body());
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

        $row = $app->db()->first('users', fn($q) => $q->where('email', $email));
        $this->assertNotNull($row, 'the account was written');
        $this->assertSame('Grace Hopper', $row['name']);

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

    private function countUsers(Application $app, string $email): int
    {
        return count($app->db()->select('users', fn($q) => $q->where('email', $email)));
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