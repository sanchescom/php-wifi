<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Illuminate\Support\Collection as BaseCollection;
use Sanchescom\WiFi\Exceptions\NetworkNotFoundException;

/**
 * Class Collection.
 */
class Collection extends BaseCollection
{
    /**
     * Get all networks as array.
     *
     * @return AbstractNetwork[]
     */
    public function getAll(): array
    {
        return $this->all();
    }

    /**
     * Find network by SSID.
     *
     * @throws NetworkNotFoundException
     */
    public function getBySsid(string $ssid): AbstractNetwork
    {
        $network = $this->where('ssid', $ssid)->first();

        if ($network === null) {
            throw new NetworkNotFoundException();
        }

        return $network;
    }

    /**
     * Find network by BSSID (MAC address).
     *
     * @throws NetworkNotFoundException
     */
    public function getByBssid(string $bssid): AbstractNetwork
    {
        $network = $this->where('bssid', $bssid)->first();

        if ($network === null) {
            throw new NetworkNotFoundException();
        }

        return $network;
    }

    /**
     * Get all currently connected networks.
     *
     * @return AbstractNetwork[]
     */
    public function getConnected(): array
    {
        return $this->where('connected', true)->all();
    }

    /**
     * Get networks filtered by security type (WPA2, WPA, WEP).
     *
     * @return self
     */
    public function getBySecurity(string $securityType): self
    {
        return $this->filter(function (AbstractNetwork $network) use ($securityType) {
            return str_contains($network->security, $securityType);
        });
    }

    /**
     * Get networks with signal strength above specified dBm.
     *
     * @return self
     */
    public function getByMinSignalStrength(float $minDbm): self
    {
        return $this->filter(function (AbstractNetwork $network) use ($minDbm) {
            return $network->dbm >= $minDbm;
        });
    }

    /**
     * Get networks on specific channel.
     *
     * @return self
     */
    public function getByChannel(int $channel): self
    {
        return $this->where('channel', $channel);
    }

    /**
     * Get networks on 2.4GHz band (channels 1-14).
     *
     * @return self
     */
    public function get24GhzNetworks(): self
    {
        return $this->filter(function (AbstractNetwork $network) {
            return $network->channel >= 1 && $network->channel <= 14;
        });
    }

    /**
     * Get networks on 5GHz band (channels > 14).
     *
     * @return self
     */
    public function get5GhzNetworks(): self
    {
        return $this->filter(function (AbstractNetwork $network) {
            return $network->channel > 14;
        });
    }

    /**
     * Sort networks by signal strength (strongest first).
     *
     * @return self
     */
    public function sortBySignalStrength(): self
    {
        return $this->sortByDesc('dbm');
    }

    /**
     * Get the strongest network.
     *
     * @throws NetworkNotFoundException
     */
    public function getStrongest(): AbstractNetwork
    {
        $network = $this->sortBySignalStrength()->first();

        if ($network === null) {
            throw new NetworkNotFoundException();
        }

        return $network;
    }
}
