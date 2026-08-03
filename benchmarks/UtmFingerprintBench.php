<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Benchmarks;

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmFingerprint;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Bench;

final class UtmFingerprintBench
{
    private static ?UtmTouchpoint $touchpoint = null;

    #[Bench(
        callables: [
            'full-similarity' => [self::class, 'compareFullSimilarity'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function buildFingerprint(): string
    {
        return UtmFingerprint::of('user-42', InteractionType::purchase(), self::touchpoint());
    }

    public static function compareFullSimilarity(): bool
    {
        return UtmSimilarity::Full->isSimilar(self::touchpoint(), self::touchpoint());
    }

    private static function touchpoint(): UtmTouchpoint
    {
        return self::$touchpoint ??= UtmTouchpoint::of(
            utm: new UtmParameters(
                source: 'google',
                medium: 'cpc',
                campaign: 'summer-sale',
                term: 'running shoes',
                content: 'hero-banner',
                id: 'campaign-42',
            ),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            clickIds: ClickIds::fromArray(['gclid' => 'EAIaIQobChMI-example']),
            landingPage: 'https://shop.example.com/summer?utm_source=google&utm_medium=cpc',
        );
    }
}
