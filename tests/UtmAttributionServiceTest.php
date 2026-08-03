<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UtmAttributionService::class)]
final class UtmAttributionServiceTest
{
    private FrozenClock $clock;
    private InMemoryUtmAttributionRepository $repository;
    private UtmAttributionService $service;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')));
        $this->repository = new InMemoryUtmAttributionRepository($this->clock);
        $this->service = new UtmAttributionService($this->repository);
    }

    public function writesOneRowPerTouchpoint(): void
    {
        $created = $this->service->record($this->event(UtmHistory::of(
            $this->touchpoint('google', '2026-07-01 10:00:00'),
            $this->touchpoint('newsletter', '2026-07-20 10:00:00'),
        )));

        Assert::same($created, 2);
        Assert::same($this->repository->countByEntity('user-1'), 2);
    }

    public function writesOldestTouchpointFirst(): void
    {
        $this->service->record($this->event(UtmHistory::of(
            $this->touchpoint('newsletter', '2026-07-20 10:00:00'),
            $this->touchpoint('google', '2026-07-01 10:00:00'),
        )));

        Assert::same($this->repository->findFirst('user-1')?->attribution()->touchpoint->utm->source, 'google');
        Assert::same($this->repository->findLast('user-1')?->attribution()->touchpoint->utm->source, 'newsletter');
    }

    public function retryOfTheSameEventCreatesNothing(): void
    {
        $event = $this->event(UtmHistory::of($this->touchpoint('google', '2026-07-01 10:00:00')));

        Assert::same($this->service->record($event), 1);
        Assert::same($this->service->record($event), 0);
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function newEventWithIdenticalCampaignCreatesARow(): void
    {
        $history = UtmHistory::of($this->touchpoint('google', '2026-07-01 10:00:00'));

        $this->service->record($this->event($history, eventId: 'order-1'));
        $created = $this->service->record($this->event($history, eventId: 'order-2'));

        Assert::same($created, 1);
        Assert::same($this->repository->countByEntity('user-1'), 2);
    }

    public function partialDeliveryHealsOnRedelivery(): void
    {
        $eventId = 'order-1';
        $first = $this->touchpoint('google', '2026-07-01 10:00:00');
        $second = $this->touchpoint('newsletter', '2026-07-20 10:00:00');

        $this->service->record($this->event(UtmHistory::of($first), eventId: $eventId));
        $created = $this->service->record($this->event(UtmHistory::of($first, $second), eventId: $eventId));

        Assert::same($created, 1);
        Assert::same($this->repository->countByEntity('user-1'), 2);
    }

    public function collapsesSimilarTouchpoints(): void
    {
        $service = new UtmAttributionService($this->repository, similarity: UtmSimilarity::SourceMedium);

        $created = $service->record($this->event(UtmHistory::of(
            $this->touchpoint('google', '2026-07-01 10:00:00', 'cpc'),
            $this->touchpoint('google', '2026-07-10 10:00:00', 'cpc'),
            $this->touchpoint('bing', '2026-07-20 10:00:00', 'cpc'),
        )));

        Assert::same($created, 2);
    }

    public function capsTheNumberOfTouchpoints(): void
    {
        $service = new UtmAttributionService($this->repository, maxTouchpoints: 2);

        $created = $service->record($this->event(UtmHistory::of(
            $this->touchpoint('a', '2026-07-01 10:00:00'),
            $this->touchpoint('b', '2026-07-10 10:00:00'),
            $this->touchpoint('c', '2026-07-20 10:00:00'),
        )));

        Assert::same($created, 2);

        $sources = \array_map(
            static fn(UtmAttributionRecord $r): ?string => $r->attribution()->touchpoint->utm->source,
            $this->repository->findByEntity('user-1'),
        );

        Assert::same($sources, ['b', 'c']);
    }

    public function skipsEmptyTouchpoints(): void
    {
        $created = $this->service->record($this->event(UtmHistory::of(
            UtmTouchpoint::of(
                utm: new UtmParameters(),
                occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            ),
        )));

        Assert::same($created, 0);
        Assert::same($this->repository->countByEntity('user-1'), 0);
    }

    public function continuesPastAnEmptyTouchpointWhenRecording(): void
    {
        $empty = UtmTouchpoint::of(
            utm: new UtmParameters(),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
        );
        $valid = $this->touchpoint('google', '2026-07-20 10:00:00');

        $created = $this->service->record($this->event(UtmHistory::of($valid, $empty)));

        Assert::same($created, 1);
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function emptyHistoryWritesNothing(): void
    {
        Assert::same($this->service->record($this->event(UtmHistory::empty())), 0);
    }

    public function separatesInteractionTypes(): void
    {
        $history = UtmHistory::of($this->touchpoint('google', '2026-07-01 10:00:00'));

        $this->service->record($this->event($history, eventId: 'e-1', type: InteractionType::registration()));
        $this->service->record($this->event($history, eventId: 'e-2', type: InteractionType::purchase()));

        Assert::same($this->repository->countByEntity('user-1', InteractionType::registration()), 1);
        Assert::same($this->repository->countByEntity('user-1', InteractionType::purchase()), 1);
    }

    #[Property(runs: 200)]
    public function repeatedDeliveryIsIdempotent(int $deliveries, int $touchpoints): void
    {
        $repository = new InMemoryUtmAttributionRepository($this->clock);
        $service = new UtmAttributionService($repository);
        $history = UtmHistory::empty();

        for ($i = 0; $i < $touchpoints; ++$i) {
            $history = $history->with($this->touchpoint('src-' . $i, \sprintf('2026-07-%02d 10:00:00', $i + 1)));
        }

        $event = $this->event($history);

        for ($i = 0; $i < $deliveries; ++$i) {
            $service->record($event);
        }

        Assert::same($repository->countByEntity('user-1'), $deliveries > 0 ? \min($touchpoints, 5) : 0);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function repeatedDeliveryIsIdempotentGenerators(): array
    {
        return [
            'deliveries' => Gen::intBetween(0, 4),
            'touchpoints' => Gen::intBetween(0, 7),
        ];
    }

    private function event(
        UtmHistory $history,
        string $eventId = 'order-1',
        ?InteractionType $type = null,
    ): UtmAttributionEvent {
        return new UtmAttributionEvent(
            entityId: 'user-1',
            eventId: $eventId,
            interactionType: $type ?? InteractionType::purchase(),
            history: $history,
        );
    }

    private function touchpoint(string $source, string $time, ?string $medium = null): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: $source, medium: $medium),
            occurredAt: new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
        );
    }
}
