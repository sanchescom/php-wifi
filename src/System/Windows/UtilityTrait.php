<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Windows;

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
