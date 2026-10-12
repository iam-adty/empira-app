<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\UserCard;
use App\Layouts\SiteLayout;
use App\Middleware\RedirectGuests;
use Empira\Attributes\Route;
use Empira\Element;
use Empira\Raw;
use Empira\View\Region;

/**
 * Somewhere that needs signing in.
 *
 * The page declares its own gate, the way it declares its own URL. `*` rather
 * than GET alone, because "this is for signed-in users" should not quietly
 * depend on which verb arrived.
 *
 * A guest is redirected rather than refused: a 401 is the right answer for an
 * API and a dead end for a person, so {@see RedirectGuests} returns a
 * navigation instead of throwing.
 */
#[Route('/dashboard', name: 'dashboard', methods: '*', middleware: [RedirectGuests::class])]
final class DashboardLayout extends SiteLayout
{
    protected function title(): string
    {
        return 'Dashboard — Empira demo';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            ...parent::regions(),
            'main' => fn(Region $region): mixed => $region
                ->component(UserCard::class)
                ->append($this->hx->div()->class('actions')->append(
                    $this->hx->a()->attr('href', '/')->text('Back to the front page'),
                )),
        ];
    }
}
