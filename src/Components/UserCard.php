<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;

/**
 * Who is signed in.
 *
 * Reads `Auth` rather than taking a user as a prop: identity is a property of
 * the request, not something a parent should have to look up and hand down.
 */
final class UserCard extends Component
{
    protected function styles(): Raw
    {
        return Raw::of('.user-card dl { display: grid; grid-template-columns: max-content 1fr; gap: .35rem 1rem; margin: 0; }
            .user-card dt { color: var(--muted); }
            .user-card dd { margin: 0; }');
    }

    protected function render(): Element
    {
        $user = $this->container()->get(Auth::class)->user();

        if ($user === null) {
            // The route behind this is gated, so reaching the empty state means
            // the gate is not where it should be. Say so rather than rendering
            // a blank card.
            return $this->hx->div()
                ->id($this->id())
                ->class('card user-card')
                ->append($this->hx->p()->text('Nobody is signed in.'));
        }

        return $this->hx->div()
            ->id($this->id())
            ->class('card user-card')
            ->append(
                $this->hx->h2('Your account'),
                $this->hx->dl()->append(
                    $this->hx->dt()->text('Name'),
                    $this->hx->dd()->text((string) $user->attribute('name', '')),
                    $this->hx->dt()->text('Email'),
                    $this->hx->dd()->text((string) $user->attribute('email', '')),
                    $this->hx->dt()->text('Id'),
                    $this->hx->dd()->text((string) $user->id()),
                ),
            );
    }
}
