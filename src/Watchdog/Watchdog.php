<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Watchdog;

use Sanchescom\WiFi\Backend\Linux\ToolPath;
use Sanchescom\WiFi\Exception\WiFiException;
use Sanchescom\WiFi\Parser\Iw\StationDumpParser;
use Sanchescom\WiFi\Shell\Command;
use Sanchescom\WiFi\Shell\CommandRunner;
use Sanchescom\WiFi\Shell\ShellCommandRunner;
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\Value\KnownNetwork;
use Sanchescom\WiFi\WiFi;
use Throwable;

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
 * backends to implement a new capability interface. `iw` is resolved
 * through {@see ToolPath}, exactly like every backend tool — a bare `iw`
 * exits 127 on the unprivileged `PATH` this whole release exists to fix
 * (`/usr/sbin` is absent from it on Debian/Raspberry Pi OS), which used to
 * silently read as "nobody attached" and let this class tear down a hotspot
 * with a phone on it, the one failure it exists to prevent.
 *
 * {@see self::countHotspotStations()} therefore returns `?int`: `null` means
 * "the count could not be determined" (`iw` could not be resolved, or the
 * `station dump` call itself failed) and is kept distinct from `0`, a
 * genuinely empty dump. {@see self::manageActiveHotspot()} treats `null` the
 * same as "someone is attached" whenever {@see WatchdogConfig::$requireIdleHotspot}
 * is true (its default): a failed read must not be read as "the hotspot is
 * idle" when the entire point of that flag is to protect whoever might be
 * attached — the safe direction is to assume they are there, not to guess
 * that they are not. A caller who has explicitly opted out of the idle
 * check (`requireIdleHotspot: false`) already ignores the station count
 * entirely, so a failed read changes nothing for them either way.
 *
 * "When did the hotspot go up" needs to survive this process dying and being
 * restarted — a headless watchdog that forgets its retry clock on every
 * crash-loop or routine restart would let an idle hotspot stand forever,
 * defeating retryAfter entirely. That timestamp is therefore mirrored to a
 * small JSON file ({@see $stateFile}, one field, no secrets) rather than
 * kept only in memory: written when the hotspot is raised, read back by a
 * fresh instance that finds the hotspot already up, and removed once the
 * hotspot is stopped. Reading or writing it never throws — a missing,
 * unreadable or malformed file falls back to treating the hotspot as just
 * raised "now", because a watchdog must not die over its own bookkeeping.
 *
 * {@see WatchdogConfig::$device}, when set, is the interface both
 * tryReconnect() and countHotspotStations() use, instead of each calling
 * {@see WiFi::device()} separately — on a multi-radio host that keeps a
 * pinned `--device` from silently drifting between the interface a hotspot
 * was raised on and the one reconnection attempts and station counts run
 * against.
 */
final class Watchdog
{
    private readonly CommandRunner $commandRunner;

    private readonly string $stateFile;

    private ?int $hotspotRaisedAt = null;

    private ?string $lastError = null;

    public function __construct(
        private readonly WiFi $wifi,
        private readonly WatchdogConfig $config,
        private readonly Clock $clock,
        ?CommandRunner $commandRunner = null,
        ?string $stateFile = null,
    ) {
        $this->commandRunner = $commandRunner ?? ShellCommandRunner::forCurrentOs();
        $this->stateFile = $stateFile ?? sys_get_temp_dir() . '/php-wifi-watchdog.json';
    }

    /** One decision. Never sleeps. */
    public function tick(): WatchdogState
    {
        $this->lastError = null;

        try {
            if ($this->wifi->isHotspotActive()) {
                return $this->manageActiveHotspot();
            }

            if ($this->wifi->scan()->connected()->isNotEmpty()) {
                return WatchdogState::Connected;
            }

            return $this->recover();
        } catch (Throwable $exception) {
            // Not just WiFiException: HostapdConfig::create() (reached from
            // recover() -> WiFi::startHotspot()) throws a plain
            // RuntimeException on a write failure, and this class's own
            // bookkeeping is the kind of thing that could throw too. Any of
            // it must land here as Failed, not escape run()'s loop into the
            // CLI's outer catch and kill an otherwise-recoverable daemon.
            $this->lastError = $exception->getMessage();

            return WatchdogState::Failed;
        }
    }

