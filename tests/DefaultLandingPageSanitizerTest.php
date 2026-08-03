<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
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

    public function respectsACustomAllowList(): void
    {
        $sanitizer = new DefaultLandingPageSanitizer(allowedQueryKeys: ['page']);
        $sanitized = $sanitizer->sanitize('https://shop.example.com/?page=2&utm_source=google');

        Assert::same($sanitized, 'https://shop.example.com/?page=2');
    }

    #[Property(runs: 200)]
    public function isIdempotent(string $path, string $token): void
    {
        $url = 'https://shop.example.com/' . $path . '?token=' . $token . '&utm_source=google';
        $once = $this->sanitizer->sanitize($url);

        Assert::same($this->sanitizer->sanitize($once), $once);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function isIdempotentGenerators(): array
    {
        return [
            'path' => Gen::stringFrom('abc/', 0, 20),
            'token' => Gen::stringFrom('xyz123', 1, 20),
        ];
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
