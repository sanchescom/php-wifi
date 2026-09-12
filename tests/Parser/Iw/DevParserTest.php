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
    public function it_returns_the_first_non_p2p_interface(): void
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

    #[Test]
    public function empty_input_returns_null(): void
    {
        $this->assertNull((new DevParser())->parse(''));
    }
}
