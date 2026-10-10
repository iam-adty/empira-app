<?php

declare(strict_types=1);

namespace App;

/**
 * The demo's stylesheet.
 *
 * Kept out of the layout so the layout reads as structure and this reads as
 * presentation — and so it can be swapped for a real asset pipeline later
 * without touching either.
 */
final class Css
{
    public static function render(): string
    {
        return <<<'CSS'
:root { color-scheme: light dark; --fg: #16181d; --muted: #6b7280; --line: #e5e7eb; --bg: #ffffff; --card: #f9fafb; --accent: #4f46e5; }
@media (prefers-color-scheme: dark) {
  :root { --fg: #e5e7eb; --muted: #9ca3af; --line: #2b2f36; --bg: #0f1115; --card: #171a1f; --accent: #a5b4fc; }
}
* { box-sizing: border-box; }
body {
  margin: 0;
  padding: 2.5rem 1.25rem;
  background: var(--bg);
  color: var(--fg);
  font: 16px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
main { max-width: 44rem; margin: 0 auto; }
h1 { font-size: 1.9rem; margin: 0 0 .25rem; letter-spacing: -0.02em; }
h2 { font-size: 1rem; margin: 0 0 .75rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
p.lede { color: var(--muted); margin: 0 0 2rem; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: .75rem; padding: 1.25rem; margin-bottom: 1rem; }
button { font: inherit; cursor: pointer; border: 1px solid var(--line); background: var(--bg); color: var(--fg); border-radius: .5rem; padding: .4rem .85rem; }
button:hover { border-color: var(--accent); }
button.link { border: 0; background: none; color: var(--muted); padding: .4rem .5rem; }
.counter { display: flex; align-items: center; gap: .75rem; }
.counter strong { min-width: 3ch; text-align: center; font-variant-numeric: tabular-nums; font-size: 1.25rem; }
ul { list-style: none; padding: 0; margin: .75rem 0 0; }
li { padding: .35rem 0; border-bottom: 1px solid var(--line); }
li:last-child { border-bottom: 0; }
li.done { text-decoration: line-through; color: var(--muted); }
input[type="search"] { font: inherit; padding: .45rem .6rem; border: 1px solid var(--line); border-radius: .5rem; background: var(--bg); color: var(--fg); min-width: 16rem; }
.htmx-indicator { opacity: 0; color: var(--muted); margin-left: .5rem; }
.htmx-request .htmx-indicator, .htmx-request.htmx-indicator { opacity: 1; }
CSS;
    }
}