<?php

declare(strict_types=1);

namespace App\Routing;

use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\RouteRegistrar;

/**
 * The routes that are not pages.
 *
 * Each page declares its own URL with `#[Route]`, because a page and the thing
 * that draws it belong in one file. This is the other half: the endpoints that
 * are not pages and would be a lie to give a layout.
 */
final class AppRoutes implements RouteRegistrar
{
    public function register(RouteCollection $routes): void
    {
        $routes->get(
            '/healthz',
            static fn(): \IamAdty\Empira\Http\Response => \IamAdty\Empira\Http\Response::json(['ok' => true]),
            'health'
        );
    }
}
