<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\WpaCli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\WpaCli\ListNetworksParser;

final class ListNetworksParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/wpacli';

    #[Test]
    public function it_parses_three_known_networks(): void
    {
        $networks = (new ListNetworksParser())->parse((string) file_get_contents(self::FIXTURES . '/ListNetworks.txt'));

        $this->assertCount(3, $networks);
    }

    #[Test]
    public function current_means_active_and_the_device_is_always_null(): void
    {
        $networks = (new ListNetworksParser())->parse((string) file_get_contents(self::FIXTURES . '/ListNetworks.txt'));

        $bell = $networks[0];
        $this->assertSame('BELL340', $bell->name);
        $this->assertSame('BELL340', $bell->ssid);
        $this->assertTrue($bell->active);
        $this->assertNull($bell->device);
    }

    #[Test]
    public function an_ssid_with_a_colon_keeps_it_intact(): void
    {
        $networks = (new ListNetworksParser())->parse((string) file_get_contents(self::FIXTURES . '/ListNetworks.txt'));

        $cafe = $networks[1];
        $this->assertSame('Cafe: Corner', $cafe->ssid);
        $this->assertFalse($cafe->active);
        $this->assertNull($cafe->device);
    }

    #[Test]
    public function disabled_is_not_active(): void
    {
        $networks = (new ListNetworksParser())->parse((string) file_get_contents(self::FIXTURES . '/ListNetworks.txt'));

        $old = $networks[2];
        $this->assertSame('OldNet', $old->ssid);
        $this->assertFalse($old->active);
        $this->assertNull($old->device);
    }
}
