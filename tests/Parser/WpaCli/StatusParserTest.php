<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\WpaCli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\WpaCli\StatusParser;

final class StatusParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/wpacli';

    #[Test]
    public function it_maps_key_value_lines_including_the_keys_the_backend_needs(): void
    {
        $status = (new StatusParser())->parse((string) file_get_contents(self::FIXTURES . '/Status.txt'));

        $this->assertSame('COMPLETED', $status['wpa_state']);
        $this->assertSame('BELL340', $status['ssid']);
        $this->assertSame('0e:ac:8a:99:58:5d', $status['bssid']);
        $this->assertSame('192.168.2.75', $status['ip_address']);
    }

    #[Test]
    public function it_also_keeps_the_other_keys_the_backend_does_not_yet_use(): void
    {
        $status = (new StatusParser())->parse((string) file_get_contents(self::FIXTURES . '/Status.txt'));

        $this->assertSame('5785', $status['freq']);
        $this->assertSame('station', $status['mode']);
    }

    #[Test]
    public function empty_input_is_an_empty_map(): void
    {
        $status = (new StatusParser())->parse('');

        $this->assertSame([], $status);
    }
}
