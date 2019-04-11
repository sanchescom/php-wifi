<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

trait UtilityTrait
{
    /** @var string */
    private static $utility = 'netsh';

    /** @var string */
    private static $argument = 'wlan';

    /**
     * @return string
     */
    public function getUtility()
    {
        return static::$utility.' '.static::$argument;
    }
}
