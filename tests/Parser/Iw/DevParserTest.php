<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Iw;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Iw\DevParser;
use Sanchescom\WiFi\Value\Device;

final class DevParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/iw';

    #[Test]
    public function it_returns_the_managed_interface(): void
    {
        $device = (new DevParser())->parse((string) file_get_contents(self::FIXTURES . '/Dev.txt'));

        $this->assertEquals(new Device('wlan0'), $device);
    }

    #[Test]
    public function it_never_returns_the_p2p_device_interface(): void
    {
        $device = (new DevParser())->parse((string) file_get_contents(self::FIXTURES . '/Dev.txt'));

        $this->assertNotEquals(new Device('p2p-dev-wlan0'), $device);
    }

    /**
     * The fixture's phy#1 (an `AP` and a `monitor` interface) is listed
     * before phy#0's managed `wlan0` — proving the AP interface being first
     * in the output does not make DevParser pick it.
     */
    #[Test]
    public function the_managed_interface_wins_even_when_the_ap_interface_is_listed_first(): void
    {
        $device = (new DevParser())->parse((string) file_get_contents(self::FIXTURES . '/Dev.txt'));

        $this->assertEquals(new Device('wlan0'), $device);
    }

    #[Test]
    public function it_never_returns_the_ap_interface(): void
    {
        $device = (new DevParser())->parse((string) file_get_contents(self::FIXTURES . '/Dev.txt'));

        $this->assertNotEquals(new Device('ap0'), $device);
    }

    #[Test]
    public function it_never_returns_the_monitor_interface(): void
    {
        $device = (new DevParser())->parse((string) file_get_contents(self::FIXTURES . '/Dev.txt'));

        $this->assertNotEquals(new Device('mon0'), $device);
    }

    /**
     * Inverts the fixture's order (managed interface listed first, AP
     * second) to prove the "managed wins regardless of order" rule holds in
     * both directions, not just the fixture's particular arrangement.
     */
    #[Test]
    public function the_managed_interface_wins_even_when_it_is_listed_first(): void
    {
        $output = <<<'TXT'
        # SYNTHETIC: iw dev
        phy#0
        	Interface wlan0
        		ifindex 3
        		type managed
        phy#1
        	Interface ap0
        		ifindex 5
        		type AP
        	Interface mon0
        		ifindex 6
        		type monitor
        TXT;

        $device = (new DevParser())->parse($output);

        $this->assertEquals(new Device('wlan0'), $device);
    }

    #[Test]
    public function empty_input_returns_null(): void
    {
        $this->assertNull((new DevParser())->parse(''));
    }
}
