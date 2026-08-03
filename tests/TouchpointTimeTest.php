<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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

    public function clampsTheFutureToNow(): void
    {
        $clamped = TouchpointTime::clamp(
            new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC')),
            $this->clock,
            86400,
        );

        Assert::same($clamped->format('Y-m-d H:i:s'), '2026-08-01 12:00:00');
    }

    public function clampsThePastToTheOldestAcceptedMoment(): void
    {
        $clamped = TouchpointTime::clamp(
            new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC')),
            $this->clock,
            86400,
        );

        Assert::same($clamped->format('Y-m-d H:i:s'), '2026-07-31 12:00:00');
    }

    public function keepsAMomentInsideTheWindow(): void
    {
        $inside = new \DateTimeImmutable('2026-08-01 06:00:00', new \DateTimeZone('UTC'));

        Assert::same(TouchpointTime::clamp($inside, $this->clock, 86400)->format('Y-m-d H:i:s'), '2026-08-01 06:00:00');
    }

    #[Property(runs: 300)]
    public function clampedMomentAlwaysLandsInsideTheWindow(int $offsetSeconds, int $maxAge): void
    {
        $claimed = $this->clock->now()->modify(\sprintf('%+d seconds', $offsetSeconds));
        $clamped = TouchpointTime::clamp($claimed, $this->clock, $maxAge);

        $now = $this->clock->now();
        $earliest = $now->sub(new \DateInterval('PT' . $maxAge . 'S'));

        Assert::true($clamped <= $now && $clamped >= $earliest);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function clampedMomentAlwaysLandsInsideTheWindowGenerators(): array
    {
        return [
            'offsetSeconds' => Gen::intBetween(-10_000_000, 10_000_000),
            'maxAge' => Gen::intBetween(0, 1_000_000),
        ];
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
