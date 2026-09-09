<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\Value;

use Illuminate\Support\Collection;
use Sanchescom\WiFi\Exception\NetworkNotFound;

/**
 * @extends Collection<int, Network>
 * @phpstan-consistent-constructor
 */
final class NetworkCollection extends Collection
{
    public function bySsid(string $ssid): Network
    {
        return $this->first(fn (Network $n) => $n->ssid === $ssid) ?? throw NetworkNotFound::bySsid($ssid);
    }

    public function byBssid(Bssid|string $bssid): Network
    {
        $wanted = $bssid instanceof Bssid ? $bssid : Bssid::from($bssid);

        return $this->first(fn (Network $n) => $n->bssid?->equals($wanted) === true)
            ?? throw NetworkNotFound::byBssid((string) $wanted);
    }

    public function connected(): static
    {
        return $this->filter(fn (Network $n) => $n->connected)->values();
    }

    public function band(Band $band): static
    {
        return $this->filter(fn (Network $n) => $n->band === $band)->values();
    }

    public function security(Security $security): static
    {
        return $this->filter(fn (Network $n) => $n->security === $security)->values();
    }

    public function strongerThan(Signal|float $threshold): static
    {
        $dbm = $threshold instanceof Signal ? $threshold->dbm : $threshold;

        return $this->filter(fn (Network $n) => $n->signal?->atLeast($dbm) === true)->values();
    }

    /** Strongest first; networks without a signal reading last, in their original order. */
    public function sortBySignal(): static
    {
        return $this->sortBy(fn (Network $n) => $n->signal === null ? PHP_FLOAT_MAX : -$n->signal->dbm)->values();
    }

    public function strongest(): Network
    {
        return $this->sortBySignal()->first(fn (Network $n) => $n->signal !== null) ?? throw NetworkNotFound::none();
    }

    /** One network per SSID — the radio with the strongest signal — in order of first appearance; hidden names are never merged. */
    public function uniqueBySsid(): static
    {
        $strongest = [];
        $hidden = [];

        foreach ($this as $network) {
            if ($network->ssid === '' || $network->ssidHidden) {
                $hidden[] = $network;
                continue;
            }
            $current = $strongest[$network->ssid] ?? null;
            if ($current === null || self::isStrongerReading($network, $current)) {
                $strongest[$network->ssid] = $network;
            }
        }

        return new static([...array_values($strongest), ...$hidden]);
    }

    private static function isStrongerReading(Network $candidate, Network $current): bool
    {
        return $candidate->signal !== null
            && ($current->signal === null || $candidate->signal->isStrongerThan($current->signal));
    }

    public function hasHiddenSsids(): bool
    {
        return $this->contains(fn (Network $n) => $n->ssidHidden);
    }
}
