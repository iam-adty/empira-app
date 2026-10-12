<?php

declare(strict_types=1);

namespace App\Layouts;

use App\Components\NavBar;
use App\Css;
use Empira\Element;
use Empira\Raw;
use Empira\View\Layout;
use Empira\View\Region;

/**
 * The master layout: the document, and the two regions every page has.
 *
 * A page extends this and adds its own regions rather than repeating the
 * document:
 *
 *     final class LoginLayout extends SiteLayout
 *     {
 *         protected function regions(): array
 *         {
 *             return [...parent::regions(), 'main' => fn(Region $r) => …];
 *         }
 *     }
 *
 * `nav` is declared **once**, here, so a page cannot forget it or spell it
 * differently. `main` is left to the subclass.
 *
 * Global styling is also declared once, in `styles()`. It lands in `<head>`
 * ahead of any component's own sheet, which is emitted inside its root element
 * and therefore arrives later — so a component overrides the global layer by
 * ordering alone, with no specificity fight.
 *
 * @see Css
 */
abstract class SiteLayout extends Layout
{
    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            'nav' => fn(Region $region): mixed => $region->component(NavBar::class),
            'main' => fn(Region $region): mixed => null,
        ];
    }

    protected function title(): string
    {
        return 'Empira';
    }

    protected function header(): Element|Raw|null
    {
        return $this->hx->main()->append(
            $this->hx->h1($this->title()),
            $this->hx->p()->class('lede')->text(
                'htmx lives in PHP classes. Not one hx-* attribute below is hand-written.'
            ),
        );
    }

    protected function styles(): Element|Raw
    {
        return Raw::of(Css::base());
    }
}