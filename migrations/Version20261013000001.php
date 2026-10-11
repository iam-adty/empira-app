<?php

declare(strict_types=1);

namespace Empira\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Somewhere to count things that went wrong.
 *
 * A table, and not an array in a worker, because a worker is per-thread: this
 * application runs 32 of them, so an in-memory counter would give 32 separate
 * tallies and the limit actually in force would be 32 times the configured one.
 * Silently, and in the direction that makes the limit worthless. The same trap
 * as the session and the ORM's identity map, and the same answer — state that
 * every process shares.
 *
 * `window_started_at` is an epoch integer rather than a timestamp because the
 * two supported servers spell those differently, and this table is read on every
 * failed sign-in and compared against arithmetic. It carries an index for one
 * reason: {@see \App\RateLimit\RateLimiter::prune()} deletes expired rows, and
 * without it that is a table scan on the one path that must not be slow.
 *
 * `limiter_key` holds a hash, never the address or email itself. This table is
 * going to accumulate a record of every address anybody has tried to sign in
 * with, and that is not something to leave lying around in plaintext — least of
 * all the addresses that guessed wrong.
 */
final class Version20261013000001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $limits = $schema->createTable('rate_limits');
        $limits->addColumn('id', 'integer', ['autoincrement' => true]);
        $limits->setPrimaryKey(['id']);

        // 191 rather than 255: that is the longest a utf8mb4 column can be
        // while still carrying a unique index on MySQL, and the keys here are
        // hex digests far shorter than that. The column is sized for the worst
        // engine in the list rather than the two we actually run.
        $limits->addColumn('limiter_key', 'string', ['length' => 191]);
        $limits->addColumn('attempts', 'integer', ['default' => 0]);
        $limits->addColumn('window_started_at', 'bigint', ['default' => 0]);

        // The upsert names this as its conflict target, so it has to exist.
        $limits->addUniqueIndex(['limiter_key'], 'rate_limits_key_unique');

        // For the prune. Nothing else reads by it.
        $limits->addIndex(['window_started_at'], 'rate_limits_window_idx');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('rate_limits');
    }
}