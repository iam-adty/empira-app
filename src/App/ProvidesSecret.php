<?php

declare(strict_types=1);

namespace App\App;

/**
 * Where the application gets its signing secret.
 *
 * One method, so replacing it is one method. The default reads `APP_SECRET`
 * and refuses without one.
 */
interface ProvidesSecret
{
    /** @throws \RuntimeException when there is no usable secret */
    public function secret(): string;
}
