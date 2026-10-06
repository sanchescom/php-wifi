<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Provision;

enum SupervisorState: string
{
    /** The hotspot is up and waiting for someone to use the page. */
    case Serving = 'serving';

    /** The page has taken the hotspot down and is joining a network. */
    case Joining = 'joining';

    /** The hotspot was found down with no join running, and was raised again. */
    case Restarted = 'restarted';

    /** The device has joined a network; the run is over. */
    case Done = 'done';
}
