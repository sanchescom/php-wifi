<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Iw;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Iw\StationDumpParser;

final class StationDumpParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/iw';

    #[Test]
    public function it_counts_the_station_lines(): void
    {
        $count = (new StationDumpParser())->count((string) file_get_contents(self::FIXTURES . '/StationDump.txt'));

        $this->assertSame(2, $count);
    }

    #[Test]
    public function empty_input_counts_zero(): void
    {
        $this->assertSame(0, (new StationDumpParser())->count(''));
    }
}
