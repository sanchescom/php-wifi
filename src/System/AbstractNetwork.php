<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

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
     * Array description:
     * <code>
     * $frequencySettings = [
     *      [
     *          2412, // frequency starts from
     *          1,    // channel starts from
     *          14,   // channel ends till
     *          5,    // frequency step
     *          1,    // frequency increasing
     *      ],
     * ];
     * </code>
     *
     * @var array[int][int]int
     */
    protected $frequencySettings = [
        [2412, 1, 14, 5, 1],
        [5180, 36, 64, 10, 2],
        [5500, 100, 144, 10, 2],
        [5745, 149, 161, 10, 2],
        [5825, 165, 173, 20, 4],
    ];

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
    public function getFrequencySettings(): array
    {
        return $this->frequencySettings;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return implode('|', [
            $this->bssid,
            $this->ssid,
            $this->quality,
            $this->dbm,
            $this->security,
            $this->securityFlags,
            $this->frequency,
            var_export($this->connected, true),
        ]);
    }

    /**
     * @return array
     */
    protected function generateFrequencies(): array
    {
        if (empty(self::$frequencies)) {
            $frequencySettings = $this->getFrequencySettings();

            foreach ($frequencySettings as $frequencySetting) {
                $this->setGeneratedFrequencies($frequencySetting);
            }
        }

        return self::$frequencies;
    }

    /**
     * @param array $frequencySetting
     */
    protected function setGeneratedFrequencies(array $frequencySetting): void
    {
        list(
            $frequencyStart,
            $channelStart,
            $channelEnd,
            $frequencyStep,
            $frequencyIncreasing
            ) = $frequencySetting;

        for ($i = $channelStart; $i <= $channelEnd; $i += $frequencyIncreasing) {
            self::$frequencies[$i] = $frequencyStart;
            $frequencyStart = $frequencyStart + $frequencyStep;
        }
    }

    /**
     * @return int
     */
    protected function getFrequency(): int
    {
        return $this->generateFrequencies()[$this->channel];
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
