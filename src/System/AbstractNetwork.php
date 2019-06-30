<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

use Sanchescom\WiFi\Contracts\CommandInterface;

/**
 * Class AbstractNetwork.
 */
abstract class AbstractNetwork
{
    /** @var string */
    const WPA2_SECURITY = 'WPA2';

    /** @var string */
    const WPA_SECURITY = 'WPA';

    /** @var string */
    const WEP_SECURITY = 'WEP';

    /** @var string */
    const UNKNOWN_SECURITY = 'Unknown';

    /** @var string */
    public $bssid;

    /** @var string */
    public $ssid;

    /** @var int */
    public $channel;

    /** @var float */
    public $quality;

    /** @var float */
    public $dbm;

    /** @var string */
    public $security;

    /** @var string */
    public $securityFlags;

    /** @var int */
    public $frequency;

    /** @var bool */
    public $connected;

    /** @var array */
    protected static $securityTypes = [
        self::WPA2_SECURITY,
        self::WPA_SECURITY,
        self::WEP_SECURITY,
    ];

    /** @var \Sanchescom\WiFi\Contracts\CommandInterface */
    protected $command;

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
        $securityType = self::UNKNOWN_SECURITY;

        foreach (self::$securityTypes as $securityType) {
            if (strpos($this->security, $securityType) !== false) {
                break;
            }
        }

        return $securityType;
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
