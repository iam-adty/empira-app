<?php

declare(strict_types=1);

namespace App\App;

/**
 * What this application is made of.
 *
 * A module list here, rather than a registry file, is what lets a module be
 * named without the application constructing it: `App` resolves each class
 * through the container and the module builds itself from what the framework has
 * already bound.
 *
 * `login` names the page `GET /login` renders. It is an option rather than a
 * path because the module's own page has no way to know what the rest of this
 * application looks like — a layout is the application's business, and a module
 * that guessed would be shipping somebody else's design into it.
 *
 * @see \App\Pages\LoginLayout for the page this one ships with
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Modules
{
    /**
     * @param list<class-string<\Empira\Module>> $modules installed in order;
     *                                                the first is outermost
     * @param class-string|null                    $login    the layout `GET /login`
     *                                                renders; the module's own
     *                                                page if this is omitted
     */
    public function __construct(
        public readonly array $modules = [],
        public readonly ?string $login = null,
    ) {
    }
}