<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\UserRepository;
use IamAdty\Empira\Console\Command;
use IamAdty\Empira\Console\Output;

/**
 * Put one user in, so the demo has somebody to be.
 *
 * A deploy step rather than something that happens on boot. Boot-time seeding is
 * a trap that only shows up under a worker: FrankenPHP runs a boot per thread,
 * so a dozen threads all reach the seeder having all found it empty, and eleven
 * of them die on the unique index. The old answer was a lock around boot, which
 * meant the cost was paid by every worker on every restart forever.
 *
 * Run it deliberately instead:
 *
 *     php vendor/bin/empira seed
 *
 * Still idempotent, because a deploy step is allowed to be run twice — a
 * retried pipeline should not fail on a seed that already happened. It simply
 * reports what it did.
 */
final class Seed implements Command
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Put the demo account in, so there is somebody to sign in as.';
    }

    public function arguments(): array
    {
        return ['[--email=address]', '[--password=secret]'];
    }

    public function handle(array $options, Output $output): int
    {
        $email = trim((string) ($options['email'] ?? '')) ?: 'ada@example.com';
        $password = (string) ($options['password'] ?? '') ?: 'correct horse battery staple';

        try {
            if ($this->users->byEmail($email) !== null) {
                $output->warn(sprintf('%s already exists; nothing to do.', $email));

                return 0;
            }

            $user = $this->users->register('Ada Lovelace', $email, $password);
        } catch (\Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $output->line(sprintf('  seeded %s (id %s)', $email, $user->id()));
        $output->line(sprintf('  sign in at /login with the password: %s', $password));

        return 0;
    }
}