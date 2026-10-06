<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use Sanchescom\WiFi\Backend\Linux\ToolPath;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandRunner;

/**
 * The name the device answers to on its network: `<hostname>.local`, which
 * `avahi-daemon` publishes by itself while it runs. Nothing is published or
 * renamed here; this only finds out whether the name can be promised.
 */
final class LocalName
{
    /** `<hostname>.local`, or null when no `avahi-daemon` is running. */
    public static function detect(CommandRunner $runner): ?string
    {
        $avahi = (new ToolPath($runner))->resolve('avahi-daemon');
        $hostname = gethostname();

        if ($avahi === null || $hostname === false || $hostname === '') {
            return null;
        }

        return $runner->run(new Command($avahi, ['--check']))->isSuccessful() ? $hostname . '.local' : null;
    }
}
