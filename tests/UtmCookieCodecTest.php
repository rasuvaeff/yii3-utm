<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UtmCookieCodec::class)]
final class UtmCookieCodecTest
{
    private UtmCookieCodec $codec;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->codec = new UtmCookieCodec();
    }

    public function encodesVersionedPayload(): void
    {
        $encoded = $this->codec->encode(UtmHistory::of($this->touchpoint('google')));

        Assert::string($encoded)->contains('"v":1');
        Assert::string($encoded)->contains('"google"');
    }

    public function encodesWithoutEscapingSlashes(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            referrer: Referrer::of('https://ads.example.com/summer/sale'),
            landingPage: 'https://shop.example.com/summer/sale',
        );

        $encoded = $this->codec->encode(UtmHistory::of($touchpoint));

        Assert::true(\str_contains($encoded, 'https://shop.example.com/summer/sale'));
        Assert::false(\str_contains($encoded, '\/'));
    }

    public function encodeAcceptsAPayloadAtTheMaxLengthBoundary(): void
    {
        $history = UtmHistory::of($this->touchpoint('google'));
        $full = $this->codec->encode($history);
        $codec = new UtmCookieCodec(maxLength: \strlen($full));

        Assert::same($codec->encode($history), $full);
    }

    public function encodeHandlesATouchpointWithoutReferrer(): void
    {
        $encoded = $this->codec->encode(UtmHistory::of($this->touchpoint('google')));

        Assert::false(\str_contains($encoded, '"r"'));
        Assert::true(\str_contains($encoded, '"s":"google"'));
    }

    public function roundTripsAFullTouchpoint(): void
    {
        $original = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer', term: 't', content: 'c', id: 'x1'),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            clickIds: ClickIds::fromArray(['gclid' => 'abc']),
            referrer: Referrer::of('https://ads.example.com/x'),
            landingPage: 'https://shop.example.com/summer',
        );

        $decoded = $this->codec->decode($this->codec->encode(UtmHistory::of($original)))->latest();

        Assert::same($decoded?->utm->toArray(), $original->utm->toArray());
        Assert::same($decoded?->clickIds->toArray(), ['gclid' => 'abc']);
        Assert::same($decoded?->referrer?->url, 'https://ads.example.com/x');
        Assert::same($decoded?->landingPage, 'https://shop.example.com/summer');
        Assert::same($decoded?->occurredAt->getTimestamp(), $original->occurredAt->getTimestamp());
    }

    public function preservesOrderAndCount(): void
    {
        $history = UtmHistory::of(
            $this->touchpoint('older', '2026-06-01 10:00:00'),
            $this->touchpoint('newer', '2026-07-01 10:00:00'),
        );

        $decoded = $this->codec->decode($this->codec->encode($history));

        Assert::same($decoded->count(), 2);
        Assert::same($decoded->latest()?->utm->source, 'newer');
    }

    #[DataProvider('unusableValuesProvider')]
    public function decodesUnusableValuesToEmptyHistory(?string $value): void
    {
        Assert::true($this->codec->decode($value)->isEmpty());
    }

    public static function unusableValuesProvider(): iterable
    {
        yield 'null' => [null];

        yield 'empty' => [''];

        yield 'not json' => ['not json at all'];

        yield 'json scalar' => ['42'];

        yield 'foreign version' => ['{"v":2,"t":[{"s":"google","at":1750000000}]}'];

        yield 'missing version' => ['{"t":[{"s":"google","at":1750000000}]}'];

        yield 'touchpoints not an array' => ['{"v":1,"t":"nope"}'];
    }

    public function skipsBrokenEntriesButKeepsGoodOnes(): void
    {
        $decoded = $this->codec->decode(
            '{"v":1,"t":[{"s":"google"},{"s":"bing","at":1750000000},"garbage",{"at":1750000000}]}',
        );

        Assert::same($decoded->count(), 1);
        Assert::same($decoded->latest()?->utm->source, 'bing');
    }

    public function rejectsOversizedValues(): void
    {
        $codec = new UtmCookieCodec(maxLength: 32);

        Assert::true($codec->decode(\str_repeat('x', 64))->isEmpty());
    }

    public function decodeAcceptsAValueAtTheMaxLengthBoundary(): void
    {
        $payload = '{"v":1,"t":[{"s":"g","at":1750000000}]}';
        $codec = new UtmCookieCodec(maxLength: \strlen($payload));

        Assert::same($codec->decode($payload)->count(), 1);
    }

    public function decodeRejectsOversizedValidJson(): void
    {
        $payload = '{"v":1,"t":[{"s":"g","at":1750000000}]}';
        $codec = new UtmCookieCodec(maxLength: 32);

        Assert::true($codec->decode($payload)->isEmpty());
    }

    public function decodeContinuesPastNonArrayEntries(): void
    {
        $decoded = $this->codec->decode(
            '{"v":1,"t":["garbage",{"s":"bing","at":1750000000}]}',
        );

        Assert::same($decoded->count(), 1);
        Assert::same($decoded->latest()?->utm->source, 'bing');
    }

    public function dropsOldestTouchpointsThatDoNotFit(): void
    {
        $codec = new UtmCookieCodec(maxLength: 90);

        $history = UtmHistory::of(
            $this->touchpoint('source-one', '2026-06-01 10:00:00'),
            $this->touchpoint('source-two', '2026-07-01 10:00:00'),
            $this->touchpoint('source-three', '2026-08-01 10:00:00'),
        );

        $encoded = $codec->encode($history);
        $decoded = $codec->decode($encoded);

        Assert::true(\strlen($encoded) <= 90);
        Assert::true($decoded->count() < 3);
        Assert::same($decoded->latest()?->utm->source, 'source-three');
    }

    public function encodesEmptyHistory(): void
    {
        Assert::same($this->codec->encode(UtmHistory::empty()), '{"v":1,"t":[]}');
    }

    #[Property(runs: 200)]
    public function roundTripsAnyHistory(array $sources): void
    {
        $touchpoints = [];

        foreach ($sources as $index => $source) {
            $touchpoints[] = $this->touchpoint($source, \sprintf('2026-06-%02d 10:00:00', ($index % 27) + 1));
        }

        $history = UtmHistory::of(...$touchpoints);
        $decoded = $this->codec->decode($this->codec->encode($history));

        Assert::same(
            \array_map(static fn(UtmTouchpoint $t): ?string => $t->utm->source, $decoded->all()),
            \array_map(static fn(UtmTouchpoint $t): ?string => $t->utm->source, $history->all()),
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function roundTripsAnyHistoryGenerators(): array
    {
        return ['sources' => Gen::arrayOf(Gen::stringFrom('abcdef', 1, 8), 0, 5)];
    }

    #[Property(runs: 200)]
    public function decodingNeverThrows(string $value): void
    {
        Assert::instanceOf($this->codec->decode($value), UtmHistory::class);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function decodingNeverThrowsGenerators(): array
    {
        return ['value' => Gen::stringAscii()];
    }

    private function touchpoint(string $source, string $time = '2026-07-01 10:00:00'): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: $source),
            occurredAt: new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
        );
    }
}
