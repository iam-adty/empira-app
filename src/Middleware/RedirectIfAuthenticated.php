<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Http\Middleware;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;

/**
 * Send anyone who *is* signed in away from the sign-in and sign-up pages.
 *
 * The counterpart to {@see RedirectGuests}, and the reason a signed-in visitor
 * does not meet a login form they have no use for.
 *
 * Note what this deliberately does **not** do: it is attached to routes
 * declared with `methods: '*'`, so it covers every verb, but the forms post to
 * their own signed component URL rather than to `/login`. Those actions carry
 * their own check, because no route middleware ever sees them.
 */
final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->guest()) {
            return $next($request);
        }

        return Response::redirectTo('/dashboard')->redirect('/dashboard');
    }
}
