<?php

declare(strict_types=1);

namespace Sanchescom\Wifi\System\Linux;

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
