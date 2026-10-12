<?php

declare(strict_types=1);

namespace App;

/**
 * Whether anybody at all may create an account.
 *
 * Open unless the environment says otherwise, so nothing about the application
 * changes until somebody decides to close it — the same bargain the secret
 * refusal makes, and for the same reason: a default that fails closed turns a
 * developer's first run into an error about a variable they have never heard of.
 *
 * Read from `$_ENV` rather than constructor-injected so that a test can flip it
 * with one assignment, the way it flips `DB_DRIVER`.
 */
final class Registration
{
    public static function isOpen(): bool
    {
        return ($_ENV['REGISTRATION'] ?? 'open') !== 'closed';
    }

    /**
     * Why registration is refused, phrased for whoever hit it.
     *
     * Not that the page exists and is switched off — that tells an attacker
     * there is an account system here worth coming back to. "Not found" is the
     * same answer the application gives for a route that never existed.
     */
    public static function notFoundMessage(): string
    {
        return 'Not found';
    }
}