<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\Nmcli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\Nmcli\TerseLine;

final class TerseLineTest extends TestCase
{
    #[Test]
    public function it_unescapes_a_colon_inside_a_field(): void
    {
        $this->assertSame(['a:b', 'c'], TerseLine::split('a\:b:c'));
    }

    #[Test]
    public function it_unescapes_a_literal_backslash_without_swallowing_the_next_separator(): void
    {
        $this->assertSame(['Foo\\', 'x'], TerseLine::split('Foo\\\\:x'));
    }

    #[Test]
    public function it_preserves_empty_fields(): void
    {
        $this->assertSame(['a', '', 'b'], TerseLine::split('a::b'));
    }

    #[Test]
    public function a_trailing_lone_backslash_is_kept_literally(): void
    {
        $this->assertSame(['a', 'b\\'], TerseLine::split('a:b\\'));
    }
}
