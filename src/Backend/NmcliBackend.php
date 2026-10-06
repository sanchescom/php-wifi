<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\NoAddress;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Exception\WiFiException;
use Sanchescom\WiFi\Exception\WrongPassphrase;
use Sanchescom\WiFi\Parser\Nmcli\ConnectionListParser;
use Sanchescom\WiFi\Parser\Nmcli\DeviceListParser;
use Sanchescom\WiFi\Parser\Nmcli\ListParser;
use Sanchescom\WiFi\Parser\Nmcli\TerseLine;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Value\Band;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Hotspot;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\Value\NetworkCollection;

/**
 * Linux backend driving NetworkManager through `nmcli`. Every invocation
 * carries `LANG=C` so parser output stays locale-independent.
 */
final class NmcliBackend implements Backend, SupportsKnownNetworks, SupportsHotspot
{
    private const HOTSPOT_CONNECTION_NAME = 'Hotspot';

    public function __construct(private readonly CommandRunner $runner)
    {
    }

    public function scan(): NetworkCollection
    {
        $command = new Command(
            'nmcli',
            [
                '--terse',
                '--fields',
                'active,ssid,bssid,mode,chan,freq,signal,security,wpa-flags,rsn-flags',
                'device',
                'wifi',
                'list',
            ],
            ['LANG' => 'C'],
        );

        $result = $this->run($command);

        return new NetworkCollection((new ListParser())->parse($result->stdout));
    }

    /**
     * With a passphrase, the secret goes on stdin, never in argv: `--ask`
     * makes `nmcli` prompt for the missing secret on its standard input,
     * which is where {@see Command::$stdin} (marked
     * {@see Command::$stdinIsSecret}) delivers it — no `password` argument
     * is ever built. `Credentials::none()` keeps the exact command this
     * backend has always run: no `--ask`, no stdin.
     *
     * Measured on real hardware: `--ask` only prompts when nmcli has no
     * secret of its own already. A saved profile for this SSID that already
     * holds a (possibly stale) passphrase makes nmcli connect with that
     * stored secret and ignore whatever arrives on stdin — a freshly typed,
     * corrected passphrase would then silently never take effect. So a
     * passphrase here first deletes any existing Wi-Fi profile named $ssid
     * ({@see self::deleteWifiProfiles()}); usually there is none, and a
     * failure of that step is ignored — it must never throw. This also
     * drops any other setting that profile held (static IP, autoconnect),
     * which is the price of honouring a freshly supplied passphrase over a
     * stale saved one.
     */
    public function connect(string $ssid, Credentials $credentials, Device $device): void
    {
        $arguments = ['-w', '10'];
        $stdin = null;
        $stdinIsSecret = false;

        if ($credentials->password !== null) {
            $this->deleteWifiProfiles($ssid);

            $arguments[] = '--ask';
            $stdin = $credentials->password . "\n";
            $stdinIsSecret = true;
        }

        $arguments = [...$arguments, 'device', 'wifi', 'connect', $ssid, 'ifname', $device->name];

        try {
            $this->run(new Command('nmcli', $arguments, ['LANG' => 'C'], [], $stdin, $stdinIsSecret));
        } catch (PermissionDenied $exception) {
            throw $exception;
        } catch (CommandFailed $exception) {
            throw self::connectFailure($ssid, $exception);
        }
    }

    /**
     * Names the reason a join failed, where `nmcli` gives one away:
     *
     * - "No network with SSID … found" is {@see NetworkNotFound}.
     * - A wrong passphrase has no message of its own. NetworkManager asks for
     *   the secret a second time ("Passwords or encryption keys are required
     *   …" — the first request is worded differently) and `nmcli` then runs
     *   into its `-w` timeout and exits 3 (measured on the Pi). The second
     *   request, or "Secrets were required, but not provided", is read as
     *   {@see WrongPassphrase}.
     * - "IP configuration could not be reserved" is {@see NoAddress}.
     *
     * Anything else stays the {@see CommandFailed} it was.
     */
    private static function connectFailure(string $ssid, CommandFailed $exception): WiFiException
    {
        $output = $exception->result->stdout . "\n" . $exception->result->stderr;

        if (str_contains($output, 'No network with SSID')) {
            return NetworkNotFound::bySsid($ssid);
        }

        if (
            str_contains($output, 'Passwords or encryption keys are required')
            || str_contains($output, 'Secrets were required, but not provided')
        ) {
            return WrongPassphrase::forNetwork($ssid, $exception->command, $exception->result);
        }

        if (str_contains($output, 'IP configuration could not be reserved')) {
            return NoAddress::forNetwork($ssid, $exception->command, $exception->result);
        }

        return $exception;
    }

