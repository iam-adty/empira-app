<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Registration;
use Closure;
use IamAdty\Empira\Http\Middleware;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Http\Response;

/**
 * Make `/register` a route that does not exist, when registration is closed.
 *
 * 404 rather than a "sorry, sign-ups are closed" page. The second is kinder to
 * the one person who trips over it and tells everyone else that there is an
 * account system here worth trying again; the first is indistinguishable from a
 * route that never existed, which is the only answer that does not describe the
 * application to somebody probing it.
 *
 * Attached to the page route, and deliberately not sufficient on its own — see
 * {@see \App\Components\RegisterForm}: the form posts to its own signed
 * component URL, which no route middleware ever sees, so the action checks for
 * itself.
 */
final class RegistrationOpen implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Registration::isOpen()) {
            return $next($request);
        }

        return Response::html(Registration::notFoundMessage(), 404);
    }
}