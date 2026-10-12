<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use Empira\Auth\Auth;
use Empira\Http\Middleware;
use Empira\Http\Request;
use Empira\Http\Response;
use Empira\Http\Session;

/**
 * Send anyone who is not signed in to the sign-in page.
 *
 * This **returns** a response rather than throwing, because a redirect is not
 * an error: `RequireAuth` throws and becomes a 401 for an API, while a page
 * wants a browser to end up somewhere useful.
 *
 * Both headers are set. `Location` moves an ordinary navigation; `HX-Redirect`
 * moves a boosted htmx one, which does not follow a 302 on its own.
 */
final class RedirectGuests implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        $this->session->flash('notice', 'Sign in to see the dashboard.');

        return Response::redirectTo('/login')->redirect('/login');
    }
}
