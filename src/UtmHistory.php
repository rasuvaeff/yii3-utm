<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Ordered collection of touchpoints, newest first.
 *
 * The class is immutable, but it is not marked `@psalm-immutable`: ordering
 * goes through `usort()`, which psalm treats as a mutating call, and silencing
 * that would mean a suppression.
 *
 * @implements \IteratorAggregate<int, UtmTouchpoint>
 *
 * @api
 */
final readonly class UtmHistory implements \IteratorAggregate, \Countable
{
    /**
     * @param list<UtmTouchpoint> $touchpoints already sorted, newest first
     */
    private function __construct(
        private array $touchpoints,
    ) {}

    public static function empty(): self
    {
        return new self(touchpoints: []);
    }

    public static function of(UtmTouchpoint ...$touchpoints): self
    {
        return new self(touchpoints: self::sort(\array_values($touchpoints)));
    }

    public function with(UtmTouchpoint $touchpoint): self
    {
        return new self(touchpoints: self::sort([...$this->touchpoints, $touchpoint]));
    }

    /**
     * @return list<UtmTouchpoint>
     */
    public function all(): array
    {
        return $this->touchpoints;
    }

    public function latest(): ?UtmTouchpoint
    {
        return $this->touchpoints[0] ?? null;
    }

    public function oldest(): ?UtmTouchpoint
    {
        return $this->touchpoints === [] ? null : $this->touchpoints[\count($this->touchpoints) - 1];
    }

    /**
     * Collapses touchpoints that {@see UtmSimilarity} considers equivalent.
     *
     * The **oldest** member of each group survives: the first contact with a
     * source is the one worth attributing. The choice must not depend on input
     * order — the fingerprint of the surviving touchpoint decides the dedupe
     * key, so a different survivor on redelivery would write a duplicate row
     * instead of a no-op.
     */
    public function deduplicated(UtmSimilarity $similarity): self
    {
        /** @var list<UtmTouchpoint> $kept */
        $kept = [];

        foreach (\array_reverse($this->touchpoints) as $touchpoint) {
            foreach ($kept as $existing) {
                if ($similarity->isSimilar($existing, $touchpoint)) {
                    continue 2;
                }
            }

            $kept[] = $touchpoint;
        }

        return new self(touchpoints: self::sort($kept));
    }

    /**
     * Keeps at most `$max` newest touchpoints.
     *
     * @param int<0, max> $max
     */
    public function limited(int $max): self
    {
        if (\count($this->touchpoints) <= $max) {
            return $this;
        }

        return new self(touchpoints: \array_slice($this->touchpoints, 0, $max));
    }

    public function isEmpty(): bool
    {
        return $this->touchpoints === [];
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->touchpoints);
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->touchpoints);
    }

    /**
     * @param list<UtmTouchpoint> $touchpoints
     *
     * @return list<UtmTouchpoint>
     */
    private static function sort(array $touchpoints): array
    {
        \usort(
            $touchpoints,
            static fn(UtmTouchpoint $a, UtmTouchpoint $b): int => ($b->occurredAt <=> $a->occurredAt)
                ?: \strcmp(UtmFingerprint::signature($a), UtmFingerprint::signature($b)),
        );

        return $touchpoints;
    }
}
