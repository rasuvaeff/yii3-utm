<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;

/**
 * Reference implementation kept in process memory.
 *
 * Useful for testing consumer code and for running the package without a
 * storage adapter. It is shipped but **never bound in the container**: binding
 * the repository is the job of a backend package or of the application.
 *
 * @api
 */
final class InMemoryUtmAttributionRepository implements UtmAttributionRepository
{
    /**
     * @var array<non-empty-string, InMemoryUtmAttributionRecord> keyed by dedupe key
     */
    private array $records = [];

    /**
     * @var int<0, max>
     */
    private int $sequence = 0;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public function append(UtmAttribution $attribution): bool
    {
        if (isset($this->records[$attribution->dedupeKey])) {
            return false;
        }

        ++$this->sequence;

        $this->records[$attribution->dedupeKey] = new InMemoryUtmAttributionRecord(
            id: (string) $this->sequence,
            attribution: $attribution,
            recordedAt: $this->clock->now(),
        );

        return true;
    }

    #[\Override]
    public function findByEntity(
        string $entityId,
        ?InteractionType $interactionType = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        return \array_slice(
            $this->matching($entityId, $interactionType),
            $offset,
            \min($limit, self::MAX_LIMIT),
        );
    }

    #[\Override]
    public function findFirst(string $entityId, ?InteractionType $interactionType = null): ?UtmAttributionRecord
    {
        return $this->matching($entityId, $interactionType)[0] ?? null;
    }

    #[\Override]
    public function findLast(string $entityId, ?InteractionType $interactionType = null): ?UtmAttributionRecord
    {
        $matching = $this->matching($entityId, $interactionType);

        return $matching === [] ? null : $matching[\count($matching) - 1];
    }

    #[\Override]
    public function countByEntity(string $entityId, ?InteractionType $interactionType = null): int
    {
        return \count($this->matching($entityId, $interactionType));
    }

    #[\Override]
    public function deleteByEntity(string $entityId): int
    {
        return $this->removeWhere(
            static fn(InMemoryUtmAttributionRecord $r): bool => $r->attribution()->entityId === $entityId,
        );
    }

    #[\Override]
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->removeWhere(
            static fn(InMemoryUtmAttributionRecord $r): bool => $r->recordedAt() < $before,
        );
    }

    #[\Override]
    public function countOlderThan(\DateTimeImmutable $before): int
    {
        $count = 0;

        foreach ($this->records as $record) {
            if ($record->recordedAt() < $before) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<InMemoryUtmAttributionRecord> canonical order: recorded first comes first
     */
    private function matching(string $entityId, ?InteractionType $interactionType): array
    {
        $matching = [];

        foreach ($this->records as $record) {
            $attribution = $record->attribution();

            if ($attribution->entityId !== $entityId) {
                continue;
            }

            if ($interactionType instanceof InteractionType && !$attribution->interactionType->equals($interactionType)) {
                continue;
            }

            $matching[] = $record;
        }

        \usort(
            $matching,
            static fn(InMemoryUtmAttributionRecord $a, InMemoryUtmAttributionRecord $b): int
                => ($a->recordedAt() <=> $b->recordedAt()) ?: ((int) $a->id() <=> (int) $b->id()),
        );

        return $matching;
    }

    /**
     * @param \Closure(InMemoryUtmAttributionRecord): bool $predicate
     *
     * @return int<0, max>
     */
    private function removeWhere(\Closure $predicate): int
    {
        $removed = 0;

        foreach ($this->records as $key => $record) {
            if ($predicate($record)) {
                unset($this->records[$key]);
                ++$removed;
            }
        }

        return $removed;
    }
}
