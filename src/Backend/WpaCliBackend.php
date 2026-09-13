<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Closure;
use Throwable;
use Sanchescom\WiFi\Backend\Linux\HostapdConfig;
use Sanchescom\WiFi\Backend\Linux\ToolPath;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Parser\Iw\DevParser;
use Sanchescom\WiFi\Parser\WpaCli\ListNetworksParser;
use Sanchescom\WiFi\Parser\WpaCli\ScanResultsParser;
use Sanchescom\WiFi\Parser\WpaCli\StatusParser;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Value\Bssid;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Hotspot;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;

/**
 * Linux backend driving `wpa_supplicant` directly through `wpa_cli`, for
 * machines with no NetworkManager. `wpa_cli` takes commands as arguments,
 * which would put a PSK in `ps`; every call here instead runs
 * `wpa_cli -i <iface>` interactively and writes the command script to the
 * child's stdin, terminated by `quit` — a secret never appears in argv.
 *
 * The hotspot ({@see SupportsHotspot}) is raised through `hostapd` and
 * `dnsmasq` rather than `wpa_supplicant`, so its two pid files live at a
 * fixed, derivable path (the system temp directory plus a fixed file name)
 * instead of an instance property: a second `WpaCliBackend` instance — the
 * CLI's `wifi hotspot status`, or the watchdog, running in a different
 * process from the one that called {@see self::startHotspot()} — discovers
 * a running hotspot through those same two files.
 *
 * Every tool this backend spawns from a system directory — `wpa_cli`, `iw`,
 * `ip`, `hostapd`, `dnsmasq`, and the DHCP clients probed by
 * {@see self::requestAddress()} — is resolved to its absolute path through
 * {@see ToolPath} before it is run, because `proc_open()` resolves a bare
 * program name using PHP's own PATH, not the `$env` array handed to a
 * {@see Command}; on Debian/Raspberry Pi OS all five live in `/usr/sbin`,
 * which is absent from an unprivileged user's PATH. `which`, `ps` and
 * `kill` are the only programs this backend spawns as bare names: all three
 * live in `/usr/bin` or `/bin`, which are always on PATH, so they need no
 * resolving.
 */
final class WpaCliBackend implements Backend, SupportsKnownNetworks, SupportsHotspot
{
    private const HOSTAPD_PID_FILE = 'php-wifi-hostapd.pid';

    private const DNSMASQ_PID_FILE = 'php-wifi-dnsmasq.pid';

    private const HOSTAPD_BINARY = 'hostapd';

    private const DNSMASQ_BINARY = 'dnsmasq';

    private const HOTSPOT_ADDRESS = '10.42.0.1/24';

    private const DHCP_RANGE = '10.42.0.10,10.42.0.100,12h';

