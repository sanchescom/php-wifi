<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Watchdog;

/**
 * The Watchdog's only source of time. tick() calls now() but never sleeps —
 * run() is the sole caller of sleep() — which is what lets tests exercise
 * the whole decision table through a fake Clock, with no real waiting.
 */
interface Clock
{
    /** Unix timestamp, in seconds. */
    public function now(): int;

    public function sleep(int $seconds): void;
}
