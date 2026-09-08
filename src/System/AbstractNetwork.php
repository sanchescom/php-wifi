<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Sanchescom\WiFi\Contracts\CommandInterface;
use Sanchescom\WiFi\Contracts\NetworkInterface;

/**
 * Class AbstractNetwork.
 */
abstract class AbstractNetwork implements NetworkInterface
{
    public const WPA3_SECURITY = 'WPA3';
    public const WPA2_SECURITY = 'WPA2';
    public const WPA_SECURITY = 'WPA';
    public const WEP_SECURITY = 'WEP';
    public const UNKNOWN_SECURITY = 'Unknown';

    public string $bssid;
    public string $ssid;
    public int $channel;
    public float $quality;
    public float $dbm;
    public string $security;
    public string $securityFlags;
    public int $frequency;
    public bool $connected;

    protected static array $securityTypes = [
        self::WPA3_SECURITY,
        self::WPA2_SECURITY,
        self::WPA_SECURITY,
        self::WEP_SECURITY,
    ];

    protected CommandInterface $command;

    /**
     * AbstractNetwork constructor.
     *
     * @param \Sanchescom\WiFi\Contracts\CommandInterface $command
     */
    public function __construct(CommandInterface $command)
    {
        $this->command = $command;
    }

    /**
     * @return string
     */
    public function getSecurityType(): string
    {
        foreach (self::$securityTypes as $securityType) {
            if (strpos($this->security, $securityType) !== false) {
                return $securityType;
            }
        }

        return self::UNKNOWN_SECURITY;
    }

    /**
     * @return CommandInterface
     */
    public function getCommand(): CommandInterface
    {
        return $this->command;
    }

    /**
     * Set both signal properties from a 0-100 quality percentage.
     *
     * @see to_dbm() in src/Helpers/calculate.php - same formula; the helper stays for BC.
     *
     * @param float $quality
     */
    protected function setSignalFromQuality(float $quality): void
    {
        $this->quality = max(0.0, min(100.0, $quality));
        $this->dbm = $this->quality / 2 - 100;
    }

    /**
     * Set both signal properties from a dBm reading.
     *
     * @see to_quality() in src/Helpers/calculate.php - same formula; the helper stays for BC.
     *
     * @param float $dbm
     */
    protected function setSignalFromDbm(float $dbm): void
    {
        $this->dbm = $dbm;
        $this->quality = max(0.0, min(100.0, 2 * ($dbm + 100)));
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
     *
     * @return \Sanchescom\WiFi\System\AbstractNetwork
     */
    abstract public function createFromArray(array $network): self;
}
