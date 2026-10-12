<?php

declare(strict_types=1);

namespace App\Pages;

use App\Layouts\SiteLayout;
use Empira\Attributes\Route;
use Empira\Element;
use Empira\Raw;
use Empira\View\Region;

/**
 * The front page, and the only page there is.
 *
 * The `#[Route]` on the class *is* the route: no table to keep in step, and no
 * handler to wire up. `App::boot()` discovers it by scanning `src/`, so a page
 * appears by existing.
 *
 * This is the whole of the demo — a page, a region, and some text. Everything
 * interesting in a real application happens to be *added* to this, not
 * configured here: a form component, a route under
 * `#[Route('/admin', middleware: [RequireAuth::class])]`, a module. A skeleton
 * that arrives with everything already wired is not a starting point, it is
 * something to delete.
 */
#[Route('/', name: 'home')]
final class HomeLayout extends SiteLayout
{
    protected function title(): string
    {
        return 'Empira';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            ...parent::regions(),
            'main' => fn(Region $region): mixed => $region->element('div')->append(
                $this->hx->p()->text('This page is a route declared with #[Route], drawn by a Layout, ' .
                    'and served by a framework that never asked you to hand-write an hx-* attribute.'),
                $this->hx->p()->text('Add a component, a page, or a module under src/. It appears by existing.'),
            ),
        ];
    }
}