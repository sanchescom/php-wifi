<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\WiFi;
use Throwable;

/**
 * Keeps the setup hotspot there for as long as a provisioning run needs it.
 *
 * The page takes the hotspot down to try a join. If the join works the run
 * is over; if it fails, the radio is left idle with no access point, and the
 * person holding the phone has nothing to go back to. So {@see self::tick()}
 * raises the hotspot again whenever it finds it down — but not while a join
 * is running, which would take the radio away from it (measured on the Pi in
 * 3.2.5, where a shell loop doing this job restarted the hotspot one second
 * into every join).
 */
final class Supervisor
{
    private int $failedRestarts = 0;

    public function __construct(
        private readonly WiFi $wifi,
        private readonly HotspotConfig $hotspot,
        private readonly State $state,
        private readonly int $maxFailedRestarts = 5,
    ) {
    }

    /** Scans — the last chance to, with one radio — and raises the hotspot. */
    public function start(): void
    {
        $this->state->reset();

        try {
            $this->state->cacheNetworks(Portal::describe($this->wifi->scan()));
        } catch (Throwable) {
            // No list to offer; the page says so. The hotspot is still worth raising.
        }

        $this->wifi->startHotspot($this->hotspot);
    }

    /**
     * One decision. Never sleeps.
     *
     * @throws Throwable the failure of the last restart, once $maxFailedRestarts in a row have failed
     */
    public function tick(): SupervisorState
    {
        if ($this->state->joinedSsid() !== null) {
            return SupervisorState::Done;
        }

        if ($this->state->isJoining()) {
            return SupervisorState::Joining;
        }

        if ($this->wifi->isHotspotActive()) {
            $this->failedRestarts = 0;

            return SupervisorState::Serving;
        }

        try {
            $this->wifi->startHotspot($this->hotspot);
        } catch (Throwable $exception) {
            if (++$this->failedRestarts >= $this->maxFailedRestarts) {
                throw $exception;
            }
        }

        return SupervisorState::Restarted;
    }

    /** Takes the hotspot down, if it is up. For a run that ends without a join. */
    public function stop(): void
    {
        if ($this->wifi->isHotspotActive()) {
            $this->wifi->stopHotspot();
        }
    }
}