    public function disconnect(Device $device): void
    {
        $this->run(new Command('nmcli', ['device', 'disconnect', $device->name], ['LANG' => 'C']));
    }

    public function detectDevice(): Device
    {
        $command = new Command('nmcli', ['-t', '-f', 'DEVICE,TYPE', 'device'], ['LANG' => 'C']);
        $result = $this->run($command);

        return (new DeviceListParser())->parse($result->stdout)
            ?? throw DeviceNotFound::onThisSystem('nmcli reported no Wi-Fi device');
    }

    /** @return list<KnownNetwork> */
    public function knownNetworks(): array
    {
        $command = new Command(
            'nmcli',
            ['-t', '-f', 'NAME,TYPE,DEVICE,ACTIVE', 'connection', 'show'],
            ['LANG' => 'C'],
        );

        $result = $this->run($command);

        return array_map(
            fn (KnownNetwork $known): KnownNetwork => new KnownNetwork(
                $known->name,
                $this->resolveSsid($known->name) ?? $known->ssid,
                $known->device,
                $known->active,
            ),
            (new ConnectionListParser())->parse($result->stdout),
        );
    }

    /**
     * The list mode of `nmcli connection show` cannot emit 802-11-wireless.ssid; one call per profile can.
     * Called once per profile from knownNetworks(), this makes that method cost N+1 nmcli commands for N profiles.
     */
    private function resolveSsid(string $name): ?string
    {
        $command = new Command(
            'nmcli',
            ['-g', '802-11-wireless.ssid', 'connection', 'show', $name],
            ['LANG' => 'C'],
        );

        try {
            $ssid = trim($this->run($command)->stdout);
        } catch (PermissionDenied $exception) {
            throw $exception;
        } catch (CommandFailed) {
            return null;
        }

        return $ssid === '' ? null : $ssid;
    }

    /**
     * Deletes the saved Wi-Fi profile(s) named $ssidOrName, each by its
     * UUID. `nmcli connection delete <name>` matches every profile of that
     * name whatever its type, so a VPN or a wired profile that happens to
     * share a Wi-Fi network's name would go with it.
     *
     * @throws NetworkNotFound when no saved Wi-Fi profile has that name
     */
    public function forget(string $ssidOrName): void
    {
        $uuids = $this->wifiProfileUuids($this->run($this->profileListCommand())->stdout, $ssidOrName);

        if ($uuids === []) {
            throw NetworkNotFound::notSaved($ssidOrName);
        }

        foreach ($uuids as $uuid) {
            $this->run($this->deleteCommand($uuid));
        }
    }

    /** Deletes every Wi-Fi profile named $name, by UUID. Never throws: there usually is none. */
    private function deleteWifiProfiles(string $name): void
    {
        $listing = $this->runner->run($this->profileListCommand());

        if (!$listing->isSuccessful()) {
            return;
        }

        foreach ($this->wifiProfileUuids($listing->stdout, $name) as $uuid) {
            $this->runner->run($this->deleteCommand($uuid));
        }
    }

