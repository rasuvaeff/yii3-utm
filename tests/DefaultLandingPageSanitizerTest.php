<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\DefaultLandingPageSanitizer;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DefaultLandingPageSanitizer::class)]
final class DefaultLandingPageSanitizerTest
{
    /**
     * `https://e.com/` (14 code points) plus a filtered query of exactly two
     * pairs — `utm_source=google` (17) and `gclid=abcdef` (12) — so a budget can
     * be aimed at any boundary of the cut: 45 keeps everything, 32 keeps the
     * first pair exactly, 31 keeps none.
     */
    private const string TWO_PAIR_URL = 'https://e.com/?utm_source=google&gclid=abcdef&token=secret';

    private DefaultLandingPageSanitizer $sanitizer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->sanitizer = new DefaultLandingPageSanitizer();
    }

    public function keepsSchemeHostAndPath(): void
    {
        Assert::same(
            $this->sanitizer->sanitize('https://shop.example.com/summer/sale'),
            'https://shop.example.com/summer/sale',
        );
    }

    public function keepsPort(): void
    {
        Assert::same($this->sanitizer->sanitize('http://localhost:8080/x'), 'http://localhost:8080/x');
    }

    public function lowercasesHostButNotPath(): void
    {
        Assert::same($this->sanitizer->sanitize('https://Shop.Example.COM/Summer'), 'https://shop.example.com/Summer');
    }

    public function lowercasesMultibyteHost(): void
    {
        Assert::same($this->sanitizer->sanitize('https://WWW.МСК.РФ/Path'), 'https://www.мск.рф/Path');
    }

    public function trimsSurroundingWhitespace(): void
    {
        Assert::same($this->sanitizer->sanitize('  https://example.com/x  '), 'https://example.com/x');
    }

    public function stripsControlCharsFromThePath(): void
    {
        Assert::same(
            $this->sanitizer->sanitize("https://example.com/path\x01injected\x7F"),
            'https://example.com/pathinjected',
        );
    }

    public function stripsControlCharsFromAKeptQueryValue(): void
    {
        Assert::same(
            $this->sanitizer->sanitize("https://example.com/?utm_source=go\x00ogle"),
            'https://example.com/?utm_source=google',
        );
    }

    public function dropsQueryParametersWithEmptyValues(): void
    {
        Assert::same($this->sanitizer->sanitize('https://example.com/?utm_source='), 'https://example.com/');
    }

    public function truncatesMultibyteByCodePoints(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 10);

        Assert::same($sanitizer->sanitize('https://xäooo.com/'), 'https://xä');
    }

    public function dropsSensitiveQueryParameters(): void
    {
        Assert::same(
            $this->sanitizer->sanitize('https://shop.example.com/reset?token=secret&email=a@b.c'),
            'https://shop.example.com/reset',
        );
    }

    public function keepsAllowedQueryParameters(): void
    {
        $sanitized = $this->sanitizer->sanitize(
            'https://shop.example.com/?utm_source=google&gclid=abc&session=secret',
        );

        Assert::string($sanitized)->contains('utm_source=google');
        Assert::string($sanitized)->contains('gclid=abc');
        Assert::true(!\str_contains($sanitized, 'session'));
    }

    public function dropsFragment(): void
    {
        Assert::same($this->sanitizer->sanitize('https://shop.example.com/x#token=secret'), 'https://shop.example.com/x');
    }

    #[DataProvider('rejectedUrlsProvider')]
    public function rejectsUnusableUrls(string $url): void
    {
        Assert::same($this->sanitizer->sanitize($url), '');
    }

    public static function rejectedUrlsProvider(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace' => ['   '];

        yield 'relative' => ['/summer?utm_source=google'];

        yield 'javascript scheme' => ['javascript://example.com/%0aalert(1)'];

        yield 'data scheme' => ['data://text/plain;base64,SGVsbG8='];
    }

    public function truncatesToTheConfiguredLength(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 40);
        $sanitized = $sanitizer->sanitize('https://shop.example.com/' . \str_repeat('a', 200));

        Assert::same(\mb_strlen($sanitized), 40);
    }

    public function keepsTheWholeValueWhenItEndsExactlyAtTheLimit(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 45);

        Assert::same($sanitizer->sanitize(self::TWO_PAIR_URL), 'https://e.com/?utm_source=google&gclid=abcdef');
    }

    public function dropsThePartialPairInsteadOfCuttingInsideIt(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 32);

        Assert::same($sanitizer->sanitize(self::TWO_PAIR_URL), 'https://e.com/?utm_source=google');
    }

    public function dropsTheSeparatorWhenNoPairFitsTheRemainingBudget(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 31);

        Assert::same($sanitizer->sanitize(self::TWO_PAIR_URL), 'https://e.com/');
    }

    #[DataProvider('incompletePercentTripletProvider')]
    public function dropsAnIncompletePercentTripletLeftByTheCut(int $maxLength, string $expected): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: $maxLength);

        Assert::same($sanitizer->sanitize('https://example.com/%D0%B0%D0%B1'), $expected);
    }

    public static function incompletePercentTripletProvider(): iterable
    {
        yield 'cut right after the percent sign' => [21, 'https://example.com/'];

        yield 'cut inside the triplet' => [22, 'https://example.com/'];

        yield 'cut on a triplet boundary' => [26, 'https://example.com/%D0%B0'];
    }

    /**
     * The budget is a code-point budget, and the three-code-point Cyrillic
     * segment costs six bytes: counting bytes would drop `utm_medium` from a
     * value that fits, and would reorder what survives.
     */
    public function measuresTheQueryBudgetInCodePointsNotBytes(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 50);

        Assert::same(
            $sanitizer->sanitize('https://e.com/ааа?utm_source=google&utm_medium=cpc&gclid=abcdef'),
            'https://e.com/ааа?utm_source=google&utm_medium=cpc',
        );
    }

    public function measuresTheCutInCodePointsNotBytes(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 20);

        Assert::same($sanitizer->sanitize('https://e.com/ааа%'), 'https://e.com/ааа%');
    }

    #[DataProvider('portCutProvider')]
    public function dropsAPortSeparatorLeftWithoutItsDigits(int $maxLength, string $expected): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: $maxLength);
        $sanitized = $sanitizer->sanitize('http://localhost:8080/x');

        Assert::same($sanitized, $expected);
        Assert::same($sanitizer->sanitize($sanitized), $sanitized);
    }

    public static function portCutProvider(): iterable
    {
        yield 'cut right after the separator' => [17, 'http://localhost'];

        yield 'cut inside the port digits' => [18, 'http://localhost:8'];
    }

    public function keepsATrailingPercentSignOfAValueThatFits(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: 17);

        Assert::same($sanitizer->sanitize('https://e.com/50%'), 'https://e.com/50%');
    }

    public function respectsACustomAllowList(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(allowedQueryKeys: ['page']);
        $sanitized = $sanitizer->sanitize('https://shop.example.com/?page=2&utm_source=google');

        Assert::same($sanitized, 'https://shop.example.com/?page=2');
    }

    /**
     * The budget floor is 26, one code point past `https://shop.example.com`:
     * a value cut shorter than its own authority stops being an absolute URL,
     * and the sanitizer says so on `$maxLength` instead of pretending
     * otherwise.
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function isIdempotent(string $path, string $token, int $maxLength): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(maxLength: $maxLength);
        $url = 'https://shop.example.com/' . $path . '?token=' . $token . '&utm_source=google&gclid=abc';
        $once = $sanitizer->sanitize($url);

        Classify::cover(\str_contains($once, 'gclid=abc'), 'whole query kept', 5);
        Classify::cover(
            \str_contains($once, '?') && !\str_contains($once, 'gclid=abc'),
            'query cut to the pairs that fit',
            5,
        );
        Classify::cover(!\str_contains($once, '?'), 'query dropped entirely', 5);
        Classify::when(\mb_strlen($once) === $maxLength, 'exactly at the budget');

        Assert::same($sanitizer->sanitize($once), $once);
        Assert::true(\mb_strlen($once) <= $maxLength);
        Assert::true(!\str_contains($once, 'token='));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function isIdempotentGenerators(): array
    {
        return [
            'path' => Gen::stringFrom('abc/%D0%B1', 0, 40),
            'token' => Gen::stringFrom('xyz123', 1, 20),
            'maxLength' => Gen::intBetween(26, 90),
        ];
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function isIdempotentExamples(): iterable
    {
        yield 'budget lands inside a percent triplet' => ['%D0%B0%D0%B1', 'abc', 27];

        yield 'budget lands right after the query separator' => ['abc', 'abc', 29];

        yield 'budget lands inside the second pair' => ['', 'abc', 46];

        yield 'everything fits' => ['', 'abc', 90];
    }

    #[Property(runs: 200)]
    public function neverExceedsTheLimitAndNeverLeaksTheToken(string $path, string $token): void
    {
        $sanitized = $this->sanitizer->sanitize(
            'https://shop.example.com/' . $path . '?token=' . $token,
        );

        Assert::true(\mb_strlen($sanitized) <= UtmTouchpoint::MAX_LANDING_PAGE_LENGTH);
        Assert::true(!\str_contains($sanitized, 'token='));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function neverExceedsTheLimitAndNeverLeaksTheTokenGenerators(): array
    {
        return [
            'path' => Gen::stringFrom('abc/', 0, 600),
            'token' => Gen::stringFrom('xyz123', 1, 40),
        ];
    }
}
