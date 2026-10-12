<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\UserRepository;
use IamAdty\Empira\Console\Command;
use IamAdty\Empira\Console\Output;

/**
 * Put the demo accounts in, so there is somebody to sign in as.
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
 *
 * `--email` with the other options seeds one account instead of the set, which
 * is how you get a login that is not one of the documented demos — and `--name`
 * exists because the name used to be hardcoded to Ada Lovelace, so anything you
 * seeded came out called Ada.
 */
final class Seed implements Command
{
    /**
     * The demo accounts.
     *
     * `test1234` rather than `test`: the sign-up form requires eight characters,
     * and a seeded account that the application itself would refuse to create is
     * a fixture that disagrees with its own rules.
     */
    private const ACCOUNTS = [
        [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
        ],
        [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'test1234',
        ],
    ];

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Put the demo accounts in, so there is somebody to sign in as.';
    }

    public function arguments(): array
    {
        return ['[--name=full name]', '[--email=address]', '[--password=secret]'];
    }

    public function handle(array $options, Output $output): int
    {
        $accounts = $this->accounts($options);

        $failed = 0;

        foreach ($accounts as $account) {
            if (! $this->seed($account, $output)) {
                $failed++;
            }
        }

        if ($accounts === self::ACCOUNTS) {
            $output->line('  sign in at /login with:');

            foreach ($accounts as $account) {
                $output->line(sprintf('    %-22s /  %s', $account['email'], $account['password']));
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    /**
     * @param array{name: string, email: string, password: string} $account
     */
    private function seed(array $account, Output $output): bool
    {
        try {
            if ($this->users->byEmail($account['email']) !== null) {
                $output->warn(sprintf('  %s already exists; nothing to do.', $account['email']));

                return true;
            }

            $user = $this->users->register($account['name'], $account['email'], $account['password']);
        } catch (\Throwable $e) {
            $output->error(sprintf('  %s: %s', $account['email'], $e->getMessage()));

            return false;
        }

        $output->line(sprintf('  seeded %s (id %s)', $account['email'], $user->id()));

        return true;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{name: string, email: string, password: string}>
     */
    private function accounts(array $options): array
    {
        $email = trim((string) ($options['email'] ?? ''));

        // No `--email` means the documented set. With one, seed only that: the
        // point of the option is an account of your own, and quietly seeding the
        // demos as well would surprise anybody who ran it in production.
        if ($email === '') {
            return self::ACCOUNTS;
        }

        return [[
            'name' => trim((string) ($options['name'] ?? '')) ?: $email,
            'email' => $email,
            'password' => (string) ($options['password'] ?? '') ?: bin2hex(random_bytes(8)),
        ]];
    }
}