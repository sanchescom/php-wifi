<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Mac;

trait UtilityTrait
{
    /**
     * @return string
     */
    public function getUtility()
    {
        return 'networksetup';
    }
}
