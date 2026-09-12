<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Watchdog;

use Sanchescom\WiFi\Exception\WiFiException;
use Sanchescom\WiFi\Parser\Iw\StationDumpParser;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\ShellCommandRunner;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\WiFi;

/**
 * Keeps a headless device reachable.
 *
 * A single-radio device cannot scan while it serves an access point — a
 * lesson learned on real hardware in 3.1 — so tick() checks
 * {@see WiFi::isHotspotActive()} first and, whenever it is true, never
 * calls {@see WiFi::scan()}: scanning would require dropping the hotspot,
 * which would drop whoever is connected to it mid-provisioning. Only when
 * no hotspot is up does tick() scan to decide whether the device is
 * connected.
 *
 * Counting attached stations needs `iw dev <iface> station dump`, which the
 * {@see WiFi} facade has no method for (by design — it is a capability of
 * the Linux backends' Wi-Fi driver, not of the cross-platform facade). This
 * class therefore carries its own {@see CommandRunner} and runs `iw`
 * itself, rather than reaching into a Backend's internals or requiring the
 * backends to implement a new capability interface. The trade-off: on a
 * system without `iw` (e.g. running against NetworkManager without the
 * wireless-tools package, or on a platform other than Linux) station
 * counting always reports zero, which — per the decision table — is treated
 * the same as "iw absent", i.e. nobody attached; the hotspot is still
 * managed correctly, just without the busy/idle distinction.
 */
final class Watchdog
{
    private readonly CommandRunner $commandRunner;

    private ?int $hotspotRaisedAt = null;

    public function __construct(
        private readonly WiFi $wifi,
        private readonly WatchdogConfig $config,
        private readonly Clock $clock,
        ?CommandRunner $commandRunner = null,
    ) {
        $this->commandRunner = $commandRunner ?? ShellCommandRunner::forCurrentOs();
    }

    /** One decision. Never sleeps. */
    public function tick(): WatchdogState
    {
        try {
            if ($this->wifi->isHotspotActive()) {
                return $this->manageActiveHotspot();
            }

            if ($this->wifi->scan()->connected()->isNotEmpty()) {
                return WatchdogState::Connected;
            }

            return $this->recover();
        } catch (WiFiException) {
            return WatchdogState::Failed;
        }
    }

    /** tick(), then sleep(interval) — forever. The only place that sleeps. */
    public function run(): never
    {
        while (true) {
            $this->tick();
            $this->clock->sleep($this->config->interval);
        }
    }

    private function manageActiveHotspot(): WatchdogState
    {
        $this->hotspotRaisedAt ??= $this->clock->now();

        $stations = $this->countHotspotStations();

        if ($stations > 0 && $this->config->requireIdleHotspot) {
            return WatchdogState::HotspotBusy;
        }

        $elapsed = $this->clock->now() - $this->hotspotRaisedAt;

        if ($elapsed < $this->config->retryAfter) {
            return WatchdogState::HotspotBusy;
        }

        $this->wifi->stopHotspot();
        $this->hotspotRaisedAt = null;

        return $this->recover();
    }

    /** Not connected, no hotspot in the way: try to join, or raise the hotspot. */
    private function recover(): WatchdogState
    {
        if ($this->tryReconnect()) {
            return WatchdogState::Recovered;
        }

        $this->wifi->startHotspot($this->config->hotspot);
        $this->hotspotRaisedAt = $this->clock->now();

        return WatchdogState::HotspotRaised;
    }

    private function tryReconnect(): bool
    {
        $device = $this->wifi->device();

        foreach ($this->candidateSsids() as $ssid) {
            try {
                $this->wifi->connectTo($ssid, Credentials::none(), $device);

                return true;
            } catch (WiFiException) {
                continue;
            }
        }

        return false;
    }

    /** @return list<string> the configured SSID, or every known network's SSID in order */
    private function candidateSsids(): array
    {
        if ($this->config->ssid !== null) {
            return [$this->config->ssid];
        }

        return array_map(
            static fn (KnownNetwork $network): string => $network->ssid,
            $this->wifi->knownNetworks(),
        );
    }

    /** iw absent, or the dump otherwise unreadable, counts as nobody attached. */
    private function countHotspotStations(): int
    {
        $device = $this->wifi->device();
        $result = $this->commandRunner->run(new Command('iw', ['dev', $device->name, 'station', 'dump']));

        if (!$result->isSuccessful()) {
            return 0;
        }

        return (new StationDumpParser())->count($result->stdout);
    }
}
