<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\LoginForm;
use App\Layouts\SiteLayout;
use App\Middleware\RedirectIfAuthenticated;
use Empira\Attributes\Route;
use Empira\Element;
use Empira\Raw;
use Empira\View\Region;

/**
 * The sign-in page.
 *
 * `methods: '*'` because the gate should not depend on the verb: a signed-in
 * visitor should be turned away whether they arrived by GET or by anything
 * else. The page renders for a guest whatever the verb, since the submission
 * goes to the form component's own signed URL rather than here.
 */
#[Route('/login', name: 'login', methods: '*', middleware: [RedirectIfAuthenticated::class])]
final class LoginLayout extends SiteLayout
{
    protected function title(): string
    {
        return 'Sign in — Empira demo';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            ...parent::regions(),
            'main' => fn(Region $region): mixed => $region->component(LoginForm::class),
        ];
    }
}
