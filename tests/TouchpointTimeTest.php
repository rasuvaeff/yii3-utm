<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\TouchpointTime;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(TouchpointTime::class)]
final class TouchpointTimeTest
{
    private FrozenClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
    }

    #[DataProvider('parsedValuesProvider')]
    public function parsesSupportedFormats(?string $value, string $expected): void
    {
        Assert::same(TouchpointTime::parse($value, $this->clock)->format('Y-m-d H:i:s'), $expected);
    }

    public static function parsedValuesProvider(): iterable
    {
        yield 'unix timestamp' => ['1751364000', '2025-07-01 10:00:00'];

        yield 'iso 8601' => ['2026-07-01T10:00:00+00:00', '2026-07-01 10:00:00'];

        yield 'date only' => ['2026-07-01', '2026-07-01 00:00:00'];

        yield 'null falls back to now' => [null, '2026-08-01 12:00:00'];

        yield 'empty falls back to now' => ['', '2026-08-01 12:00:00'];

        yield 'blank falls back to now' => ['   ', '2026-08-01 12:00:00'];

        yield 'garbage falls back to now' => ['not a date at all', '2026-08-01 12:00:00'];

        yield 'padded unix timestamp' => ['  1751364000', '2025-07-01 10:00:00'];
    }

    public function capsTheFutureToNow(): void
    {
        $capped = TouchpointTime::withinWindow(
            new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC')),
            $this->clock,
            86400,
        );

        Assert::same($capped?->format('Y-m-d H:i:s'), '2026-08-01 12:00:00');
    }

    /**
     * A stale claim is rejected, not moved to the window boundary. Moving it
     * used to invent a timestamp that changed every day and, because the moved
     * value always lands back inside the window, kept the touchpoint alive
     * forever.
     */
    public function rejectsAMomentOlderThanTheWindow(): void
    {
        Assert::null(TouchpointTime::withinWindow(
            new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC')),
            $this->clock,
            86400,
        ));
    }

    public function keepsAMomentInsideTheWindowUntouched(): void
    {
        $inside = new \DateTimeImmutable('2026-08-01 06:00:00', new \DateTimeZone('UTC'));

        Assert::same(TouchpointTime::withinWindow($inside, $this->clock, 86400), $inside);
    }

    public function keepsAMomentExactlyOnTheBoundary(): void
    {
        $boundary = new \DateTimeImmutable('2026-07-31 12:00:00', new \DateTimeZone('UTC'));

        Assert::same(TouchpointTime::withinWindow($boundary, $this->clock, 86400), $boundary);
    }

    /**
     * The claim is a distinct object equal to the clock's `now` on purpose: it
     * pins down which of the two is returned, and `>=` instead of `>` returns
     * the clock's instance.
     */
    public function keepsAMomentExactlyAtNow(): void
    {
        $now = new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC'));

        Assert::same(TouchpointTime::withinWindow($now, $this->clock, 86400), $now);
        Assert::false($now === $this->clock->now());
    }

    #[Property(runs: 300)]
    public function anAcceptedMomentAlwaysLandsInsideTheWindow(int $offsetSeconds, int $maxAge): void
    {
        $claimed = $this->clock->now()->modify(\sprintf('%+d seconds', $offsetSeconds));
        $result = TouchpointTime::withinWindow($claimed, $this->clock, $maxAge);

        $now = $this->clock->now();
        $earliest = $now->sub(new \DateInterval('PT' . $maxAge . 'S'));

        Classify::cover($offsetSeconds > 0, 'future claim capped to now', 5.0);
        Classify::cover(!$result instanceof \DateTimeImmutable, 'stale claim rejected', 5.0);
        Classify::cover($result === $claimed, 'claim accepted unchanged', 5.0);

        if (!$result instanceof \DateTimeImmutable) {
            Assert::true($claimed < $earliest);

            return;
        }

        Assert::true($result <= $now && $result >= $earliest);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anAcceptedMomentAlwaysLandsInsideTheWindowGenerators(): array
    {
        return [
            'offsetSeconds' => Gen::intBetween(-10_000_000, 10_000_000),
            'maxAge' => Gen::intBetween(0, 1_000_000),
        ];
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function anAcceptedMomentAlwaysLandsInsideTheWindowExamples(): iterable
    {
        yield 'exactly now' => [0, 86_400];

        yield 'exactly on the boundary' => [-86_400, 86_400];

        yield 'one second past the boundary' => [-86_401, 86_400];

        yield 'one second into the future' => [1, 86_400];

        yield 'zero-width window' => [0, 0];
    }

    #[Property(runs: 200)]
    public function parsingNeverThrows(string $value): void
    {
        Assert::instanceOf(TouchpointTime::parse($value, $this->clock), \DateTimeImmutable::class);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function parsingNeverThrowsGenerators(): array
    {
        return ['value' => Gen::stringAscii()];
    }
}
