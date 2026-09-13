<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Shell\ShellCommandRunner;
use Throwable;

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
        $runner ??= ShellCommandRunner::forCurrentOs();
        $os = Os::current();

        return $os === Os::Linux ? self::forLinux($runner) : self::forOs($os, $runner);
    }

    /**
     * Picks between the two Linux backends by probing for NetworkManager:
     * `NmcliBackend` when it is running, `WpaCliBackend` otherwise (missing
     * binary, NetworkManager stopped, or anything else).
     *
     * The `catch (Throwable)` below is deliberately a blanket one — one of
     * the rare places that is the right call. A failed probe is itself an
     * answer ("no usable NetworkManager here"), not an error to surface: the
     * whole point of WpaCliBackend is to work on machines where nmcli cannot
     * even be invoked, so nothing the runner does (missing binary, non-zero
     * exit, or throwing outright) may propagate out of this method.
     */
    public static function forLinux(CommandRunner $runner): Backend
    {
        try {
            $result = $runner->run(new Command('nmcli', ['-t', '-f', 'RUNNING', 'general'], ['LANG' => 'C']));

            if ($result->isSuccessful() && trim($result->stdout) === 'running') {
                return new NmcliBackend($runner);
            }
        } catch (Throwable) {
            // Probing failed outright (e.g. the runner itself throws rather
            // than returning a non-zero exit). Fall through to WpaCliBackend.
        }

        return new WpaCliBackend($runner);
    }
}
