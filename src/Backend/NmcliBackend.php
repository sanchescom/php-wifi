<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
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
            $listing = $this->runner->run($this->profileListCommand());

            if ($listing->isSuccessful()) {
                foreach ($this->wifiProfileUuids($listing->stdout, $ssid) as $uuid) {
                    $this->runner->run($this->deleteCommand($uuid));
                }
            }

            $arguments[] = '--ask';
            $stdin = $credentials->password . "\n";
            $stdinIsSecret = true;
        }

        $arguments = [...$arguments, 'device', 'wifi', 'connect', $ssid, 'ifname', $device->name];

        $this->run(new Command('nmcli', $arguments, ['LANG' => 'C'], [], $stdin, $stdinIsSecret));
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

        $arguments = [
            'device',
            'wifi',
            'hotspot',
            'ifname',
            $device->name,
            'ssid',
            $config->ssid,
            'password',
            $config->password,
        ];
        $secretIndexes = [count($arguments) - 1];

        if ($band !== null) {
            $arguments[] = 'band';
            $arguments[] = $band;
        }

        if ($config->channel !== null) {
            $arguments[] = 'channel';
            $arguments[] = (string) $config->channel;
        }

        $this->run(new Command('nmcli', $arguments, ['LANG' => 'C'], $secretIndexes));

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
