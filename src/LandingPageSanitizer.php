<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Reduces an inbound URL to what is safe to store.
 *
 * Landing pages and referrers routinely carry tokens, e-mail addresses and
 * session identifiers in their query strings; storing them verbatim would put
 * that data into the attribution journal.
 *
 * @api
 */
interface LandingPageSanitizer
{
    /**
     * @return string sanitised absolute URL, or an empty string when nothing is left
     */
    public function sanitize(string $url): string;
}
