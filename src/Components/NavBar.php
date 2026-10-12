<?php

declare(strict_types=1);

namespace App\Components;

use App\Registration;
use Empira\Auth\Auth;
use Empira\Component;
use Empira\Element;
use Empira\Raw;

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
            ? array_values(array_filter([
                $this->hx->a()->attr('href', '/login')->text('Sign in'),

                // Hidden when registration is closed, because a navigation bar
                // is the one place every visitor is guaranteed to look. Leaving
                // the link and 404-ing its destination would be worse than
                // useless: it advertises a sign-up route that does not exist.
                Registration::isOpen()
                    ? $this->hx->a()->attr('href', '/register')->text('Create account')
                    : null,
            ]))
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
