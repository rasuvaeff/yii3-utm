<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Classifies a touchpoint into a marketing {@see Channel}.
 *
 * @api
 */
interface ChannelResolver
{
    public function resolve(UtmTouchpoint $touchpoint): Channel;
}