    /** A random (version 4) UUID. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function profileListCommand(): Command
    {
        return new Command('nmcli', ['-t', '-f', 'NAME,UUID,TYPE', 'connection', 'show'], ['LANG' => 'C']);
    }

    private function deleteCommand(string $uuid): Command
    {
        return new Command('nmcli', ['connection', 'delete', 'uuid', $uuid], ['LANG' => 'C']);
    }

    /**
     * @param string $listing the output of {@see self::profileListCommand()}
     * @return list<string> the UUID of every Wi-Fi profile whose name, or own UUID, is $nameOrUuid
     */
    private function wifiProfileUuids(string $listing, string $nameOrUuid): array
    {
        $uuids = [];

        foreach (preg_split('/\r?\n/', $listing) ?: [] as $line) {
            $fields = TerseLine::split($line);

            if (count($fields) !== 3) {
                continue;
            }

            [$name, $uuid, $type] = $fields;

            if ($type === '802-11-wireless' && ($name === $nameOrUuid || $uuid === $nameOrUuid)) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * The passphrase goes to `nmcli` on stdin, never in argv. `nmcli device
     * wifi hotspot` has no way to do that — its `password` is an argument —
     * so the hotspot is raised in two steps instead: `connection add`
     * creates the access-point profile with no passphrase in it, and
     * `--ask connection up` activates it, at which point NetworkManager asks
     * for the missing secret and `--ask` reads it from standard input, the
     * same mechanism {@see self::connect()} uses. Measured on the Pi: the
     * access point comes up and NetworkManager stores the passphrase in the
     * profile, as `device wifi hotspot` did.
     *
     * Any Wi-Fi profile already called "Hotspot" is deleted first, so there
     * is one such profile however often this is called, and a profile whose
     * activation fails is deleted again instead of being left behind. The
     * new profile is created with a UUID generated here and activated by
     * that UUID, never by its name.
     *
     * `nmcli` accepts a channel only together with a band, so a channel
     * given without one brings the band it belongs to
     * ({@see HotspotConfig::resolvedBand()}). There is no country to pass:
     * NetworkManager takes the regulatory domain from the system, so a
     * config that asks for one is refused instead of being silently ignored.
     *
     * @throws UnsupportedOperation for a 6 GHz band, or when $config names a country
     */
    public function startHotspot(HotspotConfig $config, Device $device): Hotspot
    {
        if ($config->country !== null) {
            throw new UnsupportedOperation(
                'NmcliBackend cannot set a hotspot country: NetworkManager uses the system regulatory domain'
                . ' (set it with "iw reg set ' . $config->country . '" or raspi-config).',
            );
        }

        $band = match ($config->resolvedBand()) {
            null => null,
            Band::GHz5 => 'a',
            Band::GHz2_4 => 'bg',
            Band::GHz6 => throw new UnsupportedOperation('NmcliBackend does not support a 6 GHz hotspot.'),
        };

        $settings = ['wifi.mode', 'ap'];

        if ($band !== null) {
            $settings = [...$settings, 'wifi.band', $band];
        }

        if ($config->channel !== null) {
            $settings = [...$settings, 'wifi.channel', (string) $config->channel];
        }

        // WPA2 with CCMP only, as `device wifi hotspot` sets it: left out,
        // NetworkManager would also offer WPA and TKIP.
        $settings = [
            ...$settings,
            'ipv4.method', 'shared', 'ipv6.method', 'ignore',
            'wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.proto', 'rsn',
            'wifi-sec.pairwise', 'ccmp', 'wifi-sec.group', 'ccmp',
        ];

        $this->deleteWifiProfiles(self::HOTSPOT_CONNECTION_NAME);

        // The profile gets a UUID chosen here and is activated by it: "Hotspot" may also be the name of a
        // profile of another type, and the passphrase must reach this one and no other.
        $uuid = self::uuid();

        $this->run(new Command(
            'nmcli',
            [
                'connection', 'add', 'type', 'wifi', 'ifname', $device->name,
                'con-name', self::HOTSPOT_CONNECTION_NAME, 'autoconnect', 'no', 'ssid', $config->ssid,
                '--', 'connection.uuid', $uuid, ...$settings,
            ],
            ['LANG' => 'C'],
        ));

        try {
            $this->run(new Command(
                'nmcli',
                ['-w', '20', '--ask', 'connection', 'up', 'uuid', $uuid],
                ['LANG' => 'C'],
                [],
                $config->password . "\n",
                true,
            ));
        } catch (CommandFailed $exception) {
            $this->runner->run($this->deleteCommand($uuid));

            throw $exception;
        }

        return new Hotspot(self::HOTSPOT_CONNECTION_NAME, $config->ssid, $device);
    }

    public function stopHotspot(): void
    {
        $this->run(new Command('nmcli', ['connection', 'down', self::HOTSPOT_CONNECTION_NAME], ['LANG' => 'C']));
    }

    public function isHotspotActive(): bool
    {
        $command = new Command('nmcli', ['-t', '-f', 'NAME', 'connection', 'show', '--active'], ['LANG' => 'C']);
        $result = $this->run($command);

        foreach (preg_split('/\r?\n/', $result->stdout) ?: [] as $line) {
            if ($line === self::HOTSPOT_CONNECTION_NAME) {
                return true;
            }
        }

        return false;
    }

    private function run(Command $command): CommandResult
    {
        $result = $this->runner->run($command);

        if ($result->isSuccessful()) {
            return $result;
        }

        if (PermissionDenied::looksLike($result)) {
            throw PermissionDenied::fromResult($command, $result, Os::Linux);
        }

        throw CommandFailed::fromResult($command, $result, Os::Linux);
    }
}
