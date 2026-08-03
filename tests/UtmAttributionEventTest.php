<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmAttributionEvent::class)]
final class UtmAttributionEventTest
{
    public function exposesTheBusinessEvent(): void
    {
        $history = UtmHistory::empty();
        $type = InteractionType::purchase();
        $event = new UtmAttributionEvent('user-1', 'order-1', $type, $history);

        Assert::same($event->entityId, 'user-1');
        Assert::same($event->eventId, 'order-1');
        Assert::same($event->interactionType, $type);
        Assert::same($event->history, $history);
    }
}
