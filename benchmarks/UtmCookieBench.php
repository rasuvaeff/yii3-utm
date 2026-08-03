<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Benchmarks;

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Bench;

final class UtmCookieBench
{
    private static ?UtmCookieCodec $codec = null;
    private static ?UtmHistory $history = null;
    private static ?string $encoded = null;

    #[Bench(
        callables: [
            'decode-five-touchpoints' => [self::class, 'decodeHistory'],
        ],
        calls: 10_000,
        iterations: 10,
    )]
    public static function encodeHistory(): string
    {
        return self::codec()->encode(self::history());
    }

    public static function decodeHistory(): UtmHistory
    {
        return self::codec()->decode(self::$encoded ??= self::codec()->encode(self::history()));
    }

    private static function codec(): UtmCookieCodec
    {
        return self::$codec ??= new UtmCookieCodec();
    }

    private static function history(): UtmHistory
    {
        if (self::$history instanceof UtmHistory) {
            return self::$history;
        }

        $touchpoints = [];

        for ($day = 1; $day <= 5; ++$day) {
            $touchpoints[] = UtmTouchpoint::of(
                utm: new UtmParameters(
                    source: 'source-' . $day,
                    medium: 'cpc',
                    campaign: 'summer-' . $day,
                ),
                occurredAt: new \DateTimeImmutable(
                    \sprintf('2026-07-%02d 10:00:00', $day),
                    new \DateTimeZone('UTC'),
                ),
                clickIds: ClickIds::fromArray(['gclid' => 'click-' . $day]),
                landingPage: 'https://shop.example.com/landing/' . $day,
            );
        }

        return self::$history = UtmHistory::of(...$touchpoints);
    }
}
