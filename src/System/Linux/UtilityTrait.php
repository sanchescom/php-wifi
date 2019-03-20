<?php

declare(strict_types=1);

namespace Sanchescom\WiFi\System\Linux;

trait UtilityTrait
{
    /**
     * @return string
     */
    public function getUtility()
    {
        return 'nmcli';
    }
}
