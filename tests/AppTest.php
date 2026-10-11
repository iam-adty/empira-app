<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use IamAdty\Empira\Application;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Security\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Drives the application end to end, the same way htmx would.
 */
final class AppTest extends TestCase
{
    private array $session = [];

    protected function setUp(): void
    {
        $this->session = [];
        $_SESSION = [];
    }

    /**
     * @return array{0: Application, 1: Csrf}
     */
    private function app(): array
    {
        // Booted exactly as the front controller does, so the test exercises the
        // real wiring. `SessionState` falls back to an in-process store under
        // the CLI, which is what lets the stateful todo list survive the
        // separate requests a round trip makes.
        $app = App::boot();

        return [$app, $app->csrf()];
    }

    private function htmxPost(string $url, array $body = [], ?string $csrf = null): Request
    {
        $headers = ['HX-Request' => 'true', 'HX-Current-URL' => 'http://localhost/'];

        if ($csrf !== null) {
            $headers['X-CSRF-Token'] = $csrf;
        }

        return new Request('POST', $url, body: $body, headers: $headers);
    }

    private function actionUrls(string $html): array
    {
        preg_match_all('/hx-post="([^"]+)"/', $html, $matches);

        return array_map('html_entity_decode', $matches[1] ?? []);
    }

    // ---------------------------------------------------------------- page

