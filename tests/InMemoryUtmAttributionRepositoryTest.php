<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRecord;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmAttributionRepository;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(InMemoryUtmAttributionRepository::class)]
final class InMemoryUtmAttributionRepositoryTest
{
    private FrozenClock $clock;
    private InMemoryUtmAttributionRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')));
        $this->repository = new InMemoryUtmAttributionRepository($this->clock);
    }

    public function appendStoresTheRow(): void
    {
        Assert::true($this->repository->append($this->attribution()));
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function appendIsIdempotent(): void
    {
        Assert::true($this->repository->append($this->attribution()));
        Assert::false($this->repository->append($this->attribution()));
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function distinctEventsCreateDistinctRows(): void
    {
        $this->repository->append($this->attribution(eventId: 'order-1'));
        $this->repository->append($this->attribution(eventId: 'order-2'));

        Assert::same($this->repository->countByEntity('user-1'), 2);
    }

    public function recordCarriesTheStoredAttribution(): void
    {
        $this->repository->append($this->attribution());
        $record = $this->repository->findFirst('user-1');

        Assert::instanceOf($record, InMemoryUtmAttributionRecord::class);
        Assert::same($record?->attribution()->entityId, 'user-1');
        Assert::same($record?->recordedAt()->format('Y-m-d H:i:s'), '2026-08-01 10:00:00');
        Assert::same($record?->id(), '1');
    }

    public function findFirstAndLastFollowInsertionOrder(): void
    {
        $this->appendAtSecond(1, 'order-1', 'google');
        $this->appendAtSecond(2, 'order-2', 'bing');
        $this->appendAtSecond(3, 'order-3', 'newsletter');

        Assert::same($this->repository->findFirst('user-1')?->attribution()->touchpoint->utm->source, 'google');
        Assert::same($this->repository->findLast('user-1')?->attribution()->touchpoint->utm->source, 'newsletter');
    }

    public function claimedTimeDoesNotChangeTheFirstTouch(): void
    {
        $this->appendAtSecond(1, 'order-1', 'google');

        // A later delivery claims to have happened years earlier.
        $backdated = new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-2',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'spoofed'),
                occurredAt: new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC')),
            ),
        );

        $this->clock->advance(60);
        $this->repository->append($backdated);

        Assert::same($this->repository->findFirst('user-1')?->attribution()->touchpoint->utm->source, 'google');
    }

    public function scopesQueriesByInteractionType(): void
    {
        $this->repository->append($this->attribution(eventId: 'order-1'));
        $this->repository->append($this->attribution(eventId: 'reg-1', type: InteractionType::registration()));

        Assert::same($this->repository->countByEntity('user-1'), 2);
        Assert::same($this->repository->countByEntity('user-1', InteractionType::purchase()), 1);
        Assert::same(
            $this->repository->findFirst('user-1', InteractionType::registration())?->attribution()->eventId,
            'reg-1',
        );
    }

    public function separatesEntities(): void
    {
        $this->repository->append($this->attribution(entityId: 'user-1'));
        $this->repository->append($this->attribution(entityId: 'user-2'));

        Assert::same($this->repository->countByEntity('user-1'), 1);
        Assert::null($this->repository->findFirst('user-3'));
        Assert::null($this->repository->findLast('user-3'));
    }

    public function findByEntityPaginates(): void
    {
        $this->appendAtSecond(1, 'order-1', 'a');
        $this->appendAtSecond(2, 'order-2', 'b');
        $this->appendAtSecond(3, 'order-3', 'c');

        $page = $this->repository->findByEntity('user-1', limit: 2, offset: 1);

        Assert::same(\count($page), 2);
        Assert::same($page[0]->attribution()->touchpoint->utm->source, 'b');
    }

    public function findByEntityClampsAnOversizedLimit(): void
    {
        for ($i = 0; $i < UtmAttributionRepository::MAX_LIMIT + 5; ++$i) {
            $this->appendAtSecond($i, 'order-' . $i, 'a');
        }

        $page = $this->repository->findByEntity('user-1', limit: PHP_INT_MAX);

        Assert::same(\count($page), UtmAttributionRepository::MAX_LIMIT);
    }

    public function deleteByEntityRemovesEverythingForThatEntity(): void
    {
        $this->repository->append($this->attribution(entityId: 'user-1', eventId: 'order-1'));
        $this->repository->append($this->attribution(entityId: 'user-1', eventId: 'order-2'));
        $this->repository->append($this->attribution(entityId: 'user-2'));

        Assert::same($this->repository->deleteByEntity('user-1'), 2);
        Assert::same($this->repository->countByEntity('user-1'), 0);
        Assert::same($this->repository->countByEntity('user-2'), 1);
    }

    public function purgeOlderThanDropsEarlierRows(): void
    {
        $this->appendAtSecond(0, 'order-1', 'old');
        $this->clock->advance(3600);
        $this->repository->append($this->attribution(eventId: 'order-2'));

        $removed = $this->repository->purgeOlderThan(
            new \DateTimeImmutable('2026-08-01 10:30:00', new \DateTimeZone('UTC')),
        );

        Assert::same($removed, 1);
        Assert::same($this->repository->countByEntity('user-1'), 1);
        Assert::same($this->repository->findFirst('user-1')?->attribution()->eventId, 'order-2');
    }

    public function purgeOlderThanKeepsARowRecordedExactlyAtTheBoundary(): void
    {
        $this->repository->append($this->attribution());

        $removed = $this->repository->purgeOlderThan(
            new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
        );

        Assert::same($removed, 0);
        Assert::same($this->repository->countByEntity('user-1'), 1);
    }

    public function countOlderThanMatchesWhatPurgeOlderThanWouldRemove(): void
    {
        $this->appendAtSecond(0, 'order-1', 'old');
        $this->clock->advance(3600);
        $this->repository->append($this->attribution(eventId: 'order-2'));

        $count = $this->repository->countOlderThan(
            new \DateTimeImmutable('2026-08-01 10:30:00', new \DateTimeZone('UTC')),
        );

        Assert::same($count, 1);
        Assert::same($this->repository->countByEntity('user-1'), 2);
    }

    public function countOlderThanKeepsARowRecordedExactlyAtTheBoundaryOutOfTheCount(): void
    {
        $this->repository->append($this->attribution());

        $count = $this->repository->countOlderThan(
            new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
        );

        Assert::same($count, 0);
    }

    public function matchingContinuesPastRecordsOfOtherEntities(): void
    {
        $this->repository->append($this->attribution(entityId: 'user-1'));
        $this->repository->append($this->attribution(entityId: 'user-2', eventId: 'order-a'));
        $this->repository->append($this->attribution(entityId: 'user-2', eventId: 'order-b'));

        Assert::same($this->repository->countByEntity('user-2'), 2);
    }

    public function breaksNumericTiebreakersByIntegerValue(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->repository->append($this->attribution(eventId: 'order-' . $i, source: 'src-' . $i));
        }

        Assert::same($this->repository->findFirst('user-1')?->id(), '1');
        Assert::same($this->repository->findLast('user-1')?->id(), '10');
    }

    public function ordersByRecordedAtWhenTimestampsDiffer(): void
    {
        $this->repository->append($this->attribution(eventId: 'later'));
        $this->clock->advance(-120);
        $this->repository->append($this->attribution(eventId: 'earlier', source: 'old'));

        Assert::same($this->repository->findFirst('user-1')?->attribution()->eventId, 'earlier');
        Assert::same($this->repository->findLast('user-1')?->attribution()->eventId, 'later');
    }

    #[Property(runs: 200)]
    public function repeatedAppendsOfOneEventLeaveOneRow(int $times): void
    {
        $repository = new InMemoryUtmAttributionRepository($this->clock);

        for ($i = 0; $i < $times; ++$i) {
            $repository->append($this->attribution());
        }

        Assert::same($repository->countByEntity('user-1'), $times > 0 ? 1 : 0);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function repeatedAppendsOfOneEventLeaveOneRowGenerators(): array
    {
        return ['times' => Gen::intBetween(0, 8)];
    }

    #[Property(runs: 200)]
    public function distinctEventIdsStayDistinct(int $count): void
    {
        $repository = new InMemoryUtmAttributionRepository($this->clock);

        for ($i = 0; $i < $count; ++$i) {
            $repository->append($this->attribution(eventId: 'order-' . $i));
        }

        Assert::same($repository->countByEntity('user-1'), $count);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function distinctEventIdsStayDistinctGenerators(): array
    {
        return ['count' => Gen::intBetween(0, 8)];
    }

    private function appendAtSecond(int $second, string $eventId, string $source): void
    {
        $this->clock->advance($second);
        $this->repository->append(
            new UtmAttribution(
                entityId: 'user-1',
                eventId: $eventId,
                interactionType: InteractionType::purchase(),
                touchpoint: UtmTouchpoint::of(
                    utm: new UtmParameters(source: $source),
                    occurredAt: new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
                ),
            ),
        );
    }

    private function attribution(
        string $entityId = 'user-1',
        string $eventId = 'order-1',
        ?InteractionType $type = null,
        string $source = 'google',
    ): UtmAttribution {
        return new UtmAttribution(
            entityId: $entityId,
            eventId: $eventId,
            interactionType: $type ?? InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: $source, medium: 'cpc'),
                occurredAt: new \DateTimeImmutable('2026-08-01 09:00:00', new \DateTimeZone('UTC')),
            ),
        );
    }
}
