<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;

/**
 * The navigation bar: links for a guest, identity and a way out for a user.
 *
 * Present on every page, which is why it is a component rather than something
 * each layout writes. `render()` reads `Auth` from the container, so the
 * decision about which state to show is made in one place instead of three.
 *
 * Note that {@see LogoutButton} is registered even though this class is the
 * only thing that renders it. `renderComponent()` builds it directly, but the
 * token its action URL carries names the class and is resolved through the
 * registry when the action fires — so an unregistered nested component answers
 * 404 the moment somebody presses its button.
 */
final class NavBar extends Component
{
    protected function styles(): Raw
    {
        return Raw::of('.nav-bar { margin: 0 auto 2rem; max-width: 42rem; }');
    }

    protected function render(): Element
    {
        $user = $this->container()->get(Auth::class)->user();

        $links = $user === null
            ? [
                $this->hx->a()->attr('href', '/login')->text('Sign in'),
                $this->hx->a()->attr('href', '/register')->text('Create account'),
            ]
            : [
                $this->hx->span()->class('nav-user')->text((string) $user->attribute('name', 'Signed in')),
                $this->hx->a()->attr('href', '/dashboard')->text('Dashboard'),
                $this->renderComponent(LogoutButton::class),
            ];

        return $this->hx->nav()
            ->id($this->id())
            ->class('nav-bar')
            ->attr('aria-label', 'Main')
            ->append($this->hx->div()->class('actions')->append(...$links));
    }
}
