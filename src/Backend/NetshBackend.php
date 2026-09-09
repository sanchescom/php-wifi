<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Backend\Windows\ProfileFile;
use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Parser\Windows\InterfacesParser;
use Sanchescom\WiFi\Parser\Windows\NetworksParser;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;
use Sanchescom\WiFi\Value\Security;

/**
 * Windows backend driving Wi-Fi through `netsh`. Joining a network goes
 * through a temporary profile file (see `Windows\ProfileFile`) because
 * `netsh wlan connect` can only join a network that already has a saved
 * profile.
 */
final class NetshBackend implements Backend
{
    /**
     * The one allowed composite command: it contains no user data, only a
     * constant code-page switch (so netsh prints UTF-8) chained with the
     * scan command via cmd's `&`.
     *
     * @var list<string>
     */
    private const NETWORKS_COMMAND_ARGUMENTS = ['/c', 'chcp 65001 >nul & netsh wlan show networks mode=Bssid'];

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ?string $profileDirectory = null,
    ) {
    }

    public function scan(): NetworkCollection
    {
        $networksResult = $this->run(new Command('cmd', self::NETWORKS_COMMAND_ARGUMENTS));
        $networks = (new NetworksParser())->parse($networksResult->stdout);

        $interfacesResult = $this->run(new Command('netsh', ['wlan', 'show', 'interfaces']));
        $connectedBssid = (new InterfacesParser())->parse($interfacesResult->stdout)->connectedBssid;

        if ($connectedBssid !== null) {
            $networks = array_map(
                static fn (Network $network): Network => $network->bssid?->equals($connectedBssid) === true
                    ? self::markConnected($network)
                    : $network,
                $networks,
            );
        }

        return new NetworkCollection($networks);
    }

    public function connect(string $ssid, Credentials $credentials, Device $device): void
    {
        $security = Security::Unknown;

        foreach ($this->scan() as $network) {
            if ($network->ssid === $ssid) {
                $security = $network->security;
                break;
            }
        }

        $profile = new ProfileFile($ssid, $security, $this->profileDirectory);

        try {
            $path = $profile->create($credentials->password ?? '');

            $this->run(new Command('netsh', ['wlan', 'add', 'profile', 'filename=' . $path]));
            $this->run(new Command('netsh', [
                'wlan',
                'connect',
                'interface=' . $device->name,
                'ssid=' . $ssid,
                'name=' . $ssid,
            ]));
        } finally {
            $profile->delete();
        }
    }

    public function disconnect(Device $device): void
    {
        $this->run(new Command('netsh', ['wlan', 'disconnect', 'interface=' . $device->name]));
    }

    public function detectDevice(): Device
    {
        $result = $this->run(new Command('netsh', ['wlan', 'show', 'interfaces']));

        return (new InterfacesParser())->parse($result->stdout)->device
            ?? throw DeviceNotFound::onThisSystem('netsh wlan show interfaces listed no wireless interface');
    }

    private static function markConnected(Network $network): Network
    {
        return new Network(
            ssid: $network->ssid,
            ssidHidden: $network->ssidHidden,
            bssid: $network->bssid,
            channel: $network->channel,
            band: $network->band,
            frequency: $network->frequency,
            signal: $network->signal,
            security: $network->security,
            securityFlags: $network->securityFlags,
            connected: true,
        );
    }

    private function run(Command $command): CommandResult
    {
        $result = $this->runner->run($command);

        if ($result->isSuccessful()) {
            return $result;
        }

        if (PermissionDenied::looksLike($result)) {
            throw PermissionDenied::fromResult($command, $result, Os::Windows);
        }

        throw CommandFailed::fromResult($command, $result, Os::Windows);
    }
}
