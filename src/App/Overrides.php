<?php

declare(strict_types=1);

namespace App\App;

/**
 * The defaults this application is built from.
 *
 * Named here rather than passed to a constructor, so replacing one is one word
 * on one line rather than an edit to the constructor:
 *
 *     #[Overrides(logger: MyLogger::class)]
 *
 * **The cost is that a wrong class is a runtime failure**, where a wrong
 * `protected function` signature would be caught at compile time. `App` resolves
 * all three before it touches anything else, so a mistake stops the boot with a
 * message naming the class, rather than surfacing mid-request.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Overrides
{
    /** @param class-string<ProvidesSecret> */
    public function __construct(
        public readonly string $secret,
        public readonly string $logger,
        public readonly string $storage,
    ) {
    }
}