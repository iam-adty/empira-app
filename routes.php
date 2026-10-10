<?php

declare(strict_types=1);

use App\Layout;
use IamAdty\Empira\Application;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Routing\RouteCollection;

/**
 * Every route the application answers.
 *
 * Returning a closure that receives the RouteCollection keeps this file free of
 * any framework bootstrap: it only describes paths, and the caller decides when
 * to build the application around it.
 *
 * The handler asks for `Application` by type and the container supplies it, so
 * a route reads as a description of the page rather than as wiring.
 */
return static function (RouteCollection $routes): void {
    $routes->get(
        '/',
        static fn(Request $request, Application $app) => $app->view(Layout::class, [
            'q' => is_string($request->query['q'] ?? null) ? $request->query['q'] : '',
        ]),
        'home'
    );
};