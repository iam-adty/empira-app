<?php

declare(strict_types=1);

namespace App;

/**
 * The application-wide stylesheet.
 *
 * This is the **global** layer: a reset, a type scale, and the few things every
 * page shares. Anything a single component owns belongs in that component's
 * own `styles()`, not here — the split is what stops this sheet becoming a
 * list of everyone's private rules.
 *
 * Order matters between the two layers. Global rules are loaded in `<head>`;
 * a component's own sheet is emitted inside its root element, so it arrives
 * later in the document and wins on equal specificity.
 */
final class Css
{
    public static function base(): string
    {
        return <<<'CSS'
            *, *::before, *::after { box-sizing: border-box; }

            :root {
                --ink: #16181d;
                --muted: #5c6270;
                --line: #e2e5ea;
                --accent: #2f6f4f;
                --danger: #a12d2d;
                --surface: #ffffff;
                --page: #f6f7f9;
            }

            body {
                margin: 0;
                padding: 0 1.5rem 4rem;
                background: var(--page);
                color: var(--ink);
                font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            }

            main { max-width: 42rem; margin: 0 auto; }

            h1 { font-size: 1.75rem; margin: 0 0 .5rem; letter-spacing: -.02em; }
            h2 { font-size: 1.1rem; margin: 0 0 .75rem; }

            a { color: var(--accent); }

            p.lede { color: var(--muted); margin: 0 0 1.5rem; }

            .card {
                background: var(--surface);
                border: 1px solid var(--line);
                border-radius: .6rem;
                padding: 1.25rem;
                margin: 0 0 1.25rem;
            }

            .actions { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }

            button {
                font: inherit;
                padding: .45rem .9rem;
                border: 1px solid var(--line);
                border-radius: .4rem;
                background: var(--surface);
                color: var(--ink);
                cursor: pointer;
            }

            button:hover { border-color: var(--muted); }

            .htmx-indicator { opacity: 0; transition: opacity .2s; }
            .htmx-request .htmx-indicator { opacity: 1; }

            CSS;
    }
}