<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Watchdog;

/** The outcome of one Watchdog::tick(). */
enum WatchdogState: string
{
    /** Already joined to a client network; nothing was done. */
    case Connected = 'connected';

    /** Was disconnected (or the hotspot was stopped); a join attempt succeeded. */
    case Recovered = 'recovered';

    /** No client connection could be recovered; the provisioning hotspot is now up. */
    case HotspotRaised = 'hotspot_raised';

    /** The hotspot is up and either occupied or not yet due for a retry; nothing was touched. */
    case HotspotBusy = 'hotspot_busy';

    /** An unexpected failure while acting; the loop keeps running. */
    case Failed = 'failed';
}
