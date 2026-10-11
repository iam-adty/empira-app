<?php

declare(strict_types=1);

namespace App\Components;

use App\Auth\DuplicateEmail;
use App\Auth\UserRepository;
use IamAdty\Empira\Auth\Auth;
use IamAdty\Empira\Attributes\Post;
use IamAdty\Empira\Component;
use IamAdty\Empira\Element;
use IamAdty\Empira\Raw;

/**
 * Create an account, and sign in with it.
 *
 * Validation runs before anything is written, and a failure re-renders this
 * form with the messages rather than navigating away — the visitor keeps the
 * page, the fields and what was wrong with them. `confirmed` gives the second
 * field for free, so the rule and the field cannot drift apart.
 *
 * Signing the new user straight in is the usual shape of a sign-up: making
 * them type the password they just chose again is a tax with no benefit.
 */
final class RegisterForm extends Component
{
    #[Post]
    public function register(Auth $auth, UserRepository $users): void
    {
        if ($auth->check()) {
            $this->response()->redirect('/dashboard');

            return;
        }

        $name = $this->request()->string('name');
        $email = $this->request()->string('email');
        $password = $this->request()->string('password');

        // `validate()` returns an ErrorBag; it does not throw, and it reads
        // `password_confirmation` for the `confirmed` rule by itself.
        $errors = $this->validate([
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($errors->any()) {
            $this->with('errors', $errors->all())
                ->with('name', $name)
                ->with('email', $email);

            return;
        }

        try {
            $user = $users->register($name, $email, $password);
        } catch (DuplicateEmail $e) {
            $this->with('errors', ['email' => [$e->getMessage()]])->with('email', $email);

            return;
        }

        $auth->login($user);

        $this->response()->redirect('/dashboard');
    }

    protected function styles(): Raw
    {
        // Structural — the only styling it owns is the submit row.
        return Raw::of('.auth-form .submit-row { display: flex; align-items: center; gap: .75rem; }');
    }

    protected function render(): Element
    {
        /** @var array<string, list<string>> $errors */
        $errors = $this->arrayProp('errors');

        return $this->hx->div()
            ->id($this->id())
            ->class('card auth-form')
            ->append(
                $this->renderComponent(FormField::class, [
                    'name' => 'name',
                    'label' => 'Name',
                    'autocomplete' => 'name',
                    'value' => $this->stringProp('name'),
                    'error' => $this->firstError($errors, 'name'),
                ]),
                $this->renderComponent(FormField::class, [
                    'name' => 'email',
                    'label' => 'Email',
                    'type' => 'email',
                    'autocomplete' => 'email',
                    'value' => $this->stringProp('email'),
                    'error' => $this->firstError($errors, 'email'),
                ]),
                $this->renderComponent(FormField::class, [
                    'name' => 'password',
                    'label' => 'Password',
                    'type' => 'password',
                    'autocomplete' => 'new-password',
                    'error' => $this->firstError($errors, 'password'),
                ]),
                $this->renderComponent(FormField::class, [
                    'name' => 'password_confirmation',
                    'label' => 'Confirm password',
                    'type' => 'password',
                    'autocomplete' => 'new-password',
                    'error' => $this->firstError($errors, 'password_confirmation'),
                ]),
                $this->hx->div()->class('submit-row')->append(
                    $this->hx->button()->text('Create account')->action('register'),
                    $this->hx->span()->class('lede')
                        ->append($this->hx->a()->attr('href', '/login')->text('I already have an account')),
                ),
            );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function firstError(array $errors, string $field): string
    {
        return $errors[$field][0] ?? '';
    }
}
