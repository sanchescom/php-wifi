<?php

declare(strict_types=1);

namespace Sanchescom\Wifi\System\Windows;

trait UtilityTrait
{
    /**
     * @return string
     */
    public function getUtility()
    {
        return 'netsh wlan';
    }
}
