<?php

declare(strict_types=1);

namespace App;

use IamAdty\Empira\Raw;
use IamAdty\Empira\View\Layout as BaseLayout;
use IamAdty\Empira\View\Region;

/**
 * The demo's page shell.
 *
 * Each card is a region with its own id, so any one of them can be swapped on
 * its own. A request carrying `HX-Request` gets just that region; a browser
 * gets the whole document.
 */
final class Layout extends BaseLayout
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
            'counter' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append($region->hx()->h2('Counter'), $region->component(Components\Counter::class)),

            'todos' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append($region->hx()->h2('Todos'), $region->component(Components\TodoList::class)),

            'search' => fn(Region $region): mixed => $region
                ->element('section')
                ->class('card')
                ->append(
                    $region->hx()->h2('Search'),
                    $region->component(Components\SearchBox::class, ['q' => (string) $region->data('q', '')])
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