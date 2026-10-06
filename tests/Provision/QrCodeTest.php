<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Provision;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Provision\QrCode;
use Sanchescom\WiFi\Test\Support\FakeCommandRunner;

final class QrCodeTest extends TestCase
{
    #[Test]
    public function the_payload_is_the_wifi_uri_phones_understand(): void
    {
        $this->assertSame('WIFI:T:WPA;S:femus-setup;P:password1;;', QrCode::payload('femus-setup', 'password1'));
    }

    /** `;` ends a field and `:` separates key from value; unescaped, a passphrase containing one would be cut short. */
    #[Test]
    public function the_separators_are_escaped(): void
    {
        $this->assertSame(
            'WIFI:T:WPA;S:a\;b\\:c;P:p\\\\q\\,r\\"s;;',
            QrCode::payload('a;b:c', 'p\\q,r"s'),
        );
    }

    #[Test]
    public function the_passphrase_reaches_qrencode_on_stdin_and_in_no_argument(): void
    {
        $runner = new FakeCommandRunner([
            'which qrencode' => "/usr/bin/qrencode\n",
            '/usr/bin/qrencode' => "█▀▀█\n",
        ]);

        $this->assertSame("█▀▀█\n", QrCode::render($runner, 'femus-setup', 'password1'));

        $command = $runner->last();
        $this->assertSame(['-t', 'ANSIUTF8', '-o', '-'], $command->arguments);
        $this->assertSame('WIFI:T:WPA;S:femus-setup;P:password1;;', $command->stdin);
        $this->assertTrue($command->stdinIsSecret);
    }

    #[Test]
    public function without_qrencode_there_is_no_code(): void
    {
        $runner = new FakeCommandRunner(['which qrencode' => ['output' => '', 'exit' => 1]]);

        $this->assertNull(QrCode::render($runner, 'femus-setup', 'password1'));
        $this->assertCount(1, $runner->commands);
    }
}
