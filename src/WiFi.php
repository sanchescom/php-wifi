<?php

declare(strict_types=1);

namespace Sanchescom\WiFi;

use Sanchescom\WiFi\Backend\Backend;
use Sanchescom\WiFi\Backend\BackendFactory;
use Sanchescom\WiFi\Backend\SupportsHotspot;
use Sanchescom\WiFi\Backend\SupportsKnownNetworks;
use Sanchescom\WiFi\Exception\InvalidArgument;
use Sanchescom\WiFi\Exception\UnsupportedOperation;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\Device;
use Sanchescom\WiFi\Value\Hotspot;
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\Value\Network;
use Sanchescom\WiFi\Value\NetworkCollection;

/**
 * Object facade over a single Backend. Holds no static state; create() is
 * the only static method, a pure factory around BackendFactory.
 */
final class WiFi
{
    public function __construct(private readonly Backend $backend)
    {
    }

    public static function create(?CommandRunner $runner = null): self
    {
        return new self(BackendFactory::forCurrentOs($runner));
    }

    public function scan(): NetworkCollection
    {
        return $this->backend->scan();
    }

    public function connect(Network|string $network, Credentials $credentials, ?Device $device = null): void
    {
        if ($network instanceof Network && $network->ssidHidden) {
            throw InvalidArgument::hiddenNetwork($network);
        }

        if (is_string($network) && $network === '') {
            throw InvalidArgument::emptySsid();
        }

        $ssid = $network instanceof Network ? $network->ssid : $this->scan()->bySsid($network)->ssid;

        $this->connectTo($ssid, $credentials, $device);
    }

    /**
     * Join $ssid without scanning first.
     *
     * connect() scans to resolve the SSID and to raise NetworkNotFound for a
     * typo. That is impossible while the radio runs an access point — a
     * single-radio Raspberry Pi serving its own provisioning page — so this
     * variant hands the SSID straight to the backend, and a wrong SSID
     * surfaces as the backend's own CommandFailed instead.
     */
    public function connectTo(string $ssid, Credentials $credentials, ?Device $device = null): void
    {
        if ($ssid === '') {
            throw InvalidArgument::emptySsid();
        }

        $device ??= $this->backend->detectDevice();

        $this->backend->connect($ssid, $credentials, $device);
    }

    public function disconnect(?Device $device = null): void
    {
        $device ??= $this->backend->detectDevice();

        $this->backend->disconnect($device);
    }

    /** The detected wireless device. */
    public function device(): Device
    {
        return $this->backend->detectDevice();
    }

    /** @return list<KnownNetwork> */
    public function knownNetworks(): array
    {
        return $this->knownNetworksBackend()->knownNetworks();
    }

    public function forget(KnownNetwork|string $ssid): void
    {
        $this->knownNetworksBackend()->forget($ssid instanceof KnownNetwork ? $ssid->name : $ssid);
    }

    public function startHotspot(HotspotConfig $config): Hotspot
    {
        $device = $config->device ?? $this->backend->detectDevice();

        return $this->hotspotBackend()->startHotspot($config, $device);
    }

    public function stopHotspot(): void
    {
        $this->hotspotBackend()->stopHotspot();
    }

    public function isHotspotActive(): bool
    {
        return $this->hotspotBackend()->isHotspotActive();
    }

    public function supports(string $capabilityInterface): bool
    {
        return $this->backend instanceof $capabilityInterface;
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    private function knownNetworksBackend(): SupportsKnownNetworks
    {
        return $this->backend instanceof SupportsKnownNetworks
            ? $this->backend
            : throw UnsupportedOperation::by($this->backend::class, SupportsKnownNetworks::class);
    }

    private function hotspotBackend(): SupportsHotspot
    {
        return $this->backend instanceof SupportsHotspot
            ? $this->backend
            : throw UnsupportedOperation::by($this->backend::class, SupportsHotspot::class);
    }
}
