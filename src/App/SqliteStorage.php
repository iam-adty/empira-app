<?php

declare(strict_types=1);

namespace App\App;

/**
 * The default database: a SQLite file under `storage/`.
 *
 * SQLite so `composer serve` needs nothing installed and nothing running.
 *
 * The value is published into `$_ENV` before anything reads it, because two
 * readers had two defaults — the runtime wanted this file and the migration
 * command asked the framework and got `:memory:`. A migration against
 * `:memory:` is a database that vanishes on exit, followed by an application
 * querying a real one with no `users` table in it.
 *
 * **Boot does not migrate.** Migrating is a deploy step — `empira migrate` —
 * because boot is per worker thread, and a boot that migrates is a boot that has
 * eleven threads racing each other through the same migration.
 */
final class SqliteStorage implements ProvidesStorage
{
    public function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/app.sqlite';
    }
}