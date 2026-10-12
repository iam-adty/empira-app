<?php

declare(strict_types=1);

namespace App\Pages;

use App\Components\RegisterForm;
use App\Layouts\SiteLayout;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\RegistrationOpen;
use IamAdty\Empira\Attributes\Route;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;
use IamAdty\Empira\View\Region;

/**
 * The sign-up page.
 *
 * Gated exactly like {@see LoginLayout} and open to the same verbs: somebody
 * who already has an account has no use for either form.
 *
 * `RegistrationOpen` is what makes the page a 404 when `REGISTRATION=closed`.
 * It is not sufficient on its own, and deliberately so: the form posts to its
 * own signed component URL, which this route never sees. The action checks for
 * itself, exactly as it checks for a signed-in visitor.
 */
#[Route('/register', name: 'register', methods: '*', middleware: [RegistrationOpen::class, RedirectIfAuthenticated::class])]
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
