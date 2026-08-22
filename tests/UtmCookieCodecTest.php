<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\DefaultLandingPageSanitizer;
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
        $codec = new UtmCookieCodec(maxLength: \strlen(\urlencode($full)));

        Assert::same($codec->encode($history), $full);
    }

    /**
     * The budget belongs to the value that actually reaches the header, and
     * `Yiisoft\Cookies\Cookie` percent-encodes it. A raw-length check passes a
     * payload whose `Set-Cookie` is two to three times over the browser limit,
     * and the browser then drops the cookie without a word.
     */
    public function encodeSpendsTheBudgetOnThePercentEncodedValue(): void
    {
        $history = UtmHistory::of($this->touchpoint('google'));
        $full = $this->codec->encode($history);
        $codec = new UtmCookieCodec(maxLength: \strlen(\urlencode($full)) - 1);

        Assert::same($codec->encode($history), '{"v":1,"t":[]}');
        Assert::true(\strlen($full) < \strlen(\urlencode($full)));
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
        $newest = $this->touchpoint('source-three', '2026-08-01 10:00:00');
        $budget = \strlen(\urlencode($this->codec->encode(UtmHistory::of($newest))));
        $codec = new UtmCookieCodec(maxLength: $budget);

        $history = UtmHistory::of(
            $this->touchpoint('source-one', '2026-06-01 10:00:00'),
            $this->touchpoint('source-two', '2026-07-01 10:00:00'),
            $newest,
        );

        $encoded = $codec->encode($history);
        $decoded = $codec->decode($encoded);

        Assert::true(\strlen(\urlencode($encoded)) <= $budget);
        Assert::same($decoded->count(), 1);
        Assert::same($decoded->latest()?->utm->source, 'source-three');
    }

    public function encodesEmptyHistory(): void
    {
        Assert::same($this->codec->encode(UtmHistory::empty()), '{"v":1,"t":[]}');
    }

    /**
     * A hand-edited cookie is exactly as untrusted as a query string. Before
     * this path went through the sanitizer, `javascript:` survived decoding —
     * `parse_url()` reports the host of `javascript://evil.com/…` — reached the
     * request attributes and was persisted verbatim by a storage adapter, which
     * is a stored-XSS primitive in any dashboard rendering `<a href>`.
     */
    #[DataProvider('dangerousReferrersProvider')]
    public function dropsAReferrerThatIsNotAnHttpUrl(string $referrer): void
    {
        $decoded = $this->codec->decode(\sprintf(
            '{"v":1,"t":[{"s":"google","r":%s,"at":1750000000}]}',
            \json_encode($referrer, JSON_THROW_ON_ERROR),
        ));

        Assert::null($decoded->latest()?->referrer);
    }

    public static function dangerousReferrersProvider(): iterable
    {
        yield 'javascript with a host' => ['javascript://evil.example.com/%0aalert(1)'];

        yield 'javascript inline' => ['javascript:alert(document.cookie)'];

        yield 'data url' => ['data://evil.example.com/text/html,<script>alert(1)</script>'];

        yield 'file' => ['file://evil.example.com/etc/passwd'];
    }

    /**
     * A protocol-relative referrer is not dropped but upgraded: the sanitizer
     * fills in `https` for a missing scheme, so the value that reaches the
     * history is an ordinary absolute URL rather than something whose meaning
     * depends on the page that renders it.
     */
    public function upgradesAProtocolRelativeReferrerToHttps(): void
    {
        $decoded = $this->codec->decode('{"v":1,"t":[{"s":"google","r":"//ads.example.com/x","at":1750000000}]}');

        Assert::same($decoded->latest()?->referrer?->url, 'https://ads.example.com/x');
    }

    public function stripsQueryParametersOutsideTheAllowListFromAForgedReferrer(): void
    {
        $decoded = $this->codec->decode(
            '{"v":1,"t":[{"s":"google","r":"https://ads.example.com/x?utm_source=google&token=secret","at":1750000000}]}',
        );

        Assert::same($decoded->latest()?->referrer?->url, 'https://ads.example.com/x?utm_source=google');
    }

    /**
     * `lp` used to be taken verbatim: any string at all, including the tokens
     * and e-mail addresses the sanitizer exists to keep out of the journal.
     */
    #[DataProvider('forgedLandingPagesProvider')]
    public function sanitizesTheLandingPageOfAForgedCookie(string $landingPage, ?string $expected): void
    {
        $decoded = $this->codec->decode(\sprintf(
            '{"v":1,"t":[{"s":"google","lp":%s,"at":1750000000}]}',
            \json_encode($landingPage, JSON_THROW_ON_ERROR),
        ));

        Assert::same($decoded->latest()?->landingPage, $expected);
    }

    public static function forgedLandingPagesProvider(): iterable
    {
        yield 'arbitrary text' => ['not a url at all', null];

        yield 'javascript' => ['javascript:alert(1)', null];

        yield 'reset token stripped' => [
            'https://shop.example.com/reset?token=secret&utm_source=google',
            'https://shop.example.com/reset?utm_source=google',
        ];

        yield 'fragment dropped' => ['https://shop.example.com/a#b', 'https://shop.example.com/a'];

        yield 'already canonical' => ['https://shop.example.com/summer', 'https://shop.example.com/summer'];
    }

    /**
     * A sanitizer supplied by the application must reach the cookie path too:
     * two allow-lists for the same data is the bug this argument prevents.
     */
    public function usesTheSuppliedSanitizer(): void
    {
        $codec = new UtmCookieCodec(sanitizer: new DefaultLandingPageSanitizer(allowedQueryKeys: ['keep']));

        $decoded = $codec->decode(
            '{"v":1,"t":[{"s":"google","lp":"https://shop.example.com/a?keep=1&utm_source=google","at":1750000000}]}',
        );

        Assert::same($decoded->latest()?->landingPage, 'https://shop.example.com/a?keep=1');
    }

    /**
     * The eviction loop must respect the budget for every input, not only for
     * the ASCII histories the example tests use: `JSON_UNESCAPED_UNICODE` keeps
     * Cyrillic campaigns as raw UTF-8, and percent-encoding then triples every
     * one of their bytes.
     */
    #[Property(runs: 200)]
    public function theEncodedValueNeverExceedsTheBudget(array $campaigns, int $maxLength): void
    {
        $touchpoints = [];

        foreach (\array_values($campaigns) as $index => $campaign) {
            $touchpoints[] = UtmTouchpoint::of(
                utm: UtmParameters::fromArray(['utm_source' => 'google', 'utm_campaign' => $campaign]),
                occurredAt: new \DateTimeImmutable(
                    \sprintf('2026-06-%02d 10:00:00', ($index % 27) + 1),
                    new \DateTimeZone('UTC'),
                ),
            );
        }

        $codec = new UtmCookieCodec(maxLength: $maxLength);
        $encoded = $codec->encode(UtmHistory::of(...$touchpoints));
        $kept = $codec->decode($encoded)->count();

        Classify::cover($kept === \count($touchpoints), 'everything fits', 5.0);
        Classify::cover($kept < \count($touchpoints), 'something was evicted', 5.0);
        Classify::when($kept === 0, 'nothing fits at all');

        Assert::true(\strlen(\urlencode($encoded)) <= $maxLength);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theEncodedValueNeverExceedsTheBudgetGenerators(): array
    {
        return [
            'campaigns' => Gen::arrayOf(
                Gen::oneOf(
                    Gen::stringFrom('abcdefghij-', 1, 80),
                    Gen::stringFrom('абвгдежзий-', 1, 80),
                ),
                0,
                5,
            ),
            'maxLength' => Gen::intBetween(40, 4000),
        ];
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function theEncodedValueNeverExceedsTheBudgetExamples(): iterable
    {
        // The case from the report: five rich Cyrillic campaigns produce about
        // 3000 raw bytes, which the old raw-length check accepted, and roughly
        // 8000 percent-encoded bytes, which no browser stores.
        yield 'five long cyrillic campaigns at the default budget' => [
            \array_fill(0, 5, \str_repeat('летняя-кампания-', 12)),
            UtmCookieCodec::DEFAULT_MAX_LENGTH,
        ];

        yield 'ascii history well inside the budget' => [['summer', 'winter'], 4000];

        yield 'budget too small for a single touchpoint' => [['summer'], 40];

        yield 'empty history' => [[], 40];
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

    /**
     * `decode(encode(x)) === x` holds for the URLs the capture layer produces —
     * sanitizer fixed points. It deliberately does not hold for anything else:
     * decoding re-sanitises, which is the whole point of the cookie being
     * untrusted input.
     */
    #[Property(runs: 200)]
    public function roundTripsUrlsThatAreAlreadyCanonical(string $host, string $path): void
    {
        $url = 'https://' . $host . '.example/' . $path;
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            referrer: Referrer::of($url),
            landingPage: $url,
        );

        $decoded = $this->codec->decode($this->codec->encode(UtmHistory::of($touchpoint)))->latest();

        Assert::same($decoded?->referrer?->url, $url);
        Assert::same($decoded?->landingPage, $url);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function roundTripsUrlsThatAreAlreadyCanonicalGenerators(): array
    {
        return [
            'host' => Gen::stringFrom('abcxyz', 1, 20),
            'path' => Gen::stringFrom('abc/', 0, 20),
        ];
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
