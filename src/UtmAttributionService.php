<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Turns one business event into rows of the attribution journal.
 *
 * The history is collapsed by similarity, capped, and written oldest touchpoint
 * first, one row per surviving touchpoint. Deduplication happens per touchpoint
 * rather than per event, which makes a partial write self-healing: redelivering
 * the same event adds whatever is missing and duplicates nothing. No
 * transaction wraps the batch — that property is the reason.
 *
 * Empty touchpoints — no campaign, no click id, no referrer — are skipped: a
 * row that attributes nothing is noise.
 *
 * @api
 */
final readonly class UtmAttributionService
{
    /**
     * @param int<1, max> $maxTouchpoints
     */
    public function __construct(
        private UtmAttributionRepository $repository,
        private UtmSimilarity $similarity = UtmSimilarity::Full,
        private int $maxTouchpoints = 5,
    ) {}

    /**
     * @return int<0, max> number of rows actually created
     */
    public function record(UtmAttributionEvent $event): int
    {
        $touchpoints = $event->history
            ->deduplicated($this->similarity)
            ->limited($this->maxTouchpoints)
            ->all();

        $created = 0;

        foreach (\array_reverse($touchpoints) as $touchpoint) {
            if ($touchpoint->isEmpty()) {
                continue;
            }

            $attribution = new UtmAttribution(
                entityId: $event->entityId,
                eventId: $event->eventId,
                interactionType: $event->interactionType,
                touchpoint: $touchpoint,
            );

            if ($this->repository->append($attribution)) {
                ++$created;
            }
        }

        return $created;
    }
}
