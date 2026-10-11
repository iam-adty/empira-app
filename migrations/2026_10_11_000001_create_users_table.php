<?php

declare(strict_types=1);

use IamAdty\Empira\Data\Connection;
use IamAdty\Empira\Data\Migration;

/**
 * The users table, and only that.
 *
 * Two notes on what is *not* here: a `remember_token` column, and an index on
 * the credential column. Both arrive with the feature that needs them, which is
 * the whole argument for migrations over a hand-run schema file.
 *
 * The primary key branches on the dialect because there is no portable spelling
 * for an auto-incrementing column — SQLite wants AUTOINCREMENT and PostgreSQL
 * wants SERIAL. That is the cost of migrations being SQL rather than a schema
 * description, and it is the clearest argument for a schema builder later.
 */
return new class implements Migration {
    public function up(Connection $connection): void
    {
        $key = $connection->grammar()->driver() === 'sqlite'
            ? 'id INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'id SERIAL PRIMARY KEY';

        $connection->execute(sprintf(
            'CREATE TABLE users (
                %s,
                email TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL
            )',
            $key
        ));
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE users');
    }
};
