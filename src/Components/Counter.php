<?php

declare(strict_types=1);

namespace App\Components;

use IamAdty\Empira\Attributes\Prop;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;

/**
 * The smallest useful component: two buttons and a number.
 *
 * No `hx-*` attribute appears here. `action()` asks the component for the
 * endpoint, and the component supplies the target and swap strategy.
 *
 * State is a typed property: `$this->count` is hydrated from the signed token
 * before any action runs and written back into the next token on the way out,
 * so no props array is needed anywhere.
 */
final class Counter extends Component
{
    public const STEP = 5;

    #[Prop]
    public int $count = 0;

    public function actions(): array
    {
        return ['increment', 'decrement', 'reset'];
    }

    public function increment(): void
    {
        $this->count += self::STEP;
    }

    public function decrement(): void
    {
        $this->count -= self::STEP;
    }

    public function reset(): void
    {
        $this->count = 0;
    }

    protected function render(): Element
    {
        return $this->hx->div()
            ->class('counter')
            ->append($this->hx->button('-')->action('decrement')->attr('aria-label', 'Decrease'))
            ->append($this->hx->strong()->text((string) $this->count))
            ->append($this->hx->button('+')->action('increment')->attr('aria-label', 'Increase'))
            ->append(
                $this->hx->button('reset')
                    ->action('reset')
                    ->class('link')
                    ->hxPushUrl(false)
            );
    }
}