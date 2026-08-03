<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionEventHandler;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UtmAttributionEventHandler::class)]
final class UtmAttributionEventHandlerTest
{
    private InMemoryUtmAttributionRepository $repository;
    private UtmAttributionEventHandler $handler;

    #[BeforeTest]
    public function setUp(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')));
        $this->repository = new InMemoryUtmAttributionRepository($clock);
        $this->handler = new UtmAttributionEventHandler(new UtmAttributionService($this->repository));
    }

    public function passesTheEventToTheService(): void
    {
        ($this->handler)($this->event());

        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function repeatedDispatchOfOneEventStaysIdempotent(): void
    {
        $event = $this->event();

        ($this->handler)($event);
        ($this->handler)($event);

        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    private function event(): UtmAttributionEvent
    {
        return new UtmAttributionEvent(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            history: UtmHistory::of(
                UtmTouchpoint::of(
                    utm: new UtmParameters(source: 'google', medium: 'cpc'),
                    occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
                ),
            ),
        );
    }
}
