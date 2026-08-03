<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed accessors for what the capture middleware put into the request, so
 * consumers do not cast `mixed` by hand.
 *
 * @api
 */
final readonly class UtmRequest
{
    public static function current(ServerRequestInterface $request): ?UtmTouchpoint
    {
        return self::touchpoint($request->getAttribute(UtmAttributes::CURRENT));
    }

    public static function history(ServerRequestInterface $request): UtmHistory
    {
        return self::storedHistory($request->getAttribute(UtmAttributes::HISTORY));
    }

    public static function effective(ServerRequestInterface $request): ?UtmTouchpoint
    {
        return self::touchpoint($request->getAttribute(UtmAttributes::EFFECTIVE));
    }

    private static function touchpoint(mixed $value): ?UtmTouchpoint
    {
        return $value instanceof UtmTouchpoint ? $value : null;
    }

    private static function storedHistory(mixed $value): UtmHistory
    {
        return $value instanceof UtmHistory ? $value : UtmHistory::empty();
    }
}
