<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System;

/**
 * Class AbstractNetwork.
 */
abstract class AbstractNetwork
{
    /**
     * @var string
     */
    public $bssid;

    /**
     * @var string
     */
    public $ssid;

    /**
     * @var int
     */
    public $channel;

    /**
     * @var float
     */
    public $quality;

    /**
     * @var float
     */
    public $dbm;

    /**
     * @var string
     */
    public $security;

    /**
     * @var string
     */
    public $securityFlags;

    /**
     * @var int
     */
    public $frequency;

    /**
     * @var bool
     */
    public $connected;

    /**
     * @var array
     */
    protected static $securityTypes = [
        'WPA2',
        'WPA',
        'WEP',
    ];

    /** @var CommandExecutor */
    protected $commandExecutor;

    /**
     * AbstractNetwork constructor.
     *
     * @param CommandExecutor $commandExecutor
     */
    public function __construct(CommandExecutor $commandExecutor)
    {
        $this->commandExecutor = $commandExecutor;
    }

    /**
     * @return string
     */
    public function getSecurityType(): string
    {
        $securityType = 'Unknown';

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
     * @param CommandExecutor $commandExecutor
     *
     * @return AbstractNetwork
     */
    abstract public function createFromArray(array $network, CommandExecutor $commandExecutor): self;
}
