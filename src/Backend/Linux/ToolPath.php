<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend\Linux;

use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandRunner;

/**
 * Resolves a Linux tool's absolute path outside an unprivileged user's PATH.
 *
 * `proc_open()` resolves a bare program name using the PHP process's own
 * PATH — never the `$env` array handed to it — so passing `PATH` in a
 * {@see Command}'s env cannot make `wpa_cli`, `iw`, `ip`, `hostapd` or
 * `dnsmasq` runnable for an unprivileged user on Debian/Raspberry Pi OS,
 * where all five live in `/usr/sbin`, a directory absent from
 * `PATH=/usr/local/bin:/usr/bin:/bin:/usr/games`. This resolver instead runs
 * `which <name>` with an explicit, fixed search path that *does* include
 * the usual system directories, and hands back the absolute path `which`
 * printed. `WpaCliBackend` then builds its `Command`s with that absolute
 * path as the program, sidestepping `proc_open()`'s PATH resolution
 * entirely. `which` itself needs no resolving: it lives in `/usr/bin`,
 * which is always on PATH.
 *
 * Each tool is resolved at most once per instance: the answer — a path, or
 * `null` when the tool cannot be found — is cached for the instance's
 * lifetime.
 */
final class ToolPath
{
    private const SEARCH_PATH = '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin';

    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(private readonly CommandRunner $runner)
    {
    }

    /** Resolves and caches $name's absolute path, or null when it cannot be found. */
    public function resolve(string $name): ?string
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        $result = $this->runner->run(new Command('which', [$name], ['PATH' => self::SEARCH_PATH]));

        if (!$result->isSuccessful()) {
            return $this->cache[$name] = null;
        }

        [$firstLine] = explode("\n", $result->stdout, 2);
        $firstLine = trim($firstLine);

        return $this->cache[$name] = ($firstLine !== '' ? $firstLine : null);
    }
}
