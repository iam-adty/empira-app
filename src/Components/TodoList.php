<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

/**
 * A todo list, stateful on the server.
 *
 * Note the absence of `hx-*`: the wiring is derived from the action names.
 * `stateful()` moves the item list into the session store, so the token stays
 * small and the list never travels through the URL.
 *
 * Three htmx patterns are on show:
 *   - `trigger('change delay:300ms from:input')` for debounced filtering;
 *   - `hxTargetSelf()` for rows that replace themselves in place;
 *   - a second element flagged `hxSwapOob()` so the footer count updates in the
 *     same response.
 */
final class TodoList extends Component
{
    public function actions(): array
    {
        return ['add', 'toggle', 'remove', 'clearCompleted', 'setFilter'];
    }

    protected function stateful(): bool
    {
        return true;
    }

    protected function hydrate(): void
    {
        if (!$this->hasProp('items')) {
            $this->with('items', []);
        }
    }

    public function mount(): void
    {
        $this->with('filter', $this->stringProp('filter', 'all'));
    }

    // ---------------------------------------------------------------- actions

    public function add(): void
    {
        $title = trim($this->request()->string('title'));

        if ($title === '') {
            $this->response()->triggerAfterSwap('todo:rejected', ['reason' => 'empty']);

            return;
        }

        $items = $this->arrayProp('items');

        $items[] = [
            'id' => bin2hex(random_bytes(4)),
            'title' => mb_substr($title, 0, 200),
            'done' => false,
        ];

        $this->with('items', $items)
            ->response()
            ->triggerAfterSettle('todo:added', ['title' => $title]);
    }

    public function toggle(): void
    {
        $id = $this->request()->string('id');

        $this->with('items', array_map(
            static fn(array $item): array => $item['id'] === $id ? ['done' => !$item['done']] + $item : $item,
            $this->arrayProp('items')
        ));
    }

    public function remove(): void
    {
        $id = $this->request()->string('id');

        $this->with('items', array_values(array_filter(
            $this->arrayProp('items'),
            static fn(array $item): bool => $item['id'] !== $id
        )));
    }

    public function clearCompleted(): void
    {
        $this->with('items', array_values(array_filter(
            $this->arrayProp('items'),
            static fn(array $item): bool => !$item['done']
        )));
    }

    public function setFilter(): void
    {
        $filter = $this->request()->string('filter', 'all');

        $this->with('filter', in_array($filter, ['all', 'active', 'done'], true) ? $filter : 'all');
    }

    // ---------------------------------------------------------------- render

    protected function render(): Element
    {
        $visible = $this->visibleItems();
        $total = count($this->arrayProp('items'));
        $done = count(array_filter($this->arrayProp('items'), static fn(array $i): bool => (bool) $i['done']));

        return $this->hx->section()
            ->class('todos')
            ->append(
                $this->hx->form()
                    ->class('todo-form')
                    ->append(
                        $this->hx->input()
                            ->attr('type', 'text')
                            ->attr('name', 'title')
                            ->attr('placeholder', 'What needs doing?')
                            ->attr('autofocus', true)
                    )
                    ->append($this->hx->button('Add')->attr('type', 'submit'))
                    ->action('add')
                    // Replaces the whole list, so the input is emptied too.
            )
            ->append($this->hx->ul()->class('todo-list')->append(...$visible))
            ->append(
                $this->hx->footer()
                    ->id('todo-footer')
                    ->class('todo-footer')
                    ->append($this->hx->span()->id('todo-count')->text($done . ' / ' . $total . ' done'))
                        // Out-of-band: updates in the same response, even though
                        // the swap target is the form.
                        ->hxSwapOob('true')
                    ->append($this->hx->button('Clear completed')->action('clearCompleted'))
            );
    }

    /**
     * @return list<Element>
     */
    private function visibleItems(): array
    {
        $filter = $this->stringProp('filter', 'all');

        $items = array_filter($this->arrayProp('items'), static function (array $item) use ($filter): bool {
            return match ($filter) {
                'active' => !$item['done'],
                'done' => (bool) $item['done'],
                default => true,
            };
        });

        return array_map($this->row(...), array_values($items));
    }

    private function row(array $item): Element
    {
        return $this->hx->li()
            ->id('todo-' . $item['id'])
            ->class($item['done'] ? 'done' : '')
            ->append(
                $this->hx->input()
                    ->attr('type', 'checkbox')
                    ->attr('checked', (bool) $item['done'])
                    ->hxPost($this->endpoint('toggle'))
                    ->hxInclude('#todo-' . $item['id'])
                    ->data('id', $item['id'])
                    ->hxTargetSelf()
            )
            ->append($this->hx->span()->text((string) $item['title']))
            ->append(
                $this->hx->button('x')
                    ->action('remove')
                    ->hxInclude('#todo-' . $item['id'])
                    ->data('id', $item['id'])
                    ->hxConfirm('Delete "' . $item['title'] . '"?')
            );
    }
}