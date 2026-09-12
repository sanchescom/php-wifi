<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Support;

use Sanchescom\WiFi\Watchdog\Clock;

/**
 * A Clock for tests: now() returns a settable value, and sleep() only
 * advances that value — nothing ever really waits.
 */
final class TestClock implements Clock
{
    public function __construct(private int $now = 0)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function sleep(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }
}
