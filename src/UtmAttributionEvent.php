<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * A business event worth attributing: a registration, a purchase, anything the
 * application decides to track.
 *
 * `eventId` is the idempotency key and belongs to the application: it must stay
 * the same across retries of one event and differ for a new one. Reusing an id
 * suppresses the new rows; minting a fresh one on every retry duplicates them.
 *
 * @api
 */
final readonly class UtmAttributionEvent
{
    /**
     * @param non-empty-string $entityId
     * @param non-empty-string $eventId
     */
    public function __construct(
        public string $entityId,
        public string $eventId,
        public InteractionType $interactionType,
        public UtmHistory $history,
    ) {}
}
