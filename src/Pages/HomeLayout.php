<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\Counter;
use App\Components\SearchBox;
use App\Components\TodoList;
use App\Css;
use IamAdty\Empira\Attributes\Route;
use IamAdty\Empira\Http\Request;
use IamAdty\Empira\Raw;
use IamAdty\Empira\View\Layout as BaseLayout;
use IamAdty\Empira\View\Region;

/**
 * The demo's home page.
 *
 * The `#[Route]` on the class *is* the route — there is no route table to keep
 * in step with this file, and no separate handler to wire up. Adding a second
 * URL is one more attribute.
 *
 * Each card is a region with its own id, so any one of them can be swapped on
 * its own. A request carrying `HX-Request` gets just that region; a browser
 * gets the whole document.
 */
#[Route('/', name: 'home')]
final class HomeLayout extends BaseLayout
{
    /**
     * What the search box is currently filtering by.
     */
    private string $q = '';

    protected function title(): string
    {
        return 'Empira demo';
    }

    /**
     * Read whatever this page needs from the request.
     *
     * The arguments are resolved by type, so a page can ask for `Request`, for
     * `RouteParams`, or for one of the framework's services without this file
     * importing any wiring.
     */
    public function mount(Request $request): void
    {
        $this->q = is_string($request->query['q'] ?? null) ? $request->query['q'] : '';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            'counter' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append($region->hx()->h2('Counter'), $region->component(Counter::class)),

            'todos' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append($region->hx()->h2('Todos'), $region->component(TodoList::class)),

            'search' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append(
                    $region->hx()->h2('Search'),
                    $region->component(SearchBox::class, ['q' => $this->q])
                ),
        ];
    }

    protected function header(): \IamAdty\Empira\Element
    {
        return $this->hx->main()->append(
            $this->hx->h1('Empira'),
            $this->hx->p()->class('lede')->text('htmx lives in PHP classes. Not one hx-* attribute below is hand-written.'),
        );
    }

    protected function styles(): \IamAdty\Empira\Element|Raw
    {
        return Raw::of(Css::render());
    }
}