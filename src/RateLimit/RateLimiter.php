<?php

declare(strict_types=1);

namespace App\RateLimit;

use Doctrine\DBAL\Connection;

/**
 * Counting what went wrong, where every worker can see it.
 *
 * The obvious implementation is an array in a static property, and here it
 * would be wrong: a worker is per-thread and this application runs 32 of them,
 * so each would keep its own tally and the limit actually in force would be
 * thirty-two times the configured one — silently, and in the direction that
 * makes the limit worthless. State has to be shared, so it lives in the
 * database, next to everything else that has to outlive a process.
 *
 * That sounds expensive on a login path and is not. `password_verify` at the
 * default bcrypt cost takes around 300ms; a couple of indexed statements beside
 * it is rounding error.
 *
 * Counting happens in a single upsert so two workers arriving together cannot
 * both read four attempts and both write five. `INSERT … ON CONFLICT DO UPDATE`
 * is one statement, atomic, and spelled the same by PostgreSQL and SQLite, so
 * there is no dialect branch to get wrong.
 */
final class RateLimiter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Has this key spent its allowance inside the current window?
     *
     * Read-only: it never records an attempt, because asking whether you are
     * blocked is not the same as trying. A caller that checks and then does not
     * proceed — a bot walking the login page — cannot lock a real user out.
     */
    public function isBlocked(string $key, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $now ??= time();
        $row = $this->row($key);

        if ($row === null) {
            return false;
        }

        // A window that has elapsed is no window at all: the key starts again
        // from zero rather than staying blocked until something resets it.
        if ($now - $row['window_started_at'] >= $windowSeconds) {
            return false;
        }

        return $row['attempts'] >= $limit;
    }

    /**
     * Note that something went wrong against this key.
     *
     * Idempotent in the way a counter is not: calling this twice counts twice,
     * which is the point. A caller records only when it has actually rejected
     * something, or every honest attempt would be indistinguishable from a
     * stream of guesses.
     */
    public function record(string $key, int $windowSeconds, ?int $now = null): void
    {
        $now ??= time();

        // `:cutoff` is a named parameter used twice, which is why the statement
        // is built as a string rather than left to positional binding.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO rate_limits (limiter_key, attempts, window_started_at)
                VALUES (:key, 1, :now)
                ON CONFLICT (limiter_key) DO UPDATE SET
                    attempts = CASE
                        WHEN rate_limits.window_started_at <= :cutoff THEN 1
                        ELSE rate_limits.attempts + 1
                    END,
                    window_started_at = CASE
                        WHEN rate_limits.window_started_at <= :cutoff THEN :now
                        ELSE rate_limits.window_started_at
                    END
                SQL,
            [
                'key' => $key,
                'now' => $now,
                'cutoff' => $now - $windowSeconds,
            ],
        );
    }

    /**
     * Forget a key entirely.
     *
     * Called when an attempt *succeeds*. Without this the limiter punishes
     * correct passwords: five successful sign-ins in a quarter of an hour would
     * lock out somebody using the application exactly as intended.
     */
    public function clear(string $key): void
    {
        $this->connection->executeStatement(
            'DELETE FROM rate_limits WHERE limiter_key = :key',
            ['key' => $key],
        );
    }

    /**
     * Drop rows whose window has closed.
     *
     * Without it the table grows for as long as the process is up, one row per
     * distinct address ever seen. `$longestWindow` is the largest window in
     * play, so the cut-off is conservative: a row is only removed once nothing
     * could still be counting against it.
     *
     * @return int rows removed
     */
    public function prune(int $longestWindow, ?int $now = null): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM rate_limits WHERE window_started_at <= :cutoff',
            ['cutoff' => ($now ?? time()) - $longestWindow],
        );
    }

    /**
     * A key for something worth counting, hashed.
     *
     * The hash is the whole point. The raw value is an email address or an IP
     * that somebody offered, and this table is a running record of every
     * credential anybody has tried — which is not a thing to leave readable.
     *
     * Plain SHA-256 rather than keyed, because the input is low-entropy and a
     * dictionary attack on a hex digest is cheap. That is an accepted trade for
     * this table: it is not a secret store, it is a tally, and the addresses in
     * it are the ones people typed into a login form.
     */
    public static function key(string $namespace, string $value): string
    {
        return $namespace . ':' . hash('sha256', mb_strtolower(trim($value)));
    }

    /**
     * @return array{attempts: int, window_started_at: int}|null
     */
    private function row(string $key): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT attempts, window_started_at FROM rate_limits WHERE limiter_key = :key',
            ['key' => $key],
        );

        return $row === false
            ? null
            : ['attempts' => (int) $row['attempts'], 'window_started_at' => (int) $row['window_started_at']];
    }
}