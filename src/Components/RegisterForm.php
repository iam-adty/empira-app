<?php

declare(strict_types=1);

namespace App\Components;

use App\Auth\DuplicateEmail;
use App\Auth\UserRepository;
use App\RateLimit\RateLimiter;
use App\Registration;
use Empira\Auth\Auth;
use Empira\Attributes\Post;
use Empira\Component;
use Empira\Element;
use Empira\Raw;
use Empira\Routing\HttpException;
use Psr\Log\LoggerInterface;

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
    public function register(
        Auth $auth,
        UserRepository $users,
        RateLimiter $limiter,
        LoggerInterface $log,
    ): void {
        if ($auth->check()) {
            $this->response()->redirect('/dashboard');

            return;
        }

        // Checked here rather than left to the route's `RegistrationOpen`,
        // because this action posts to its own signed component URL and no route
        // middleware ever sees it. A page-level gate alone would 404 the page
        // while leaving the endpoint open to anybody who had the form open
        // before it closed — which is the same trap the signed-in check above
        // already has to work around.
        if (!Registration::isOpen()) {
            $log->warning('registration refused: closed', ['ip' => $this->request()->ip()]);

            $this->refuse();

            return;
        }

        $ip = $this->request()->ip();
        $byIp = RateLimiter::key('register:ip', $ip ?? 'unknown');

        // Per address only. Stopping one host creating a pile of throwaway
        // accounts is the point; there is no account being defended, so there is
        // nothing to count per credential.
        if ($limiter->isBlocked($byIp, self::ipLimit(), self::window())) {
            $log->warning('registration blocked: rate limited', ['ip' => $ip]);

            $this->refuse();

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

        // Recorded after validation and not before: a form that failed its own
        // rules is not an attempt to create an account, and counting those would
        // make a typo look like an attack.
        $limiter->record($byIp, self::window());

        try {
            $user = $users->register($name, $email, $password);
        } catch (DuplicateEmail $e) {
            $this->with('errors', ['email' => [$e->getMessage()]])->with('email', $email);

            return;
        }

        $auth->login($user);

        $this->response()->redirect('/dashboard');
    }

    /**
     * Refuse, the same way {@see LoginForm::refuse()} does.
     *
     * A swap re-renders the form; anything else gets a real 429 with
     * `Retry-After`. Closing registration and being rate limited answer
     * differently — one is 404, because the endpoint should look absent, and the
     * other is 429, because the person has a legitimate reason to come back.
     */
    private function refuse(): void
    {
        if (!Registration::isOpen()) {
            // Gone, not slow. A page saying "try again later" about something
            // that will never reopen is worse than one saying nothing.
            if (! $this->request()->isHtmx()) {
                throw new HttpException(404, Registration::notFoundMessage());
            }

            $this->with('errors', ['name' => [Registration::notFoundMessage()]]);

            return;
        }

        if (! $this->request()->isHtmx()) {
            throw new HttpException(429, 'Too many attempts.', [
                'Retry-After' => (string) self::window(),
            ]);
        }

        $this->with('errors', ['name' => ['Too many attempts. Try again later.']]);
    }

    private static function window(): int
    {
        return (int) ($_ENV['RATE_LIMIT_REGISTER_WINDOW'] ?? 3600);
    }

    /** Looser than sign-in per address, and for the same WSL reason. */
    private static function ipLimit(): int
    {
        return (int) ($_ENV['RATE_LIMIT_REGISTER'] ?? 3);
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
