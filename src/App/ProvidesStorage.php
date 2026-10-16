<?php

declare(strict_types=1);

namespace App\App;

/**
 * Where the application keeps its database.
 */
interface ProvidesStorage
{
    /** An absolute path, or one relative to the application root. */
    public function path(): string;
}
