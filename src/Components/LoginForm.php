<?php

declare(strict_types=1);

namespace App\Components;

use App\Auth\UserRepository;
use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;

/**
 * Sign in.
 *
 * The whole authentication flow lives here rather than in a controller, because
 * a component that renders a form and then cannot handle its own submission is
 * a form that has to hand itself off somewhere else. The action asks for
 * `Auth` and the repository by type; the page has no idea either exists.
 *
 * Two things this deliberately does:
 *
 * - **Says the same thing for an unknown email and a wrong password.** Telling
 *   them apart turns the form into an account-existence oracle.
 * - **Refuses when somebody is already signed in.** The route behind this page
 *   redirects such a visitor away, but that is a page gate: this form posts to
 *   its own signed component URL, which no route middleware ever sees. A page
 *   left open before signing in would otherwise still be able to submit.
 */
final class LoginForm extends Component
{
    private const FAILED = 'Those credentials did not match any account.';

    #[Post]
    public function login(Auth $auth, UserRepository $users): void
    {
        if ($auth->check()) {
            $this->response()->redirect('/dashboard');

            return;
        }

        $email = $this->request()->string('email');
        $user = $users->byEmail($email);

        if ($user === null || !$users->verify($user, $this->request()->string('password'))) {
            // The email is echoed back so the visitor does not retype it. The
            // password never is — it is not even kept.
            $this->with('error', self::FAILED)->with('email', $email);

            return;
        }

        $auth->login($user);

        $this->response()->redirect('/dashboard');
    }

    protected function styles(): Raw
    {
        return Raw::of('.auth-form .field { margin-bottom: .9rem; }
            .auth-form .field label { display: block; font-size: .9rem; margin-bottom: .25rem; }
            .auth-form .field input { width: 100%; padding: .5rem .6rem; border: 1px solid var(--line); border-radius: .35rem; font: inherit; }
            .auth-form .field-error { color: var(--danger); font-size: .875rem; margin: .3rem 0 0; }
            .auth-form .form-error { color: var(--danger); margin: 0 0 1rem; }');
    }

    protected function render(): Element
    {
        $error = $this->stringProp('error');

        return $this->hx->div()
            ->id($this->id())
            ->class('card auth-form')
            ->append(
                $error === ''
                    ? null
                    : $this->hx->p()->class('form-error')->attr('role', 'alert')->text($error),
                $this->renderComponent(FormField::class, [
                    'name' => 'email',
                    'label' => 'Email',
                    'type' => 'email',
                    'autocomplete' => 'email',
                    'value' => $this->stringProp('email'),
                ]),
                $this->renderComponent(FormField::class, [
                    'name' => 'password',
                    'label' => 'Password',
                    'type' => 'password',
                    'autocomplete' => 'current-password',
                ]),
                $this->hx->button()->text('Sign in')->action('login'),
                $this->hx->p()->class('lede')
                    ->append($this->hx->a()->attr('href', '/register')->text('Create an account')),
            );
    }
}
