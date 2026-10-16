<?php

declare(strict_types=1);

namespace App\App;

/**
 * The default secret: `APP_SECRET`, and nothing to fall back to.
 *
 * A component token is `base64url(payload).base64url(hmac)` and the payload
 * carries the component class and its props — so with the secret in the open,
 * anyone can mint a signed URL for any component with any props they like.
 * Nothing 500s, nothing is logged, and the forged URL simply works.
 *
 * There was a fallback once, a constant in the application class. It was public
 * in three places, so "change it before deploying" was a step every deployment
 * had to remember. A missing secret now stops the application everywhere, which
 * makes a first run one step longer and every deploy one safer.
 *
 * **It must not change between deploys** — that invalidates every component link
 * already in a user's history.
 */
final class EnvSecret implements ProvidesSecret
{
    public function secret(): string
    {
        $secret = (string) ($_ENV['APP_SECRET'] ?? '');

        if (strlen($secret) >= 16) {
            return $secret;
        }

        throw new \RuntimeException(sprintf(
            'APP_SECRET is %s. Generate one with: php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\' '
            . 'and put it in %s beside the rest of .env.example. It must be at least 16 bytes, and it '
            . 'must not change between deploys — changing it invalidates every component link already '
            . 'in a user\'s history.',
            $secret === '' ? 'not set' : sprintf('too short (%d bytes, 16 needed)', strlen($secret)),
            dirname(__DIR__, 2) . '/.env',
        ));
    }
}