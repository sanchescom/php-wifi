<?php

namespace Sanchescom\WiFi\System;

use Sanchescom\WiFi\Exceptions\NetworkNotFoundException;
use Tightenco\Collect\Support\Collection as BaseCollection;

/**
 * Class Collection.
 */
class Collection extends BaseCollection
{
    /**
     * @return \Sanchescom\WiFi\System\AbstractNetwork[]
     */
    public function getAll()
    {
        return $this->all();
    }

    /**
     * @param string $ssid
     *
     * @return \Sanchescom\WiFi\System\AbstractNetwork
     */
    public function getBySsid(string $ssid)
    {
        return $this->where('ssid', $ssid)->firstOrFail();
    }

    /**
     * @param string $bssid
     *
     * @return \Sanchescom\WiFi\System\AbstractNetwork
     */
    public function getByBssid(string $bssid)
    {
        return $this->where('bssid', $bssid)->firstOrFail();
    }

    /**
     * @return \Sanchescom\WiFi\System\AbstractNetwork[]
     */
    public function getConnected()
    {
        return $this->where('connected', true)->all();
    }

    /**
     * @throws \Sanchescom\WiFi\Exceptions\NetworkNotFoundException
     *
     * @return \Sanchescom\WiFi\System\AbstractNetwork
     */
    public function firstOrFail()
    {
        if ($this->isEmpty()) {
            throw new NetworkNotFoundException();
        }

        return $this->first();
    }
}
