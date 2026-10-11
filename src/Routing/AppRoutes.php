<?php

declare(strict_types=1);

namespace App\Routing;

use IamAdty\Empira\Http\Response;
use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\RouteRegistrar;

/**
 * The routes that are not pages.
 *
 * Discovery covers the pages, because a page and its URL belong together in one
 * file. A health check is the opposite: it is not a page, it is an endpoint
 * somebody else calls, and it would be noise to give it a `Layout`. So it is
 * registered here, deliberately and in order.
 *
 * This is the same mechanism an application uses for grouped routes, shared
 * middleware, or a route table imported from configuration — see
 * {@see RouteRegistrar}.
 */
final class AppRoutes implements RouteRegistrar
{
    public function register(RouteCollection $routes): void
    {
        $routes->get(
            '/healthz',
            static fn(): Response => Response::json(['ok' => true]),
            'health'
        );
    }
}