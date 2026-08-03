<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Names of the request attributes the capture middleware always sets — even
 * when there is nothing to report, so consumers never have to distinguish
 * "absent" from "empty".
 *
 * @api
 */
final readonly class UtmAttributes
{
    /** Touchpoint carried by this very request, or `null`. */
    public const string CURRENT = 'utm_current';

    /** Stored history, possibly empty. */
    public const string HISTORY = 'utm_history';

    /** Current touchpoint, falling back to the newest stored one. */
    public const string EFFECTIVE = 'utm';
}
