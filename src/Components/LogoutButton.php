<?php

declare(strict_types=1);

namespace App\Components;

use Empira\Auth\Auth;
use Empira\Attributes\Post;
use Empira\Component;
use Empira\Element;

/**
 * Sign out.
 *
 * Asks `Auth` for its dependencies rather than reaching into the container,
 * which is the point of actions being autowired: a component that talks to the
 * application's services looks the same as a controller that does.
 */
final class LogoutButton extends Component
{
    #[Post]
    public function logout(Auth $auth): void
    {
        $auth->logout();

        // A guest reaching this has nothing to sign out of; going home is the
        // honest outcome either way, so there is nothing to guard against.
        $this->response()->redirect('/');
    }

    protected function render(): Element
    {
        return $this->hx->button()
            ->id($this->id())
            ->text('Sign out')
            ->action('logout');
    }
}
