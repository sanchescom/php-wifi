<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Value\Bssid;

final class BssidTest extends TestCase
{
    #[Test]
    public function it_normalises_to_lowercase_and_compares_case_insensitively(): void
    {
        $bssid = Bssid::from('04:8D:38:22:78:9E');

        $this->assertSame('04:8d:38:22:78:9e', (string) $bssid);
        $this->assertTrue($bssid->equals('04:8d:38:22:78:9E'));
        $this->assertTrue($bssid->equals(Bssid::from('04:8d:38:22:78:9e')));
        $this->assertFalse($bssid->equals('04:8d:38:22:78:9f'));
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return ['empty' => [''], 'short' => ['04:8d:38'], 'letters' => ['zz:8d:38:22:78:9e'], 'dashes' => ['04-8d-38-22-78-9e']];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function it_rejects_malformed_addresses(string $raw): void
    {
        $this->expectException(InvalidArgument::class);
        Bssid::from($raw);
    }
}
