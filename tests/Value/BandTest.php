<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Value\Band;

final class BandTest extends TestCase
{
    #[Test]
    public function frequency_windows(): void
    {
        $this->assertSame(Band::GHz2_4, Band::fromFrequency(2412));
        $this->assertSame(Band::GHz2_4, Band::fromFrequency(2499));
        $this->assertNull(Band::fromFrequency(2500));
        $this->assertSame(Band::GHz5, Band::fromFrequency(4900));
        $this->assertSame(Band::GHz5, Band::fromFrequency(5924));
        $this->assertSame(Band::GHz6, Band::fromFrequency(5925));
        $this->assertSame(Band::GHz6, Band::fromFrequency(6775));
        $this->assertNull(Band::fromFrequency(0));
    }

    #[Test]
    public function hints_and_channel_frequencies(): void
    {
        $this->assertSame(Band::GHz6, Band::fromHint('6'));
        $this->assertSame(Band::GHz2_4, Band::fromHint('2'));
        $this->assertNull(Band::fromHint(null));
        $this->assertNull(Band::fromHint('7'));
        $this->assertSame(2437, Band::GHz2_4->frequencyForChannel(6));
        $this->assertSame(5500, Band::GHz5->frequencyForChannel(100));
        $this->assertSame(5825, Band::GHz5->frequencyForChannel(165));
        $this->assertSame(6775, Band::GHz6->frequencyForChannel(165));
        $this->assertNull(Band::GHz2_4->frequencyForChannel(36));
        $this->assertNull(Band::GHz5->frequencyForChannel(3));
    }
}
