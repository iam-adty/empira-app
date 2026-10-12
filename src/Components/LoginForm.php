<?php

declare(strict_types=1);

namespace App\Components;

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
 * Sign in.
 *
 * The whole authentication flow lives here rather than in a controller, because
 * a component that renders a form and then cannot handle its own submission is
 * a form that has to hand itself off somewhere else. The action asks for
 * `Auth` and the repository by type; the page has no idea either exists.
 *
 * Three things this deliberately does:
 *
 * - **Says the same thing for an unknown email, a wrong password, and being
 *   rate limited.** Telling them apart turns the form into an account-existence
 *   oracle; a fourth variant saying "too many attempts" would tell an attacker
 *   they are being watched and exactly how long to wait. The *log* distinguishes
 *   all three, because a log is ours and the visitor's guesswork is not.
 * - **Refuses when somebody is already signed in.** The route behind this page
 *   redirects such a visitor away, but that is a page gate: this form posts to
 *   its own signed component URL, which no route middleware ever sees. A page
 *   left open before signing in would otherwise still be able to submit.
 * - **Counts failures per address and per address being signed into, and
 *   forgets both on success.** The count is what makes the first two honest
 *   over time; forgetting it is what keeps five correct sign-ins in a quarter
 *   of an hour from locking somebody out of their own account.
 */
final class LoginForm extends Component
{
    private const FAILED = 'Those credentials did not match any account.';

    #[Post]
    public function login(
        Auth $auth,
        UserRepository $users,
        RateLimiter $limiter,
        LoggerInterface $log,
    ): void {
        if ($auth->check()) {
            $this->response()->redirect('/dashboard');

            return;
        }

        $email = $this->request()->string('email');
        $ip = $this->request()->ip();

        $byIp = RateLimiter::key('login:ip', $ip ?? 'unknown');
        $byEmail = RateLimiter::key('login:email', $email);

        // Checked before any work, and never recorded — asking whether you are
        // blocked is not the same as trying. A visitor walking the page cannot
        // spend somebody else's allowance.
        if ($limiter->isBlocked($byIp, self::ipLimit(), self::ipWindow())
            || $limiter->isBlocked($byEmail, self::emailLimit(), self::window())
        ) {
            $log->warning('login blocked: rate limited', [
                'email' => $email,
                'ip' => $ip,
            ]);

            $this->refuse();

            return;
        }

        $user = $users->byEmail($email);

        // Two branches, one message. Telling a visitor which of the two it was
        // turns this form into an account-existence oracle, so the split is for
        // the log and never for the page. What it buys is the ability to answer
        // "was this one person struggling, or many?" — which is unanswerable
        // from a log that only records that something failed.
        if ($user === null) {
            $log->warning('login failed: no such user', ['email' => $email, 'ip' => $ip]);

            $this->recordFailure($limiter, $byIp, $byEmail, $email);

            return;
        }

        if (!$users->verify($user, $this->request()->string('password'))) {
            $log->warning('login failed: password did not match', ['email' => $email, 'ip' => $ip]);

            $this->recordFailure($limiter, $byIp, $byEmail, $email);

            return;
        }

        // Cleared on success, which is the detail that decides whether this is
        // usable at all. Counting every attempt would lock out somebody who has
        // signed in correctly five times in a quarter of an hour.
        $limiter->clear($byIp);
        $limiter->clear($byEmail);

        $auth->login($user);

        $this->response()->redirect('/dashboard');
    }

    /**
     * A rejected credential: show the one message, and spend the allowance.
     *
     * Both keys, not just the one that failed. A wrong password against a real
     * address is still traffic from that address, and counting only the email
     * would let anyone with one account spray from a rotating set of them.
     */
    private function recordFailure(RateLimiter $limiter, string $byIp, string $byEmail, string $email): void
    {
        $limiter->record($byIp, self::ipWindow());
        $limiter->record($byEmail, self::window());

        $this->with('error', self::FAILED)->with('email', $email);
    }

    /**
     * Refuse an attempt.
     *
     * The same message for a blocked request as for a wrong one, because saying
     * "too many attempts" tells an attacker their address is being watched and
     * exactly how long to wait.
     *
     * Two shapes, because this action has two kinds of caller. An htmx swap must
     * re-render the form — a bare status body would land in the middle of
     * somebody's page, so the error goes into the component's own props. Anything
     * else gets a real 429 with `Retry-After`, which means going through the
     * framework's error path rather than setting the response here: `Router`
     * finishes a component action with `setStatus(200)->setBody($markup)`, so
     * anything this method sets on the response would be overwritten.
     */
    private function refuse(): void
    {
        if ($this->request()->isHtmx()) {
            $this->with('error', self::FAILED)->with('email', $this->request()->string('email'));

            return;
        }

        throw new HttpException(429, self::FAILED, ['Retry-After' => (string) self::window()]);
    }

    private static function window(): int
    {
        return (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 900);
    }

    /**
     * Per-address, and much looser than per-email.
     *
     * Because every session from a developer reaching a WSL-hosted application
     * arrives from the same relayed address, a tight figure here locks the owner
     * out of their own demo — and buys almost nothing, since an attacker with
     * several addresses simply moves between them.
     */
    private static function ipLimit(): int
    {
        return (int) ($_ENV['RATE_LIMIT_LOGIN_IP'] ?? 20);
    }

    /** Tighter, because it is the one actually defending a single account. */
    private static function emailLimit(): int
    {
        return (int) ($_ENV['RATE_LIMIT_LOGIN_EMAIL'] ?? 5);
    }

    private static function ipWindow(): int
    {
        return (int) ($_ENV['RATE_LIMIT_IP_WINDOW'] ?? self::window());
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

        $form = $this->hx->div()
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
            );

        // The sign-up link is the one thing on this page that has to move when
        // registration closes. The form itself is already a 404 by then, so
        // leaving a link to it here would be advertising a route that answers
        // "not found".
        if (Registration::isOpen()) {
            $form = $form->append(
                $this->hx->p()->class('lede')
                    ->append($this->hx->a()->attr('href', '/register')->text('Create an account'))
            );
        }

        return $form;
    }
}
