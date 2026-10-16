<?php

declare(strict_types=1);

namespace App\Pages;

use App\Layouts\SiteLayout;
use Empira\Element;
use Empira\View\Region;

/**
 * The sign-in form, in this application's own layout.
 *
 * Named by `#[Modules(..., login: LoginLayout::class)]` on `App`. The auth
 * module ships a page too, and this replaces it — which is the point: a login
 * page should look like the rest of the application, and only the application
 * knows what that is.
 *
 * It carries no `#[Route]`. The module owns the three authentication URLs and
 * points `GET /login` here, so this is a page rather than a registration.
 */
final class LoginLayout extends SiteLayout
{
    protected function title(): string
    {
        return 'Sign in';
    }

    protected function regions(): array
    {
        return [
            'main' => fn(Region $region): Element => $this->form($region),
        ];
    }

    private function form(Region $region): Element
    {
        $error = $region->data('error');

        $form = $this->hx->form()
            ->attr('method', 'post')
            ->attr('action', '/login')
            ->append(
                $this->hx->label('Email')
                    ->append(
                        $this->hx->input()
                            ->attr('name', 'email')
                            ->attr('value', (string) $region->data('email', ''))
                            ->attr('autocomplete', 'username'),
                    ),
                $this->hx->label('Password')
                    ->append(
                        $this->hx->input()
                            ->attr('name', 'password')
                            ->attr('type', 'password')
                            ->attr('autocomplete', 'current-password'),
                    ),
                $this->hx->button('Sign in')->attr('type', 'submit'),
            );

        // Absent under `bootConsole()`, where there is no browser and nothing to
        // protect. Omitting it there is correct rather than a fallback: the POST
        // does not exist in a console.
        if ($this->csrf !== null) {
            $form->append(
                $this->hx->input()
                    ->attr('type', 'hidden')
                    ->attr('name', '_csrf')
                    ->attr('value', $this->csrf->token()),
            );
        }

        return $this->hx->div('login')
            ->append($error === null ? null : $this->hx->p()->class('error')->text((string) $error))
            ->append($form);
    }
}