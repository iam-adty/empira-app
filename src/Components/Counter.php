<?php

declare(strict_types=1);

namespace App\Components;

use Empira\Attributes\Post;
use Empira\Attributes\Prop;
use Empira\Component;
use Empira\Element;

/**
 * The smallest thing this framework is for, and the example from
 * {@see Component}'s own documentation.
 *
 * ## Why this is the example
 *
 * It is the same component, attribute for attribute, that the framework's
 * docblock shows — which is the reason it is here rather than something more
 * impressive. A skeleton whose example diverged from the documentation would be
 * teaching two different frameworks, and the documentation is what anybody
 * reading this repository first meets.
 *
 * ## What it actually demonstrates
 *
 * - **State in a signed token.** `$count` is not in a session and not in a
 *   database. It rides inside the URL the button carries, signed, so there is
 *   nothing on the server to keep in step and two users never share it.
 * - **A generated action URL.** There is no `hx-post` anywhere in this file and
 *   no route to register. `->action('increment')` asks for the URL and the
 *   framework writes it, along with the HTTP verb the dispatcher will accept —
 *   so the markup and the router cannot disagree.
 * - **An htmx swap.** The root is `div#counter`, which is what htmx replaces
 *   when the button is pressed.
 *
 * Press the button with the server running: `composer serve`, then open
 * <http://localhost:8000>.
 */
final class Counter extends Component
{
    #[Prop]
    public int $count = 0;

    #[Post]
    public function increment(): void
    {
        $this->count++;
    }

    protected function render(): Element
    {
        // Two append()s and no text() on the parent: `Element::text()` replaces
        // every child, so ending the chain with it discards the button — which
        // is the mistake the framework's own docblock example used to make.
        return $this->hx->div('#counter')
            ->append($this->hx->strong()->text((string) $this->count))
            ->append($this->hx->button('+1')->action('increment'));
    }
}