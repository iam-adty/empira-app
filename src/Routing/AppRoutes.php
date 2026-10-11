<?php

declare(strict_types=1);

namespace App\Routing;

use App\Pages\AdminLayout;
use IamAdty\Empira\Auth\Middleware\RequireAuth;
use IamAdty\Empira\Routing\RouteCollection;
use IamAdty\Empira\Routing\RouteRegistrar;

/**
 * The routes that are not pages.
 *
 * {@see \App\Pages\HomeLayout} declares its own URL, because a page and the
 * thing that draws it belong in one file. This is the other half: endpoints
 * that are not pages and would be a lie to give a layout.
 *
 * `/admin` sits behind {@see RequireAuth}. It answers a 401 without a signed-in
 * user and a rendered page with one — which is the whole demonstration, and
 * which {@see \App\Auth\DemoGuard} makes possible without inventing a login.
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

        $routes->group('/admin', static function (RouteCollection $routes): void {
            // A Layout class as the handler is the framework's shorthand for
            // "this URL renders this page", so there is no view() call to make.
            $routes->get('/stats', AdminLayout::class, 'admin.stats');
        }, middleware: [RequireAuth::class]);
    }
}
