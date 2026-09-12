<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

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
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\Value\NetworkCollection;

/**
 * Linux backend driving `wpa_supplicant` directly through `wpa_cli`, for
 * machines with no NetworkManager. `wpa_cli` takes commands as arguments,
 * which would put a PSK in `ps`; every call here instead runs
 * `wpa_cli -i <iface>` interactively and writes the command script to the
 * child's stdin, terminated by `quit` — a secret never appears in argv.
 *
 * Hotspot support ({@see SupportsHotspot}) is added in a later release; this
 * class deliberately does not declare that interface yet, so
 * `WiFi::supports()` never advertises a capability this class cannot back.
 */
final class WpaCliBackend implements Backend, SupportsKnownNetworks
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ?string $interface = null,
        private readonly int $associationAttempts = 15,
    ) {
    }

    /**
     * Triggers a scan and immediately reads back the results. Real
     * `wpa_supplicant` keeps the previous scan's results available while a
     * fresh one runs, so `scan_results` still returns useful (if possibly
     * slightly stale) data without this backend sleeping between the two
     * calls — which would only slow down every test and every caller for no
     * correctness gain.
     */
    public function scan(): NetworkCollection
    {
        $interface = $this->resolveInterface();

        $this->wpaCli($interface, ['scan']);
        $result = $this->wpaCli($interface, ['scan_results']);

        return new NetworkCollection((new ScanResultsParser())->parse($result->stdout));
    }

    /**
     * `add_network` must run as its own call: the id it returns is only
     * known once its reply is read, and a batched script cannot be read
     * mid-script. Once the id is known, `set_network`/`enable_network`/
     * `save_config` run together as one script, then `status` is polled
     * (up to `$associationAttempts` times) until `wpa_state=COMPLETED`.
     */
    public function connect(string $ssid, Credentials $credentials, Device $device): void
    {
        $interface = $device->name;
        $id = $this->addNetwork($interface);

        $lines = [sprintf('set_network %d ssid %s', $id, self::quote($ssid))];
        $secret = $credentials->password !== null;

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
        $result = $this->run(new Command('iw', ['dev']));

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
        $result = $this->wpaCli($interface, ['list_networks']);

        foreach (preg_split('/\r?\n/', $result->stdout) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode("\t", $line, 4);

            if (count($fields) < 2 || $fields[1] !== $ssidOrName) {
                continue;
            }

            $this->wpaCli($interface, [sprintf('remove_network %s', $fields[0]), 'save_config']);

            return;
        }

        throw NetworkNotFound::bySsid($ssidOrName);
    }

    private function resolveInterface(): string
    {
        return $this->interface ?? $this->detectDevice()->name;
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
     * `dhclient` (via `command -v`, never `is_executable()` — the runner is
     * the only way a test can observe the probe) and runs the first one
     * found. `dhcpcd` is special: `dhcpcd -U <iface>` succeeding means a
     * `dhcpcd` daemon already supervises this interface, so it is left
     * alone — two DHCP clients fighting over one interface is worse than
     * one running. No client present is not a failure: association already
     * succeeded, and something else (systemd-networkd, a static address)
     * may be responsible for addressing.
     */
    private function requestAddress(Device $device): void
    {
        $interface = $device->name;

        if ($this->commandExists('dhcpcd')) {
            $lease = $this->runner->run(new Command('dhcpcd', ['-U', $interface]));

            if ($lease->isSuccessful()) {
                return;
            }

            $this->run(new Command('dhcpcd', ['-n', $interface]));

            return;
        }

        if ($this->commandExists('udhcpc')) {
            $this->run(new Command('udhcpc', ['-i', $interface, '-n', '-q']));

            return;
        }

        if ($this->commandExists('dhclient')) {
            $this->run(new Command('dhclient', ['-1', $interface]));
        }
    }

    private function commandExists(string $name): bool
    {
        return $this->runner->run(new Command('command', ['-v', $name]))->isSuccessful();
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

        return $this->run(new Command('wpa_cli', ['-i', $interface], ['LANG' => 'C'], [], $stdin, $secret));
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
