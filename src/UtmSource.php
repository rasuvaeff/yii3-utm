<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Extracts the touchpoint this request carries, if any.
 *
 * Sources are ordered by the middleware and the first non-null result wins, so
 * an application chooses its transport by configuration rather than by picking
 * one of several middleware classes.
 *
 * @api
 */
interface UtmSource
{
    public function extract(ServerRequestInterface $request): ?UtmTouchpoint;
}
