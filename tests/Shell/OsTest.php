<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Test\Shell;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sanchescom\WiFi\Shell\Os;

final class OsTest extends TestCase
{
    /**
     * Os::current()'s UnsupportedOperation branch wraps self::from(PHP_OS_FAMILY),
     * which can only throw for a PHP_OS_FAMILY value outside {Windows, Linux,
     * Darwin, Solaris, BSD, Unknown} — and PHP_OS_FAMILY itself is a constant
     * fixed at build time, so the exception branch cannot be forced from a test
     * running on any real host. This test covers the enum side of that guard
     * instead: the same "no matching case" condition that current() catches.
     */
    #[Test]
    public function an_unrecognised_os_family_has_no_matching_case(): void
    {
        $this->assertNull(Os::tryFrom('BSD'));
    }
}
