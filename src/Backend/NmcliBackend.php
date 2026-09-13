<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Parser\Nmcli\ConnectionListParser;
use Sanchescom\WiFi\Parser\Nmcli\DeviceListParser;
use Sanchescom\WiFi\Parser\Nmcli\ListParser;
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
     * passphrase here first deletes any existing profile for $ssid; its
     * failure (there usually is no such profile) is expected and ignored —
     * this call must never throw. This also drops any other setting that
     * profile held (static IP, autoconnect), which is the price of
     * honouring a freshly supplied passphrase over a stale saved one.
     */
    public function connect(string $ssid, Credentials $credentials, Device $device): void
    {
        $arguments = ['-w', '10'];
        $stdin = null;
        $stdinIsSecret = false;

        if ($credentials->password !== null) {
            $this->runner->run(new Command('nmcli', ['connection', 'delete', $ssid], ['LANG' => 'C']));

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

    public function forget(string $ssidOrName): void
    {
        $this->run(new Command('nmcli', ['connection', 'delete', $ssidOrName], ['LANG' => 'C']));
    }

    public function startHotspot(HotspotConfig $config, Device $device): Hotspot
    {
        $band = match ($config->band) {
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
