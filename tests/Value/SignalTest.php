<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Value\Signal;

final class SignalTest extends TestCase
{
    #[Test]
    public function dbm_and_quality_convert_both_ways(): void
    {
        $this->assertSame(-64.0, Signal::fromQuality(72)->dbm);
        $this->assertSame(72.0, Signal::fromQuality(72)->quality);
        $this->assertSame(82.0, Signal::fromDbm(-59)->quality);
        $this->assertSame(-59.0, Signal::fromDbm(-59)->dbm);
    }

    #[Test]
    public function quality_is_clamped_to_percent(): void
    {
        $this->assertSame(100.0, Signal::fromQuality(390)->quality);
        $this->assertSame(0.0, Signal::fromQuality(-5)->quality);
        $this->assertSame(100.0, Signal::fromDbm(-20)->quality);
        $this->assertSame(0.0, Signal::fromDbm(-130)->quality);
        $this->assertSame(-130.0, Signal::fromDbm(-130)->dbm, 'dBm is never clamped');
    }

    #[Test]
    public function comparison_is_by_dbm_and_strict(): void
    {
        $this->assertTrue(Signal::fromDbm(-50)->isStrongerThan(Signal::fromDbm(-65.5)));
        $this->assertFalse(Signal::fromDbm(-50)->isStrongerThan(Signal::fromDbm(-50)));
        $this->assertTrue(Signal::fromDbm(-60)->atLeast(-60));
        $this->assertFalse(Signal::fromDbm(-61)->atLeast(-60));
    }
}
