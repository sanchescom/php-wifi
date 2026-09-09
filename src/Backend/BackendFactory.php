<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Shell\ShellCommandRunner;

/** Picks the concrete Backend for an operating system. */
final class BackendFactory
{
    public static function forOs(Os $os, CommandRunner $runner): Backend
    {
        return match ($os) {
            Os::Linux => new NmcliBackend($runner),
            Os::Darwin => new NetworksetupBackend($runner),
            Os::Windows => new NetshBackend($runner),
        };
    }

    public static function forCurrentOs(?CommandRunner $runner = null): Backend
    {
        return self::forOs(Os::current(), $runner ?? ShellCommandRunner::forCurrentOs());
    }
}