    public function testTheHomePageRenders(): void
    {
        [$app] = $this->app();

        $response = $app->handle(new Request('GET', '/'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('<h1>Empira</h1>', $response->body());
        $this->assertStringContainsString('unpkg.com/htmx.org', $response->body());
        $this->assertStringContainsString('hx-headers', $response->body());
    }

    public function testTheHomePageContainsNoHandWrittenHtmxVerbs(): void
    {
        [$app] = $this->app();

        $body = $app->handle(new Request('GET', '/'))->body();

        // Every verb in the document must be on a component element, and every
        // one of them must point at a signed endpoint.
        preg_match_all('/hx-(get|post|put|patch|delete)="([^"]*)"/', $body, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);

        foreach ($matches as [, $verb, $url]) {
            $this->assertStringStartsWith('/empira/', $url, sprintf('hx-%s has an unwired URL', $verb));
        }
    }

    public function testThePageRouteComesFromAnAttributeOnTheLayout(): void
    {
        [$app] = $this->app();

        // The URL is declared on `HomeLayout`; there is no route table to keep
        // in step with the page.
        $this->assertTrue($app->routes()->has('home'));
        $this->assertSame(200, $app->handle(new Request('GET', '/'))->status());
    }

    public function testAPageReadsTheQueryStringThroughMount(): void
    {
        [$app] = $this->app();

        $body = $app->handle(new Request('GET', '/', query: ['q' => 'ember']))->body();

        // `mount()` pulled `q` off the request; the search box renders it.
        $this->assertStringContainsString('value="ember"', $body);
    }

    public function testRoutesThatAreNotPagesAreRegisteredExplicitly(): void
    {
        [$app] = $this->app();

        $response = $app->handle(new Request('GET', '/healthz'));

        $this->assertSame(200, $response->status());
        $this->assertSame('{"ok":true}', $response->body());
    }

    public function testTheApplicationRunsMigrationsAndSeedsAUser(): void
    {
        [$app] = $this->app();

        // Boot migrates and seeds, so the demo has somebody to sign in as
        // without a setup step a reader has to discover.
        $this->assertTrue($app->db()->table('users')->exists());
        $this->assertSame('Ada Lovelace', $app->db()->first('users')['name']);
    }

    public function testAnAdminRouteRefusesAnAnonymousVisitor(): void
    {
        [$app] = $this->app();

        // Behind RequireAuth, so the answer is the negotiated 401 rather than
        // the 404 an unregistered route would give.
        $this->assertSame(401, $app->handle(new Request('GET', '/admin/stats'))->status());
    }

    public function testAnAdminRouteRendersForAVerifiedVisitor(): void
    {
        [$app] = $this->app();

        $body = $app->handle(new Request('GET', '/admin/stats', query: ['as' => '1']))->body();

        // Assert on the identity itself, not on the word "admin", which the
        // page says either way.
        $this->assertStringContainsString('Signed in as Ada Lovelace', $body);
    }

    public function testAnUnknownUserIsStillRefused(): void
    {
        [$app] = $this->app();

        $this->assertSame(401, $app->handle(new Request('GET', '/admin/stats', query: ['as' => '999']))->status());
    }

    public function testTheAdminRouteNegotiatesJsonForAJsonClient(): void
    {
        [$app] = $this->app();

        $response = $app->handle(new Request('GET', '/admin/stats', headers: ['Accept' => 'application/json']));

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('"error"', $response->body());
    }

    public function testUnknownRoutes404(): void
    {
        [$app] = $this->app();

        $response = $app->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('Not Found', $response->body());
    }

    // ---------------------------------------------------------------- counter

    public function testCounterRoundTrip(): void
    {
        [$app, $csrf] = $this->app();
        $token = $csrf->token();

        $page = $app->handle(new Request('GET', '/'))->body();

        $increment = $this->findAction($page, 'increment');
        $fragment = $app->handle($this->htmxPost($increment, [], $token))->body();

        $this->assertMatchesRegularExpression('/<strong>\d+<\/strong>|>\s*5\s*</', $fragment);
        $this->assertStringNotContainsString('Counter', $fragment, 'a fragment must not include the page shell');

        $again = $this->findAction($fragment, 'increment');
        $fragment = $app->handle($this->htmxPost($again, [], $token))->body();

        $this->assertStringContainsString('10', $fragment);
    }

    // ---------------------------------------------------------------- todos

    public function testTodoListRoundTrip(): void
    {
        [$app, $csrf] = $this->app();
        $token = $csrf->token();

        $page = $app->handle(new Request('GET', '/'))->body();
        $add = $this->findAction($page, 'add');

        $fragment = $app->handle($this->htmxPost($add, ['title' => 'buy milk'], $token))->body();
        $this->assertStringContainsString('buy milk', $fragment);

        $fragment = $app->handle($this->htmxPost($add, ['title' => 'walk dog'], $token))->body();
        $this->assertStringContainsString('buy milk', $fragment);
        $this->assertStringContainsString('walk dog', $fragment);
    }

    public function testTodoListRejectsAnEmptyTitle(): void
    {
        [$app, $csrf] = $this->app();
        $token = $csrf->token();

        $page = $app->handle(new Request('GET', '/'))->body();
        $add = $this->findAction($page, 'add');

        $response = $app->handle($this->htmxPost($add, ['title' => '   '], $token));

        $this->assertStringContainsString('todo:rejected', $response->headers()['HX-Trigger-After-Swap']);
        $this->assertStringNotContainsString('<li>', $response->body());
    }

    public function testTodoTitlesAreEscaped(): void
    {
        [$app, $csrf] = $this->app();
        $token = $csrf->token();

        $page = $app->handle(new Request('GET', '/'))->body();
        $add = $this->findAction($page, 'add');

        $fragment = $app->handle($this->htmxPost($add, ['title' => '<script>alert(1)</script>'], $token))->body();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $fragment);
        $this->assertStringContainsString('&lt;script&gt;', $fragment);
    }

    public function testToggleAndRemove(): void
    {
        [$app, $csrf] = $this->app();
        $token = $csrf->token();

        $page = $app->handle(new Request('GET', '/'))->body();
        $fragment = $app->handle($this->htmxPost($this->findAction($page, 'add'), ['title' => 'task'], $token))->body();

        preg_match('/data-id="([a-f0-9]+)"/', $fragment, $matches);
        $id = $matches[1];

        $toggle = $this->findAction($fragment, 'toggle');
        $fragment = $app->handle($this->htmxPost($toggle, ['id' => $id], $token))->body();
        $this->assertStringContainsString('class="done"', $fragment);

        $remove = $this->findAction($fragment, 'remove');
        $fragment = $app->handle($this->htmxPost($remove, ['id' => $id], $token))->body();
        $this->assertStringNotContainsString('task', $fragment);
    }

    // ---------------------------------------------------------------- search

    public function testSearchUsesAGetAndReplacesTheUrl(): void
    {
        [$app, $csrf] = $this->app();

        $page = $app->handle(new Request('GET', '/'))->body();

        preg_match('/hx-get="([^"]+)"/', $page, $matches);
        $this->assertNotEmpty($matches, 'The search box should issue a GET.');

        $url = html_entity_decode($matches[1], ENT_QUOTES);
        $response = $app->handle(new Request(
            'GET',
            $url,
            query: ['q' => 'tail'],
            headers: ['HX-Request' => 'true', 'X-CSRF-Token' => $csrf->token()],
        ));

        // Assert a real result, not just the word "htmx", which also appears in
        // the `htmx-indicator` class name and made an earlier check pass
        // whatever the search actually returned.
        $this->assertStringContainsString('<li>multitail</li>', $response->body());
        $this->assertSame('/?q=tail', $response->headers()['HX-Replace-Url']);
    }

    public function testASearchWithNoMatchesRendersAnEmptyList(): void
    {
        [$app, $csrf] = $this->app();

        $page = $app->handle(new Request('GET', '/'))->body();
        preg_match('/hx-get="([^"]+)"/', $page, $matches);
        $url = html_entity_decode($matches[1], ENT_QUOTES);

        $response = $app->handle(new Request(
            'GET',
            $url,
            query: ['q' => 'nothing-matches-this'],
            headers: ['HX-Request' => 'true', 'X-CSRF-Token' => $csrf->token()],
        ));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('<ul id="results"></ul>', $response->body());
    }

    // ---------------------------------------------------------------- csrf

    public function testActionsRequireTheCsrfToken(): void
    {
        [$app, $csrf] = $this->app();

        $page = $app->handle(new Request('GET', '/'))->body();
        $increment = $this->findAction($page, 'increment');

        $response = $app->handle($this->htmxPost($increment));

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString('CSRF', $response->body());
    }

    public function testAForeignCsrfTokenIsRejected(): void
    {
        [$app, $csrf] = $this->app();
        $csrf->token();

        $page = $app->handle(new Request('GET', '/'))->body();
        $increment = $this->findAction($page, 'increment');

        $response = $app->handle($this->htmxPost($increment, [], 'not-the-token'));

        $this->assertSame(403, $response->status());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Find the action URL whose trailing path segment is `$action`.
     */
    private function findAction(string $html, string $action): string
    {
        foreach ($this->actionUrls($html) as $url) {
            if (str_ends_with($url, '/' . $action)) {
                return $url;
            }
        }

        $this->fail('No action URL ending in "/' . $action . '" was rendered.');
    }
}