<?php

declare(strict_types=1);

namespace App\App;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * The default logger: JSON on stderr.
 *
 * stderr rather than a file, because a log file needs rotating and cleaning and
 * nobody reads one during development. JSON rather than Monolog's line format,
 * because these lines are meant to be read by something — `jq` over a stream of
 * records beats grepping a stack trace out of prose.
 *
 * A bad `LOG_LEVEL` falls back rather than refusing to boot: it is a typo, and a
 * typo should not stop the application serving traffic.
 */
final class StderrLogger implements ProvidesLogger
{
    public function logger(): LoggerInterface
    {
        $handler = new StreamHandler(
            $_ENV['LOG_STREAM'] ?? 'php://stderr',
            $this->level(),
            bubble: true,
        );

        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, appendNewline: true));

        return new Logger('app', [$handler]);
    }

    private function level(): Level
    {
        try {
            return Level::fromName(strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'info')));
        } catch (\Throwable) {
            return Level::Info;
        }
    }
}