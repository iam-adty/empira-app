<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

/**
 * One labelled input, with room for a message.
 *
 * Structural rather than behavioural: it has no action and no styling of its
 * own, because the form that composes it owns both. A `<style>` here would
 * repeat on every field of every form on the page.
 */
final class FormField extends Component
{
    protected function render(): Element
    {
        $name = $this->stringProp('name');
        $error = $this->stringProp('error');
        $id = $this->id() . '-' . $name;

        return $this->hx->div()
            ->id($this->id())
            ->class('field')
            ->append(
                $this->hx->label()->attr('for', $id)->text($this->stringProp('label', $name)),
                $this->hx->input()
                    ->attr('id', $id)
                    ->attr('name', $name)
                    ->attr('type', $this->stringProp('type', 'text'))
                    ->attr('value', $this->stringProp('value'))
                    ->attr('autocomplete', $this->stringProp('autocomplete', 'off'))
                    ->attr('required', true),
                $error === ''
                    ? null
                    : $this->hx->p()->class('field-error')->attr('role', 'alert')->text($error),
            );
    }
}
