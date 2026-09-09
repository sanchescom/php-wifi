<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Illuminate\Support\Collection as BaseCollection;
use Sanchescom\WiFi\Exceptions\NetworkNotFoundException;

/**
 * Class Collection.
 *
 * @extends BaseCollection<int, AbstractNetwork>
 * @phpstan-consistent-constructor
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
     * Networks on the 2.4 GHz band (2400–2499 MHz).
     */
    public function get24GhzNetworks(): self
    {
        return $this->filter(fn (AbstractNetwork $n) => $n->frequency >= 2400 && $n->frequency < 2500);
    }

    /**
     * Networks on the 5 GHz band (4900–5924 MHz).
     */
    public function get5GhzNetworks(): self
    {
        return $this->filter(fn (AbstractNetwork $n) => $n->frequency >= 4900 && $n->frequency < 5925);
    }

    /**
     * Networks on the 6 GHz band (5925 MHz and above).
     */
    public function get6GhzNetworks(): self
    {
        return $this->filter(fn (AbstractNetwork $n) => $n->frequency >= 5925);
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

    /**
     * Whether any network's name was hidden by the OS (see AbstractNetwork::$ssidRedacted).
     */
    public function hasRedactedSsids(): bool
    {
        return $this->contains(fn (AbstractNetwork $n) => $n->ssidRedacted);
    }

    /**
     * One network per SSID — the radio with the strongest signal — in order of
     * first appearance. Hidden networks (empty SSID) are not merged.
     */
    public function uniqueBySsid(): self
    {
        $strongest = [];
        $hidden = [];

        foreach ($this as $network) {
            if ($network->ssid === '') {
                $hidden[] = $network;
                continue;
            }

            if (!isset($strongest[$network->ssid]) || $network->dbm > $strongest[$network->ssid]->dbm) {
                $strongest[$network->ssid] = $network;
            }
        }

        return new static(array_merge(array_values($strongest), $hidden));
    }
}
