<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Utm\InMemoryUtmAttributionRepository;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttributionEvent;
use Rasuvaeff\Yii3Utm\UtmAttributionRecord;
use Rasuvaeff\Yii3Utm\UtmAttributionService;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;

require_once __DIR__ . '/../vendor/autoload.php';

$utc = new DateTimeZone('UTC');

$clock = new class ($utc) implements ClockInterface {
    private DateTimeImmutable $now;

    public function __construct(DateTimeZone $utc)
    {
        $this->now = new DateTimeImmutable('2026-08-01 12:00:00', $utc);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
};

// In a real application the repository comes from rasuvaeff/yii3-utm-db.
$repository = new InMemoryUtmAttributionRepository($clock);
$service = new UtmAttributionService($repository);

$touchpoint = static fn(string $source, string $medium, string $day): UtmTouchpoint => UtmTouchpoint::of(
    utm: new UtmParameters(source: $source, medium: $medium),
    occurredAt: new DateTimeImmutable("2026-07-{$day} 10:00:00", new DateTimeZone('UTC')),
);

$history = UtmHistory::of(
    $touchpoint('google', 'cpc', '01'),
    $touchpoint('newsletter', 'email', '20'),
);

$purchase = new UtmAttributionEvent(
    entityId: 'user-42',
    eventId: 'order-7',
    interactionType: InteractionType::purchase(),
    history: $history,
);

printf("first delivery ..... %d rows created\n", $service->record($purchase));

// The same business event is delivered again — a queue retry, a double click,
// a webhook redelivery. Nothing new is written.
$clock->advance(30);
printf("retry .............. %d rows created\n", $service->record($purchase));

// A different business event with the very same campaign history does write.
$clock->advance(30);
$second = new UtmAttributionEvent(
    entityId: 'user-42',
    eventId: 'order-8',
    interactionType: InteractionType::purchase(),
    history: $history,
);
printf("second purchase .... %d rows created\n", $service->record($second));

printf("\njournal for user-42: %d rows\n", $repository->countByEntity('user-42'));

foreach ($repository->findByEntity('user-42') as $record) {
    printf(
        "  #%-2s recorded %s  event %-8s %-10s %s\n",
        $record->id(),
        $record->recordedAt()->format('H:i:s'),
        $record->attribution()->eventId,
        (string) $record->attribution()->touchpoint->utm->source,
        substr($record->attribution()->dedupeKey, 0, 12) . '…',
    );
}

$first = $repository->findFirst('user-42');
$last = $repository->findLast('user-42');

printf("\nfirst touch ........ %s\n", (string) $first?->attribution()->touchpoint->utm->source);
printf("last touch ......... %s\n", (string) $last?->attribution()->touchpoint->utm->source);

// Retention and personal-data erasure are part of the repository contract.
$removed = $repository->purgeOlderThan(new DateTimeImmutable('2026-08-01 12:00:30', $utc));
printf("\npurged by retention  %d rows\n", $removed);

$erased = $repository->deleteByEntity('user-42');
printf("erased on request .. %d rows\n", $erased);
printf("left ............... %d rows\n", $repository->countByEntity('user-42'));

// The record type is what consumers program against.
assert($first instanceof UtmAttributionRecord);