    /**
     * Why the most recent tick() ended as it did, when that needs saying: the
     * exception message behind a {@see WatchdogState::Failed}, or why a
     * {@see WatchdogState::HotspotBusy} hotspot's stations could not be
     * counted. Null otherwise. Lets a caller that only
     * gets a state back (run()'s observer, --once's printed value) explain
     * why, without tick() itself doing any I/O.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * tick(), then sleep(interval) — forever. The only place that sleeps.
     * $onTick, when given, is called with the state right after each
     * tick() — the class stays free of I/O; a caller wanting to log or
     * otherwise observe ticks (a headless watch loop under systemd, say)
     * supplies its own callback.
     */
    public function run(?callable $onTick = null): never
    {
        while (true) {
            $state = $this->tick();

            if ($onTick !== null) {
                $onTick($state);
            }

            $this->clock->sleep($this->config->interval);
        }
    }

    private function manageActiveHotspot(): WatchdogState
    {
        $this->hotspotRaisedAt ??= $this->readHotspotRaisedAt() ?? $this->recordHotspotRaisedAt();

        $stations = $this->countHotspotStations();

        if ($this->config->requireIdleHotspot && ($stations === null || $stations > 0)) {
            return WatchdogState::HotspotBusy;
        }

        $now = $this->clock->now();

        if ($now < $this->hotspotRaisedAt) {
            // The clock stepped back behind the raise time — a Pi with no RTC
            // corrected by NTP while the hotspot is up. The elapsed time
            // would never reach retryAfter and the real network would never
            // be retried, so restart the countdown from the clock as it now
            // stands (the same `$age >= 0` guard examples/provision/index.php
            // has).
            $this->recordHotspotRaisedAt();

            return WatchdogState::HotspotBusy;
        }

        if ($now - $this->hotspotRaisedAt < $this->config->retryAfter) {
            return WatchdogState::HotspotBusy;
        }

        $this->wifi->stopHotspot();
        $this->clearHotspotRaisedAt();

        return $this->recover();
    }

    /** Not connected, no hotspot in the way: try to join, or raise the hotspot. */
    private function recover(): WatchdogState
    {
        if ($this->tryReconnect()) {
            return WatchdogState::Recovered;
        }

        $this->wifi->startHotspot($this->config->hotspot);
        $this->recordHotspotRaisedAt();

        return WatchdogState::HotspotRaised;
    }

    private function tryReconnect(): bool
    {
        $device = $this->config->device ?? $this->wifi->device();

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

    /**
     * Null means "could not be determined" — `iw` could not be resolved
     * through {@see ToolPath}, or the `station dump` call itself failed —
     * kept distinct from `0`, a dump that genuinely lists nobody. See the
     * class docblock for why callers must not treat the two the same. The
     * reason goes to {@see self::lastError()}, so a hotspot held up by it is
     * distinguishable in the journal from one with a phone on it. `iw` is
     * resolved afresh on every call (a fresh {@see ToolPath}, whose cache
     * would otherwise remember "not found" for the life of the daemon), so
     * installing it takes effect without a restart.
     */
    private function countHotspotStations(): ?int
    {
        $device = $this->config->device ?? $this->wifi->device();
        $iw = (new ToolPath($this->commandRunner))->resolve('iw');

        if ($iw === null) {
            $this->lastError = 'cannot count hotspot stations: iw was not found';

            return null;
        }

        $result = $this->commandRunner->run(new Command($iw, ['dev', $device->name, 'station', 'dump']));

        if (!$result->isSuccessful()) {
            $this->lastError = sprintf(
                'cannot count hotspot stations: iw station dump exited %d%s',
                $result->exitCode,
                trim($result->stderr) === '' ? '' : ': ' . trim($result->stderr),
            );

            return null;
        }

        return (new StationDumpParser())->count($result->stdout);
    }

    /** Sets, persists and returns "now" as the moment the hotspot went up. */
    private function recordHotspotRaisedAt(): int
    {
        $now = $this->clock->now();
        $this->hotspotRaisedAt = $now;

        $this->suppressingWarnings(
            fn (): int|false => file_put_contents($this->stateFile, (string) json_encode(['hotspotRaisedAt' => $now])),
        );

        return $now;
    }

    private function clearHotspotRaisedAt(): void
    {
        $this->hotspotRaisedAt = null;

        if (is_file($this->stateFile)) {
            $this->suppressingWarnings(fn (): bool => unlink($this->stateFile));
        }
    }

    /** Null on anything short of a clean, valid read — missing, unreadable or malformed alike. */
    private function readHotspotRaisedAt(): ?int
    {
        if (!is_file($this->stateFile) || !is_readable($this->stateFile)) {
            return null;
        }

        $contents = $this->suppressingWarnings(fn (): string|false => file_get_contents($this->stateFile));

        if ($contents === false || $contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) && is_int($data['hotspotRaisedAt'] ?? null) ? $data['hotspotRaisedAt'] : null;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function suppressingWarnings(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
