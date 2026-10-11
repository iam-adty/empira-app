<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\RegisterForm;
use App\Layouts\SiteLayout;
use App\Middleware\RedirectIfAuthenticated;
use IamAdty\Empira\Attributes\Route;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;
use IamAdty\Empira\View\Region;

/**
 * The sign-up page.
 *
 * Gated exactly like {@see LoginLayout} and open to the same verbs: somebody
 * who already has an account has no use for either form.
 */
#[Route('/register', name: 'register', methods: '*', middleware: [RedirectIfAuthenticated::class])]
final class RegisterLayout extends SiteLayout
{
    protected function title(): string
    {
        return 'Create an account — Empira demo';
    }

    /**
     * @return array<string, callable(Region): (Element|Raw|null)>
     */
    protected function regions(): array
    {
        return [
            ...parent::regions(),
            'main' => fn(Region $region): mixed => $region->component(RegisterForm::class),
        ];
    }
}
