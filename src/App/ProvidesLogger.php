<?php

declare(strict_types=1);

namespace App\App;

use Psr\Log\LoggerInterface;

/**
 * Where the application gets its logger.
 */
interface ProvidesLogger
{
    public function logger(): LoggerInterface;
}