    private readonly ToolPath $toolPath;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * $scanAttempts/$scanPollIntervalMicroseconds and
     * $associationAttempts/$associationPollIntervalMicroseconds bound how
     * long {@see self::scan()} and {@see self::awaitAssociation()} each
     * wait (see their own docblocks for the reasoning behind the numbers).
     * $sleep is the one seam both polls pause through, and the seam that
     * makes the wait fake in tests: it defaults to a real, blocking
     * `usleep()`, but any test can hand in a no-op (or recording) closure
     * instead, so the suite never actually waits.
     *
     * @param Closure(int): void|null $sleep called with a microsecond count
     *        between polls (scan results or association status); defaults
     *        to a real `usleep()`
     */
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ?string $interface = null,
        private readonly int $associationAttempts = 15,
        private readonly int $scanAttempts = 11,
        private readonly int $scanPollIntervalMicroseconds = 500_000,
        private readonly int $associationPollIntervalMicroseconds = 1_500_000,
        ?Closure $sleep = null,
    ) {
        $this->toolPath = new ToolPath($runner);
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Triggers a scan, then polls `scan_results` until it reports at least
     * one network, or the attempt budget runs out.
     *
     * A freshly started `wpa_supplicant` — the normal state on a
     * NetworkManager-free device right after boot — is still
     * `wpa_state=SCANNING` for a few seconds after `scan` is sent, with an
     * empty results table until the scan finishes; reading `scan_results`
     * only once, immediately, made every SSID look absent on real hardware
     * (the defect this method fixes). So this polls instead of reading
     * once, sleeping {@see self::$scanPollIntervalMicroseconds} between
     * attempts through the injectable {@see self::$sleep}, up to
     * {@see self::$scanAttempts} times.
     *
     * Defaults: 11 attempts, 500ms apart, i.e. 10 pauses = 5.0 seconds of
     * total wait before giving up — close to the multi-second scan latency
     * observed on the Pi that reported this defect, while still being a
     * small, bounded number of `wpa_cli` invocations (11, not a tight
     * sub-100ms poll that would spawn it dozens of times per call).
     *
     * Exhausting the budget is not an error: it returns whatever the last
     * read gave, even an empty collection — a genuinely empty area has no
     * networks, and that is a valid answer for `scan()` to give. It is
     * {@see self::connect()} that turns "not found" into
     * {@see NetworkNotFound}, and that stays correct behaviour.
     *
     * Once the rows are in hand, {@see self::markConnectedNetwork()} makes
     * one further `status` call to find out which of them (if any) is the
     * network this interface is actually joined to — `scan_results` itself
     * never says.
     */
    public function scan(): NetworkCollection
    {
        $interface = $this->resolveInterface();

        $this->wpaCli($interface, ['scan']);

        $networks = [];

        for ($attempt = 0; $attempt < $this->scanAttempts; $attempt++) {
            $result = $this->wpaCli($interface, ['scan_results']);
            $networks = (new ScanResultsParser())->parse($result->stdout);

            if ($networks !== []) {
                break;
            }

            if ($attempt < $this->scanAttempts - 1) {
                ($this->sleep)($this->scanPollIntervalMicroseconds);
            }
        }

        return new NetworkCollection($this->markConnectedNetwork($networks, $interface));
    }

    /**
     * `scan_results` carries no "this one is connected" column ({@see
     * ScanResultsParser}), so the only way to know which row is the network
     * this interface actually joined is a separate `status` call, matched
     * against the rows already parsed.
     *
     * Matching prefers BSSID over SSID: a BSSID is the access point's own
     * hardware address, so it is exact and unambiguous, whereas two rows
     * can legitimately share one SSID (two access points of the same mesh,
     * or two unrelated neighbours who happened to pick the same name) —
     * matching by SSID alone could then mark the wrong one. `status` only
     * omits `bssid` when it also omits `ssid` (i.e. when idle), so falling
     * back to SSID never actually loses precision in practice; it is kept
     * only because the interface contract allows it.
     *
     * When `status` reports no association at all (`wpa_state=INACTIVE`,
     * neither `ssid=` nor `bssid=` printed) every row is returned exactly
     * as `scan_results` produced it, still `connected: false`. The same
     * happens when the `status` call itself fails: a scan that returns
     * unmarked rows is more useful than no scan at all, so that one
     * failure is swallowed here instead of failing the whole call.
     *
     * @param list<Network> $networks
     * @return list<Network>
     */
    private function markConnectedNetwork(array $networks, string $interface): array
    {
        try {
            $result = $this->wpaCli($interface, ['status']);
        } catch (CommandFailed) {
            return $networks;
        }

        $status = (new StatusParser())->parse($result->stdout);
        $bssid = isset($status['bssid']) ? Bssid::tryFrom($status['bssid']) : null;
        $ssid = $status['ssid'] ?? null;

        if ($bssid === null && $ssid === null) {
            return $networks;
        }

        return array_map(
            static fn (Network $network): Network => self::isAssociatedNetwork($network, $bssid, $ssid)
                ? $network->withConnected(true)
                : $network,
            $networks,
        );
    }

    private static function isAssociatedNetwork(Network $network, ?Bssid $bssid, ?string $ssid): bool
    {
        if ($bssid !== null) {
            return $network->bssid?->equals($bssid) === true;
        }

        return $network->ssid === $ssid;
    }

    /**
     * `add_network` must run as its own call: the id it returns is only
     * known once its reply is read, and a batched script cannot be read
     * mid-script. Once the id is known, `set_network`/`enable_network`/
     * `save_config` run together as one script, then `status` is polled
     * (up to `$associationAttempts` times, `$associationPollIntervalMicroseconds`
     * apart — see {@see self::awaitAssociation()}) until `wpa_state=COMPLETED`.
     *
     * Two defects fixed here both come from the same root cause —
     * `wpa_supplicant` has no notion of "the" block for an SSID, only
     * however many `add_network` happened to create — and both are handled
     * by consulting `list_networks` before touching anything:
     *
     * - No passphrase (the watchdog's rejoin path): unconditionally adding a
     *   fresh open-network (`key_mgmt NONE`) block for an SSID that already
     *   has one — almost always the WPA2 block a previous {@see
     *   self::connect()} with a real passphrase created — can never
     *   associate, and the junk block is persisted by `save_config` on every
     *   retry. So an existing block for $ssid is `select_network`ed instead:
     *   one command both disables every other block and selects this one,
     *   which is exactly "rejoin the network already configured under this
     *   id" — cheaper than `enable_network` (which would not depose an
     *   already-selected different block) followed by a separate
     *   `reconnect`. Only when no block exists at all does this fall back to
     *   today's add-with-`key_mgmt NONE`, which is correct for a genuinely
     *   open network.
     * - A passphrase supplied: every existing block for $ssid is removed
     *   (`remove_network`, in the order `list_networks` printed them) before
     *   a new one is added, so a stale, wrong block from an earlier typo can
     *   never coexist with the corrected one and win the race to associate.
     *   These removals are not `save_config`d on their own — the
     *   `save_config` already at the end of this method, after the new block
     *   is added and enabled, persists the removals and the addition
     *   together in one write.
     */
    public function connect(string $ssid, Credentials $credentials, Device $device): void
    {
        $interface = $device->name;
        $secret = $credentials->password !== null;
        $existingIds = $this->networkIdsBySsid($interface, $ssid);

        if (!$secret && $existingIds !== []) {
            $this->wpaCli($interface, [sprintf('select_network %s', $existingIds[0])]);

            $this->awaitAssociation($interface);

            $this->requestAddress($device);

            return;
        }

        foreach ($existingIds as $existingId) {
            $this->wpaCli($interface, [sprintf('remove_network %s', $existingId)]);
        }

        $id = $this->addNetwork($interface);

        $lines = [sprintf('set_network %d ssid %s', $id, self::quote($ssid))];

        if ($secret) {
            /** @var string $password */
            $password = $credentials->password;
            $lines[] = sprintf('set_network %d psk %s', $id, self::quote($password));
        } else {
            $lines[] = sprintf('set_network %d key_mgmt NONE', $id);
        }

        $lines[] = sprintf('enable_network %d', $id);
        $lines[] = 'save_config';

        $this->wpaCli($interface, $lines, $secret);

        $this->awaitAssociation($interface);

        $this->requestAddress($device);
    }

    public function disconnect(Device $device): void
    {
        $this->wpaCli($device->name, ['disconnect']);
    }

    public function detectDevice(): Device
    {
        $result = $this->run(new Command($this->resolvedPath('iw'), ['dev']));

        return (new DevParser())->parse($result->stdout)
            ?? throw DeviceNotFound::onThisSystem('iw dev listed no wireless interface');
    }

    /** @return list<KnownNetwork> */
    public function knownNetworks(): array
    {
        $interface = $this->resolveInterface();
        $result = $this->wpaCli($interface, ['list_networks']);

        return (new ListNetworksParser())->parse($result->stdout);
    }

    public function forget(string $ssidOrName): void
    {
        $interface = $this->resolveInterface();
        $ids = $this->networkIdsBySsid($interface, $ssidOrName);

        if ($ids === []) {
            throw NetworkNotFound::bySsid($ssidOrName);
        }

        // Every block, not just the first: installs from before connect()
        // started removing duplicates may already carry several.
        $lines = array_map(static fn (string $id): string => sprintf('remove_network %s', $id), $ids);
        $lines[] = 'save_config';

        $this->wpaCli($interface, $lines);
    }

    /**
     * Runs `list_networks` and picks out the id column ($fields[0]) of every
     * row whose ssid column matches $ssid exactly as `list_networks` printed
     * it (not decoded through {@see \Sanchescom\WiFi\Parser\WpaCli\Printf},
     * unlike {@see ListNetworksParser}) — the one thing that parser, built
     * for the public {@see self::knownNetworks()} shape, does not expose,
     * and the one thing {@see self::forget()} and {@see self::connect()}
     * both need in order to name a specific block rather than merely
     * describe one.
     *
     * @return list<string> network ids, in the order `list_networks` printed
     *         them
     */
    private function networkIdsBySsid(string $interface, string $ssid): array
    {
        $result = $this->wpaCli($interface, ['list_networks']);
        $ids = [];

        foreach (preg_split('/\r?\n/', $result->stdout) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode("\t", $line, 4);

            if (count($fields) < 2 || $fields[1] !== $ssid) {
                continue;
            }

            $ids[] = $fields[0];
        }

        return $ids;
    }

    /**
     * Releases the interface from `wpa_supplicant` (stdin `disconnect`),
     * addresses it, then raises `hostapd` and `dnsmasq` on it. The
     * `HostapdConfig` file is the only place the passphrase ever exists in
     * clear text; it is deleted in a `finally` regardless of outcome —
     * `hostapd` daemonises (`-B`), so by the time it has started it has
     * already read the file. If `hostapd` fails, `dnsmasq` is never
     * reached, and the config file is still deleted.
     *
     * `hostapd -B` has already daemonised — and written its pid file — by
     * the time `dnsmasq` is started, so a `dnsmasq` that fails to start
     * (port 53 already bound by a system `dnsmasq` or `systemd-resolved` is
     * the ordinary case) would otherwise leave `hostapd` running with
     * nothing able to stop it: `isHotspotActive()` only reports a hotspot
     * once *both* pid files match a live process, so it would report false,
     * and a watchdog polling it would re-enter recovery and start another
     * `hostapd` every interval while the radio stayed an access point.
     * $hostapdStarted, set true only once the `hostapd` {@see Command} has
     * actually returned, guards a rollback in the `catch` below: anything
     * that fails afterwards (today, only `dnsmasq`) kills that `hostapd` by
     * its pid file — {@see self::killPidFile()}, the same helper {@see
     * self::stopHotspot()} uses, rather than the whole of `stopHotspot()`
     * itself, which would also flush the address and re-resolve the
     * interface (via `detectDevice()`, which can itself throw while the
     * radio is mid-failure) — before the original exception propagates.
     */
    public function startHotspot(HotspotConfig $config, Device $device): Hotspot
    {
        $this->guardAgainstAlreadyRunningHotspot();

        $interface = $device->name;
        $hostapdConfig = new HostapdConfig($interface, $config);
        $confFile = $hostapdConfig->create();
        $hostapdStarted = false;

        try {
            $this->wpaCli($interface, ['disconnect']);

            $ip = $this->resolvedPath('ip');
            $this->run(new Command($ip, ['addr', 'flush', 'dev', $interface]));
            $this->run(new Command($ip, ['addr', 'add', self::HOTSPOT_ADDRESS, 'dev', $interface]));
            $this->run(new Command($ip, ['link', 'set', $interface, 'up']));

            $this->run(new Command(
                $this->resolvedPath(self::HOSTAPD_BINARY),
                ['-B', '-P', $this->hostapdPidFile(), $confFile],
            ));
            $hostapdStarted = true;

            $this->run(new Command($this->resolvedPath(self::DNSMASQ_BINARY), [
                '--interface=' . $interface,
                '--bind-interfaces',
                '--except-interface=lo',
                '--dhcp-range=' . self::DHCP_RANGE,
                '--pid-file=' . $this->dnsmasqPidFile(),
            ]));
        } catch (Throwable $exception) {
            if ($hostapdStarted) {
                $this->killPidFile($this->hostapdPidFile(), self::HOSTAPD_BINARY);
            }

            throw $exception;
        } finally {
            $hostapdConfig->delete();
        }

        return new Hotspot('hostapd', $config->ssid, $device);
    }

    /**
     * Terminates both daemons, flushes the address off the interface and
     * hands the radio back to `wpa_supplicant` (stdin `reconnect`). Every
     * step tolerates "already gone" — a missing pid file is skipped, a
     * `kill` of an already-dead pid is not treated as failure, and the
     * trailing `reconnect` swallows a {@see CommandFailed} the same way:
     * "no `wpa_supplicant` is running" is the routine reply on a
     * hostapd-only box that never had one, and by this point both daemons
     * are already dead and the address already flushed, so a `wpa_cli`
     * complaint at the very last step must not make this method itself
     * throw. Calling this twice in a row is harmless. A pid file whose pid
     * names a different process than expected is never signalled (see
     * {@see self::killPidFile()}); its stale file is still removed.
     */
    public function stopHotspot(): void
    {
        $interface = $this->resolveInterface();

        $this->killPidFile($this->hostapdPidFile(), self::HOSTAPD_BINARY);
        $this->killPidFile($this->dnsmasqPidFile(), self::DNSMASQ_BINARY);

        $this->run(new Command($this->resolvedPath('ip'), ['addr', 'flush', 'dev', $interface]));

        try {
            $this->wpaCli($interface, ['reconnect']);
        } catch (CommandFailed) {
            // Routine when no wpa_supplicant is running; both daemons are
            // already gone and the address already flushed.
        }
    }

    /**
     * True only when both pid files exist, each holds a bare numeric pid,
     * that pid is alive, and it names the expected binary (`hostapd` /
     * `dnsmasq`) — checked through the {@see CommandRunner} (`ps -p <pid>
     * -o comm=`), never `posix_kill`, so tests can drive it. A pid that is
     * alive but names something else is a stale file left behind by a
     * daemon that crashed without cleaning up, whose number the kernel
     * later reused for an unrelated process; that pid file is dropped here
     * (see {@see self::pidMatches()}) so it stops looking like a hotspot.
     */
    public function isHotspotActive(): bool
    {
        // Both are evaluated deliberately, without short-circuiting: each
        // call also drops its own pid file when the pid has been reused by
        // an unrelated process, and a stale dnsmasq file must self-heal
        // even when the hostapd one already told us there is no hotspot.
        $hostapd = $this->pidMatches($this->hostapdPidFile(), self::HOSTAPD_BINARY);
        $dnsmasq = $this->pidMatches($this->dnsmasqPidFile(), self::DNSMASQ_BINARY);

        return $hostapd && $dnsmasq;
    }

    private function hostapdPidFile(): string
    {
        return sys_get_temp_dir() . '/' . self::HOSTAPD_PID_FILE;
    }

    private function dnsmasqPidFile(): string
    {
        return sys_get_temp_dir() . '/' . self::DNSMASQ_PID_FILE;
    }

    /**
     * Throws when both pid files already name live, matching processes —
     * i.e. {@see self::isHotspotActive()} is true — before this method (or
     * any of its callers) has run a single `wpa_cli`, `ip` or `hostapd`
     * command.
     *
     * This narrows the window in which a second start could overwrite the
     * first daemon's pid file; it does not close it. Two callers can still
     * both read "no hotspot" before either starts one, because the check
     * and the start are not atomic. Closing that gap needs a lock (an
     * `flock`ed file around the whole start), which 3.2 does not attempt:
     * the realistic caller is one CLI invocation or one watchdog process
     * per device.
     *
     * @throws CommandFailed when a hotspot is already running
     */
    private function guardAgainstAlreadyRunningHotspot(): void
    {
        if (!$this->isHotspotActive()) {
            return;
        }

        throw new CommandFailed(
            new Command('hostapd', ['-B', '-P', $this->hostapdPidFile()]),
            new CommandResult(1, '', ''),
            sprintf(
                'A hotspot is already running (pid files: %s, %s); refusing to start a second one.',
                $this->hostapdPidFile(),
                $this->dnsmasqPidFile(),
            ),
        );
    }

    /**
     * True when the pid file holds a live pid whose process name (basename
     * of `ps -p <pid> -o comm=`, which may be truncated or path-free)
     * matches $expectedBinary. A pid that is alive but names something
     * else is not ours — never signalled — and its now-stale pid file is
     * unlinked here so it self-heals instead of permanently mimicking a
     * running hotspot.
     */
    private function pidMatches(string $pidFile, string $expectedBinary): bool
    {
        $pid = $this->readPid($pidFile);

        if ($pid === null) {
            return false;
        }

        $name = $this->processName($pid);

        if ($name === $expectedBinary) {
            return true;
        }

        if ($name !== '') {
            unlink($pidFile);
        }

        return false;
    }

    /**
     * Kills the pid a pid file names, but only when that pid is alive and
     * names $expectedBinary — a stale file whose pid was reused by an
     * unrelated process must never be signalled. The pid file is removed
     * unconditionally afterwards, matched, mismatched, or already gone.
     */
    private function killPidFile(string $pidFile, string $expectedBinary): void
    {
        $pid = $this->readPid($pidFile);

        if ($pid !== null && $this->processName($pid) === $expectedBinary) {
            $this->runner->run(new Command('kill', [$pid]));
        }

        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }

    /** @return string the basename of `ps -p <pid> -o comm=`'s output, or '' when $pid is not alive */
    private function processName(string $pid): string
    {
        $result = $this->runner->run(new Command('ps', ['-p', $pid, '-o', 'comm=']));

        if (!$result->isSuccessful()) {
            return '';
        }

        return basename(trim($result->stdout));
    }

    private function readPid(string $pidFile): ?string
    {
        if (!file_exists($pidFile)) {
            return null;
        }

        $contents = trim((string) file_get_contents($pidFile));

        return preg_match('/^\d+$/', $contents) === 1 ? $contents : null;
    }

    private function resolveInterface(): string
    {
        return $this->interface ?? $this->detectDevice()->name;
    }

    /**
     * Resolves $name to its absolute path through {@see ToolPath}, for a
     * tool that is required for the calling operation to proceed at all —
     * unlike the DHCP clients probed by {@see self::requestAddress()},
     * where an unresolved tool just means "try the next one".
     *
     * @throws CommandFailed when $name cannot be found in PATH or in any of
     *         the usual system directories
     */
    private function resolvedPath(string $name): string
    {
        return $this->toolPath->resolve($name) ?? throw new CommandFailed(
            new Command($name),
            new CommandResult(127, '', ''),
            sprintf(
                '"%s" was not found in PATH or in the usual system directories '
                . '(/usr/local/sbin, /usr/sbin, /sbin, /usr/local/bin, /usr/bin, /bin).',
                $name,
            ),
        );
    }

    /**
     * `add_network` prints the new network id on its own line, possibly
     * preceded or followed by other banner/prompt text — the id is the last
     * line that is purely numeric. {@see self::run()} already rejects a
     * `FAIL` reply before this method ever sees the output, so the fallback
     * exception below only guards against an id-less success reply.
     */
    private function addNetwork(string $interface): int
    {
        $result = $this->wpaCli($interface, ['add_network']);

        $id = null;

        foreach (preg_split('/\r?\n/', $result->stdout) ?: [] as $line) {
            if (preg_match('/^\d+$/', trim($line)) === 1) {
                $id = (int) trim($line);
            }
        }

        if ($id === null) {
            throw CommandFailed::despiteZeroExit(
                new Command('wpa_cli', ['-i', $interface], ['LANG' => 'C']),
                $result,
                Os::Linux,
            );
        }

        return $id;
    }

    /**
     * Polls `status` for `wpa_state=COMPLETED`, pausing
     * {@see self::$associationPollIntervalMicroseconds} between attempts
     * through the injectable {@see self::$sleep} — never after a
     * successful read, and never after the last attempt.
     *
     * Reading `status` in a tight loop with no pause at all completed all
     * `$associationAttempts` reads in milliseconds on real hardware, so
     * every attempt saw the same still-associating state and the call
     * always failed even though the network had, in fact, already been
     * added and selected (`wifi known` showed it `Active`) — association
     * was simply still running. `wpa_supplicant` association typically
     * finishes in a few seconds, but a cold radio that must scan first (the
     * same cold-start condition {@see self::scan()} works around) can take
     * longer, so the defaults budget roughly 20 seconds: 15 attempts, 1.5s
     * apart, is 14 pauses = 21.0 seconds of total wait before giving up.
     *
     * Exhausting the budget keeps its existing meaning: a {@see
     * CommandFailed} naming the last observed `wpa_state`.
     */
    private function awaitAssociation(string $interface): void
    {
        $lastState = 'UNKNOWN';
        $lastResult = new CommandResult(0, '', '');

        for ($attempt = 0; $attempt < $this->associationAttempts; $attempt++) {
            $lastResult = $this->wpaCli($interface, ['status']);
            $status = (new StatusParser())->parse($lastResult->stdout);
            $lastState = $status['wpa_state'] ?? $lastState;

            if ($lastState === 'COMPLETED') {
                return;
            }

            if ($attempt < $this->associationAttempts - 1) {
                ($this->sleep)($this->associationPollIntervalMicroseconds);
            }
        }

        throw new CommandFailed(
            new Command('wpa_cli', ['-i', $interface], ['LANG' => 'C']),
            $lastResult,
            sprintf(
                'wpa_cli -i %s did not reach wpa_state=COMPLETED within %d attempt(s); last state: %s',
                $interface,
                $this->associationAttempts,
                $lastState,
            ),
        );
    }

    /**
     * `wpa_supplicant` only associates; nothing else hands out an address on
     * a machine without NetworkManager. Probes `dhcpcd`, `udhcpc`, then
     * `dhclient` — via {@see ToolPath}, the same `which`-based resolver
     * every other tool in this backend goes through, never
     * `is_executable()` — and runs the first one found. Unlike every other
     * call to {@see self::resolvedPath()} in this class, a DHCP client that
     * cannot be resolved is not an error here: it just means "try the next
     * one", and no client present at all is not a failure either —
     * association already succeeded, and something else
     * (systemd-networkd, a static address) may be responsible for
     * addressing. `dhcpcd` is special: `dhcpcd -U <iface>` succeeding means
     * a `dhcpcd` daemon already supervises this interface, so it is left
     * alone — two DHCP clients fighting over one interface is worse than
     * one running.
     */
    private function requestAddress(Device $device): void
    {
        $interface = $device->name;

        $dhcpcd = $this->toolPath->resolve('dhcpcd');

        if ($dhcpcd !== null) {
            $lease = $this->runner->run(new Command($dhcpcd, ['-U', $interface]));

            if ($lease->isSuccessful()) {
                return;
            }

            $this->run(new Command($dhcpcd, ['-n', $interface]));

            return;
        }

        $udhcpc = $this->toolPath->resolve('udhcpc');

        if ($udhcpc !== null) {
            $this->run(new Command($udhcpc, ['-i', $interface, '-n', '-q']));

            return;
        }

        $dhclient = $this->toolPath->resolve('dhclient');

        if ($dhclient !== null) {
            $this->run(new Command($dhclient, ['-1', $interface]));
        }
    }

    /**
     * wpa_supplicant's `ssid`/`psk` network-block values are C-style quoted
     * strings: a literal `"` or `\` inside the value must be backslash
     * escaped, in that order (escaping `\` first, then `"`, so the
     * backslash introduced by quote-escaping is not itself re-escaped).
     */
    private static function quote(string $value): string
    {
        $escaped = str_replace('\\', '\\\\', $value);
        $escaped = str_replace('"', '\\"', $escaped);

        return '"' . $escaped . '"';
    }

    /**
     * @param list<string> $lines sent over stdin, each on its own line, the
     *        script always terminated by `quit`
     */
    private function wpaCli(string $interface, array $lines, bool $secret = false): CommandResult
    {
        $stdin = implode("\n", [...$lines, 'quit']) . "\n";

        return $this->run(new Command(
            $this->resolvedPath('wpa_cli'),
            ['-i', $interface],
            ['LANG' => 'C'],
            [],
            $stdin,
            $secret,
        ));
    }

    /**
     * `wpa_cli` exits 0 in several failure cases — exactly like
     * `networksetup` on macOS ({@see NetworksetupBackend}) — so the reply
     * text has to be inspected regardless of the exit code. `Permission
     * denied` is checked first because it can appear on either a zero or a
     * non-zero exit.
     */
    private function run(Command $command): CommandResult
    {
        $result = $this->runner->run($command);
        $output = $result->stdout . "\n" . $result->stderr;

        if (str_contains($output, 'Permission denied')) {
            throw PermissionDenied::fromWpaCli($command, $result);
        }

        if (!$result->isSuccessful()) {
            throw CommandFailed::fromResult($command, $result, Os::Linux);
        }

        if (self::looksLikeWpaCliFailure($output)) {
            throw CommandFailed::despiteZeroExit($command, $result, Os::Linux);
        }

        return $result;
    }

    private static function looksLikeWpaCliFailure(string $output): bool
    {
        return preg_match('/^FAIL(?:-\S+)?\s*$/m', $output) === 1
            || str_contains($output, 'Failed to connect to non-global ctrl_ifname');
    }
}
