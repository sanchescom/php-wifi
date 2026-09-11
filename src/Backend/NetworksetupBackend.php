<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Backend;

use Sanchescom\WiFi\Exception\CommandFailed;
use Sanchescom\WiFi\Exception\DeviceNotFound;
use Sanchescom\WiFi\Exception\NetworkNotFound;
use Sanchescom\WiFi\Exception\PermissionDenied;
use Sanchescom\WiFi\Parser\Darwin\AirportParser;
use Sanchescom\WiFi\Parser\Darwin\HardwarePortsParser;
use Sanchescom\WiFi\Parser\Darwin\SystemProfilerParser;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandResult;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\Os;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\NetworkCollection;

/**
 * macOS backend driving Wi-Fi through `networksetup` and `system_profiler`.
 * No known-networks or hotspot support is exposed on this platform.
 */
final class NetworksetupBackend implements Backend
{
    public function __construct(private readonly CommandRunner $runner)
    {
    }

    public function scan(): NetworkCollection
    {
        $result = $this->run(new Command('system_profiler', ['SPAirPortDataType']));

        $parser = SystemProfilerParser::looksLikeAirport($result->stdout)
            ? new AirportParser()
            : new SystemProfilerParser();

        return new NetworkCollection($parser->parse($result->stdout));
    }

    /**
     * `networksetup -setairportnetwork` exits 0 even when the join failed,
     * printing the reason on stdout instead: `Could not find network
     * <ssid>.` for an unknown SSID, or `Failed to join network <ssid>.`
     * (with an `apple80211API.error` line) for, e.g., a wrong passphrase.
     * The exit code alone is therefore not enough — the output has to be
     * inspected too.
     */
    public function connect(string $ssid, Credentials $credentials, Device $device): void
    {
        $arguments = ['-setairportnetwork', $device->name, $ssid];
        $secretIndexes = [];

        if ($credentials->password !== null) {
            $secretIndexes[] = count($arguments);
            $arguments[] = $credentials->password;
        }

        $command = new Command('networksetup', $arguments, secretIndexes: $secretIndexes);
        $result = $this->run($command);
        $output = $result->stderr . "\n" . $result->stdout;

        if (preg_match('/^Could not find network .*\.$/m', $output) === 1) {
            throw NetworkNotFound::bySsid($ssid);
        }

        if (
            preg_match('/^Failed to join network /m', $output) === 1
            || str_contains($output, 'apple80211API.error')
        ) {
            throw CommandFailed::despiteZeroExit($command, $result, Os::Darwin);
        }
    }

    /**
     * If the second `-setairportpower … on` call fails after the first
     * `off` call has already succeeded, the radio is left powered off and
     * the thrown `CommandFailed` names the `on` command, not the `off` one.
     */
    public function disconnect(Device $device): void
    {
        $this->run(new Command('networksetup', ['-setairportpower', $device->name, 'off']));
        $this->run(new Command('networksetup', ['-setairportpower', $device->name, 'on']));
    }

    public function detectDevice(): Device
    {
        $result = $this->run(new Command('networksetup', ['-listallhardwareports']));

        return (new HardwarePortsParser())->parse($result->stdout)
            ?? throw DeviceNotFound::onThisSystem('networksetup -listallhardwareports reported no Wi-Fi hardware port');
    }

    private function run(Command $command): CommandResult
    {
        $result = $this->runner->run($command);

        if ($result->isSuccessful()) {
            return $result;
        }

        if (PermissionDenied::looksLike($result)) {
            throw PermissionDenied::fromResult($command, $result, Os::Darwin);
        }

        throw CommandFailed::fromResult($command, $result, Os::Darwin);
    }
}
