<?php

declare(strict_types=1);

namespace App\Layouts;

use Empira\Element;
use Empira\Raw;
use Empira\View\Layout;
use Empira\View\Region;

/**
 * The document, and the regions a page fills.
 *
 * A page extends this and contributes a region rather than repeating the
 * document:
 *
 *     final class PageLayout extends SiteLayout
 *     {
 *         protected function regions(): array
 *         {
 *             return [...parent::regions(), 'main' => fn(Region $r) => …];
 *         }
 *     }
 *
 * `main` is declared **once**, here, so a page cannot forget it or spell it
 * differently. Each region is an element with an id, and htmx can swap any one
 * of them on its own — which is why they are regions and not just markup.
 *
 * Global styling lives in `styles()` and lands in `<head>` ahead of any
 * component's own sheet, which is emitted inside its root element and therefore
 * arrives later. A component overrides the global layer by ordering alone, with
 * no specificity fight.
 */
abstract class SiteLayout extends Layout
{
    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            'main' => fn(Region $region): mixed => null,
        ];
    }

    protected function title(): string
    {
        return 'Empira';
    }

    protected function header(): Element|Raw|null
    {
        return $this->hx->header()->append(
            $this->hx->h1($this->title()),
            $this->hx->p()->class('lede')->text(
                'htmx lives in PHP classes. Not one hx-* attribute below is hand-written.'
            ),
        );
    }

    /**
     * htmx, served from this application's own `public/`.
     *
     * The framework's default is a CDN URL, which is right for a library —
     * it cannot know where an application keeps its webroot, and assuming the
     * skeleton would 404 for anyone who did not install from it. A skeleton
     * *does* know, so it ships the file and points at it.
     *
     * `public/htmx.min.js` is committed, so this costs no build step and no
     * network on page load. `public/htmx.LICENSE` records the version and its
     * hash.
     */
    protected function htmxUrl(): string
    {
        return '/htmx.min.js';
    }

    protected function styles(): Element|Raw
    {
        return Raw::of(self::CSS);
    }

    /**
     * Enough to see that the thing arrived. Deliberately tiny: a skeleton is not
     * a design system, and a stylesheet somebody might mistake for one is a
     * liability in a file whose job is to be a starting point.
     */
    private const CSS = <<<'CSS'
        :root { color-scheme: light dark; }
        body { font: 16px/1.6 system-ui, sans-serif; margin: 0 auto; max-width: 44rem; padding: 3rem 1.5rem; }
        h1 { font-size: 1.75rem; margin: 0 0 .25rem; }
        .lede { color: #666; margin: 0 0 2rem; }
        a { color: inherit; }
        @media (prefers-color-scheme: dark) { .lede { color: #999; } }
        CSS;
}