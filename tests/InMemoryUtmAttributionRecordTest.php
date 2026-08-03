<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRecord;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(InMemoryUtmAttributionRecord::class)]
final class InMemoryUtmAttributionRecordTest
{
    public function exposesStoredValues(): void
    {
        $attribution = $this->attribution();
        $recordedAt = new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC'));
        $record = new InMemoryUtmAttributionRecord('7', $attribution, $recordedAt);

        Assert::same($record->id(), '7');
        Assert::same($record->attribution(), $attribution);
        Assert::same($record->recordedAt(), $recordedAt);
    }

    private function attribution(): UtmAttribution
    {
        return new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'google'),
                occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            ),
        );
    }
}
