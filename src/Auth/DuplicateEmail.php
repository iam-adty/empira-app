<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * That email address is already registered.
 *
 * Its own exception so the form can say so, rather than the controller
 * inspecting a driver's error message — which would be the wrong thing to
 * depend on and a bad one to show a user.
 */
final class DuplicateEmail extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('That email address is already registered.');
    }
}
