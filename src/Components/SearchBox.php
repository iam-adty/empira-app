<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Attributes\Get;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

/**
 * Debounced search box.
 *
 * Shows a GET-flavoured action, a custom `hx-trigger` for debouncing, an
 * indicator, and an `HX-Replace-Url` so the query survives a refresh without
 * cluttering the history.
 *
 * `#[Get]` is the whole declaration: the action is exposed, the framework
 * emits `hx-get` instead of `hx-post`, and the router refuses the action over
 * any other method — so the verb can never drift from what the markup says.
 */
final class SearchBox extends Component
{
    #[Get]
    public function search(): void
    {
        $query = trim($this->request()->string('q'));

        $this->with('q', $query)->with('results', $this->find($query));

        $this->response()->replaceUrl('/?q=' . rawurlencode($query));
    }

    protected function render(): Element
    {
        $query = $this->stringProp('q', '');

        return $this->hx->div()
            ->id('search')
            ->append(
                $this->hx->input()
                    ->attr('type', 'search')
                    ->attr('name', 'q')
                    ->attr('value', $query)
                    ->attr('placeholder', 'Search…')
                    ->action('search', [
                        'trigger' => 'input changed delay:250ms, search',
                        'indicator' => '#searching',
                    ])
            )
            ->append($this->hx->span()->id('searching')->class('htmx-indicator')->text('searching…'))
            ->append(
                $this->hx->ul()->id('results')->append(...array_map(
                    fn(string $hit): Element => $this->hx->li()->text($hit),
                    $this->arrayProp('results')
                ))
            );
    }

    /**
     * @return list<string>
     */
    private function find(string $query): array
    {
        if ($query === '') {
            return [];
        }

        return array_values(array_filter(
            ['htmx', 'empira', 'hypermedia', 'multitail', 'goodhart'],
            static fn(string $haystack): bool => str_contains($haystack, strtolower($query))
        ));
    }
}