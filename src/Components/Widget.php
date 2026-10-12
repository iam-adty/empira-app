<?php

declare(strict_types=1);

namespace App\Components;

use Empira\Component;
use Empira\Element;
use Empira\Raw;
use Empira\StyleOrder;

/**
 * An embeddable block with its own chrome.
 *
 * The thing a page cannot get for free from a region: a panel that brings its
 * own components, its own styling and nothing else, and can therefore be
 * dropped into any region of any page without that page knowing what is inside
 * it.
 *
 * A Component rather than a Layout on purpose. A Layout renders a whole
 * document, and a document inside a region is not a panel — a component nests
 * through `renderComponent()` and brings its stylesheet with it.
 */
final class Widget extends Component
{
    protected function aggregateStyles(): ?StyleOrder
    {
        // This component and the UserCard it wraps arrive and leave together, so
        // their rules belong in one sheet at the top rather than one per element
        // inside the other.
        return StyleOrder::SelfLast;
    }

    protected function styles(): Raw
    {
        return Raw::of('.widget { border: 1px solid var(--line); border-radius: .6rem; background: var(--surface); padding: 1.25rem; }
            .widget > h2 { margin-top: 0; }
            .widget .widget-note { color: var(--muted); font-size: .9rem; margin: .75rem 0 0; }');
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->id($this->id())
            ->class('widget')
            ->append(
                $this->hx->h2($this->stringProp('heading', 'Account')),
                $this->renderComponent(UserCard::class),
                $this->hx->p()->class('widget-note')->text($this->stringProp(
                    'note',
                    'A component, not a layout: it nests into any region and carries its own styles.'
                )),
            );
    }
}
