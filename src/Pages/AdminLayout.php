<?php

declare(strict_types=1);

namespace App\Pages;

use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\View\Layout as BaseLayout;
use IamAdty\Empira\View\Region;

/**
 * The page behind {@see RequireAuth}.
 *
 * Reached only with a signed-in user, which is what the route's middleware
 * enforces — so `mount()` can ask who that is without checking anything itself.
 */
final class AdminLayout extends BaseLayout
{
    private string $greeting = 'Signed out';

    /**
     * The identity is asked for by type, so nothing here has to know which
     * guard found it.
     */
    public function mount(Auth $auth): void
    {
        $user = $auth->user();

        if ($user !== null) {
            $this->greeting = sprintf('Signed in as %s <%s>', $user->attribute('name'), $user->attribute('email'));
        }
    }

    protected function title(): string
    {
        return 'Admin — Empira demo';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            'main' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append(
                    $region->hx()->h2('Admin'),
                    $region->element('p')->text($this->greeting),
                    $region->element('p')->class('lede')
                        ->text('Reached through RequireAuth: without a user this route answers 401.'),
                ),
        ];
    }
}
