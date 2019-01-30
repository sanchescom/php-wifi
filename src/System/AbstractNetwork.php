<?php

declare(strict_types=1);

namespace Sanchescom\Wifi\System;

/**
 * Class AbstractNetwork
 * @package Sanchescom\Wifi\System
 */
abstract class AbstractNetwork
{
    /**
     * @var string $bssid
     */
    public $bssid;

    /**
     * @var string $ssid
     */
    public $ssid;

    /**
     * @var int $channel
     */
    public $channel;

    /**
     * @var float $quality
     */
    public $quality;

    /**
     * @var float $dbm
     */
    public $dbm;

    /**
     * @var string $security
     */
    public $security;

    /**
     * @var string $securityFlags
     */
    public $securityFlags;

    /**
     * @var int $frequency
     */
    public $frequency;

    /**
     * @var bool $connected
     */
    public $connected;

    /**
     * @var array
     */
    protected static $frequencies = [];

    /**
     * @param string $password
     * @param string $device
     */
    abstract public function connect(string $password, string $device): void;

    /**
     * @param string $device
     */
    abstract public function disconnect(string $device): void;

    /**
     * @param array $network
     * @return AbstractNetwork
     */
    abstract public static function createFromArray(array $network): AbstractNetwork;

    /**
     * @return array
     */
    protected static function generateFrequencies(): array
    {
        if (empty(self::$frequencies)) {
            $frequencySettings = [
                [2412, 1, 14, 5, 1],
                [5180, 36, 64, 10, 2],
                [5500, 100, 144, 10, 2],
                [5745, 149, 161, 10, 2],
                [5825, 165, 173, 20, 4],
            ];

            $frequencies = [];

            foreach ($frequencySettings as $frequencySetting) {
                for ($i = $frequencySetting[1]; $i <= $frequencySetting[2]; $i += $frequencySetting[4]) {
                    $frequencies[$i] = $frequencySetting[0];
                    $frequencySetting[0] = $frequencySetting[0] + $frequencySetting[3];
                }
            }

            self::$frequencies = $frequencies;
        }

        return self::$frequencies;
    }

    /**
     * @return int
     */
    protected function getFrequency(): int
    {
        return self::generateFrequencies()[$this->channel];
    }

    /**
     * @return float
     */
    protected function dBmToQuality(): float
    {
        return 2 * ($this->dbm + 100);
    }

    /**
     * @return float
     */
    protected function qualityToDBm(): float
    {
        return ($this->quality / 2) - 100;
    }
}
