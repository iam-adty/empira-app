<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\Widget;
use App\Layouts\SiteLayout;
use Empira\Attributes\Route;
use Empira\Element;
use Empira\Raw;
use Empira\View\Region;

/**
 * The front page.
 *
 * The `#[Route]` on the class *is* the route: no table to keep in step, and no
 * handler to wire up. It is open to everyone, which is the point of a front
 * page.
 */
#[Route('/', name: 'home')]
final class HomeLayout extends SiteLayout
{
    protected function title(): string
    {
        return 'Empira demo';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            ...parent::regions(),
            'main' => fn(Region $region): mixed => $region->component(Widget::class),
        ];
    }
}
