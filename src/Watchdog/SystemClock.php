<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Watchdog;

/** The real Clock: wall-clock time and an actual, blocking sleep(). */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
