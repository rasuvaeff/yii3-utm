<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmHistory::class)]
final class UtmHistoryTest
{
    public function emptyHistoryHasNoTouchpoints(): void
    {
        $history = UtmHistory::empty();

        Assert::true($history->isEmpty());
        Assert::same($history->count(), 0);
        Assert::null($history->latest());
        Assert::null($history->oldest());
        Assert::same($history->all(), []);
    }

    public function sortsNewestFirst(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('older', '2026-07-01 10:00:00'),
            $this->touchpoint('newer', '2026-08-01 10:00:00'),
            $this->touchpoint('middle', '2026-07-15 10:00:00'),
        );

        Assert::same(\array_map(static fn(UtmTouchpoint $t): ?string => $t->utm->source, $history->all()), ['newer', 'middle', 'older']);
        Assert::same($history->latest()?->utm->source, 'newer');
        Assert::same($history->oldest()?->utm->source, 'older');
    }

    public function withKeepsOrdering(): void
    {
        $history = UtmHistory::of($this->touchpoint('a', '2026-07-01 10:00:00'))
            ->with($this->touchpoint('b', '2026-08-01 10:00:00'));

        Assert::same($history->count(), 2);
        Assert::same($history->latest()?->utm->source, 'b');
    }

    public function withKeepsAllPriorTouchpoints(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('a', '2026-06-01 10:00:00'),
            $this->touchpoint('b', '2026-07-01 10:00:00'),
        )->with($this->touchpoint('c', '2026-08-01 10:00:00'));

        Assert::same($history->count(), 3);
    }

    public function isIterable(): void
    {
        $history = UtmHistory::of($this->touchpoint('a', '2026-07-01 10:00:00'));

        Assert::same(\iterator_to_array($history->getIterator()), $history->all());
    }

    public function limitedKeepsNewest(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('a', '2026-06-01 10:00:00'),
            $this->touchpoint('b', '2026-07-01 10:00:00'),
            $this->touchpoint('c', '2026-08-01 10:00:00'),
        )->limited(2);

        Assert::same(\array_map(static fn(UtmTouchpoint $t): ?string => $t->utm->source, $history->all()), ['c', 'b']);
    }

    public function limitedIsANoOpWhenSmallEnough(): void
    {
        $history = UtmHistory::of($this->touchpoint('a', '2026-06-01 10:00:00'));

        Assert::same($history->limited(5)->count(), 1);
    }

    public function limitedReturnsTheSameInstanceAtTheBoundary(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('a', '2026-06-01 10:00:00'),
            $this->touchpoint('b', '2026-07-01 10:00:00'),
        );

        Assert::same($history->limited(2), $history);
    }

    public function deduplicatedKeepsTheOldestOfEachGroup(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('google', '2026-06-01 10:00:00', 'cpc', 'first'),
            $this->touchpoint('google', '2026-07-01 10:00:00', 'cpc', 'second'),
            $this->touchpoint('bing', '2026-08-01 10:00:00', 'cpc', 'other'),
        )->deduplicated(UtmSimilarity::SourceMedium);

        Assert::same($history->count(), 2);
        Assert::same($history->oldest()?->utm->campaign, 'first');
    }

    public function deduplicatedKeepsDistinctTouchpoints(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('google', '2026-06-01 10:00:00'),
            $this->touchpoint('bing', '2026-07-01 10:00:00'),
        )->deduplicated(UtmSimilarity::Full);

        Assert::same($history->count(), 2);
    }

    #[Property(runs: 200)]
    public function limitedNeverExceedsTheCap(array $offsets, int $max): void
    {
        $history = $this->historyOf($offsets);

        // `count() <= $max` is satisfied by any history shorter than the cap
        // without the cap doing anything. Both sides have to occur for the
        // property to be about `limited()` at all.
        Classify::cover($history->count() > $max, 'the cap actually binds', 15.0);
        Classify::cover($history->count() <= $max, 'the history is already short enough', 25.0);
        Classify::when($max === 0, 'a cap of zero');

        Assert::true($history->limited($max)->count() <= $max);
    }

    /**
     * @return iterable<string, array{list<int>, int}>
     */
    public static function limitedNeverExceedsTheCapExamples(): iterable
    {
        yield 'empty history, zero cap' => [[], 0];
        yield 'one touchpoint, zero cap' => [[0], 0];
        yield 'exactly at the cap' => [[0, 1, 2], 3];
        yield 'one over the cap' => [[0, 1, 2, 3], 3];
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function limitedNeverExceedsTheCapGenerators(): array
    {
        return [
            'offsets' => Gen::arrayOf(Gen::intBetween(0, 100), 0, 12),
            'max' => Gen::intBetween(0, 8),
        ];
    }

    #[Property(runs: 200)]
    public function orderingIsIndependentOfInputOrder(array $offsets): void
    {
        $direct = $this->historyOf($offsets);
        $reversed = $this->historyOf(\array_reverse($offsets));

        Assert::same($this->sources($reversed), $this->sources($direct));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function orderingIsIndependentOfInputOrderGenerators(): array
    {
        return ['offsets' => Gen::arrayOf(Gen::intBetween(0, 20), 0, 10)];
    }

    #[Property(runs: 200)]
    public function deduplicationIsIndependentOfInputOrder(array $offsets, UtmSimilarity $similarity): void
    {
        $direct = $this->historyOf($offsets)->deduplicated($similarity);
        $reversed = $this->historyOf(\array_reverse($offsets))->deduplicated($similarity);

        Assert::same($this->sources($reversed), $this->sources($direct));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function deduplicationIsIndependentOfInputOrderGenerators(): array
    {
        return [
            'offsets' => Gen::arrayOf(Gen::intBetween(0, 6), 0, 10),
            'similarity' => Gen::elements(UtmSimilarity::cases()),
        ];
    }

    /**
     * @param list<int> $offsets
     */
    private function historyOf(array $offsets): UtmHistory
    {
        $touchpoints = [];

        foreach ($offsets as $offset) {
            $touchpoints[] = $this->touchpoint(source: 'src-' . ($offset % 3), time: \sprintf('2026-06-%02d 10:00:00', ($offset % 27) + 1), medium: 'cpc', campaign: 'camp-' . ($offset % 2));
        }

        return UtmHistory::of(...$touchpoints);
    }

    /**
     * @return list<string>
     */
    private function sources(UtmHistory $history): array
    {
        return \array_map(
            static fn(UtmTouchpoint $t): string => $t->occurredAt->format('Y-m-d') . '|' . $t->utm->source . '|' . $t->utm->campaign,
            $history->all(),
        );
    }

    private function touchpoint(string $source, string $time, ?string $medium = null, ?string $campaign = null): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: $source, medium: $medium, campaign: $campaign),
            occurredAt: new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
        );
    }
}
