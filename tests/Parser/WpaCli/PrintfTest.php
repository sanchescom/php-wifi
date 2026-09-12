<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Parser\WpaCli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Parser\WpaCli\Printf;

final class PrintfTest extends TestCase
{
    #[Test]
    public function it_decodes_lowercase_and_uppercase_hex_byte_escapes(): void
    {
        $this->assertSame("\xab", Printf::decode('\xab'));
        $this->assertSame("\xAB", Printf::decode('\xAB'));
    }

    #[Test]
    public function it_reassembles_a_multi_byte_utf8_character_from_xnn_escapes(): void
    {
        $this->assertSame('Привет', Printf::decode('\xd0\x9f\xd1\x80\xd0\xb8\xd0\xb2\xd0\xb5\xd1\x82'));
    }

    #[Test]
    public function an_xnn_escape_that_decodes_to_an_invalid_utf8_byte_survives_unchanged(): void
    {
        $this->assertSame("\xff", Printf::decode('\xff'));
    }

    #[Test]
    public function it_decodes_the_named_control_escapes(): void
    {
        $this->assertSame("\t", Printf::decode('\t'));
        $this->assertSame("\n", Printf::decode('\n'));
        $this->assertSame("\r", Printf::decode('\r'));
        $this->assertSame("\x1b", Printf::decode('\e'));
    }

    #[Test]
    public function it_decodes_escaped_quote_and_backslash(): void
    {
        $this->assertSame('"', Printf::decode('\"'));
        $this->assertSame('\\', Printf::decode('\\\\'));
    }

    #[Test]
    public function a_backslash_followed_by_an_unrecognised_character_keeps_both_as_is(): void
    {
        $this->assertSame('\z', Printf::decode('\z'));
        $this->assertSame('\9', Printf::decode('\9'));
    }

    #[Test]
    public function a_trailing_lone_backslash_is_kept(): void
    {
        $this->assertSame('\\', Printf::decode('\\'));
        $this->assertSame('a\\', Printf::decode('a\\'));
    }

    #[Test]
    public function plain_text_without_escapes_passes_through_unchanged(): void
    {
        $this->assertSame('Café', Printf::decode('Café'));
    }

    #[Test]
    public function empty_string_decodes_to_empty_string(): void
    {
        $this->assertSame('', Printf::decode(''));
    }
}
