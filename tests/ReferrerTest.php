<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\Referrer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Referrer::class)]
final class ReferrerTest
{
    public function extractsHost(): void
    {
        $referrer = Referrer::of('https://ads.example.com/landing');

        Assert::instanceOf($referrer, Referrer::class);
        Assert::same($referrer->host, 'ads.example.com');
        Assert::same($referrer->url, 'https://ads.example.com/landing');
    }

    public function lowercasesHost(): void
    {
        Assert::same(Referrer::of('https://ADS.Example.COM/x')?->host, 'ads.example.com');
    }

    public function trimsInput(): void
    {
        Assert::same(Referrer::of('  https://example.com/x  ')?->url, 'https://example.com/x');
    }

    public function stripsControlCharsFromTheUrl(): void
    {
        Assert::same(Referrer::of("https://example.com/path\x01injected\x7F")?->url, 'https://example.com/pathinjected');
    }

    public function acceptsAMultibyteHostWhoseByteLengthExceedsTheLimitButWhoseCharacterLengthDoesNot(): void
    {
        $url = 'https://' . \str_repeat('а', 242) . '.example/x';

        Assert::instanceOf(Referrer::of($url), Referrer::class);
    }

    #[DataProvider('rejectedUrlsProvider')]
    public function rejectsUnusableUrls(string $url): void
    {
        Assert::null(Referrer::of($url));
    }

    public static function rejectedUrlsProvider(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace' => ['   '];

        yield 'relative path' => ['/landing?a=1'];

        yield 'no host' => ['https://'];

        // `https://` is rejected by the scheme guard already — parse_url()
        // reports no scheme for it. This one carries a real scheme and still
        // has no host, which is what keeps the host guard exercised.
        yield 'scheme without a host' => ['https:/landing'];

        yield 'too long' => ['https://example.com/' . \str_repeat('a', Referrer::MAX_URL_LENGTH)];

        yield 'host too long' => ['https://' . \str_repeat('a', Referrer::MAX_HOST_LENGTH + 1) . '.com/x'];

        // Every one of these used to be accepted: the factory only asked
        // parse_url() for a host, and `javascript://evil.example.com/x` has one.
        yield 'javascript with a host' => ['javascript://evil.example.com/%0aalert(1)'];

        yield 'data' => ['data://evil.example.com/text/html,<script>alert(1)</script>'];

        yield 'file' => ['file://evil.example.com/etc/passwd'];

        yield 'ftp' => ['ftp://evil.example.com/x'];

        yield 'protocol relative' => ['//evil.example.com/x'];
    }

    public function acceptsHttpAndUppercaseSchemes(): void
    {
        Assert::same(Referrer::of('http://example.com/x')?->host, 'example.com');
        Assert::same(Referrer::of('HTTPS://example.com/x')?->host, 'example.com');
    }

    /**
     * An accept/reject property over the scheme alphabet: the generator builds
     * both allowed and forbidden schemes, so no `Assume` is needed and the
     * "rejects everything" mutation dies as surely as "accepts everything".
     * `timeoutMs` guards the regex work inside `parse_url()`.
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function acceptsAnUrlExactlyWhenItsSchemeIsHttpOrHttps(string $scheme): void
    {
        $allowed = \in_array(\mb_strtolower($scheme), ['http', 'https'], strict: true);

        Classify::cover($allowed, 'http or https', 5.0);
        Classify::cover(!$allowed, 'some other scheme', 5.0);

        Assert::same(Referrer::of($scheme . '://ads.example.com/x') instanceof Referrer, $allowed);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function acceptsAnUrlExactlyWhenItsSchemeIsHttpOrHttpsGenerators(): array
    {
        return [
            'scheme' => Gen::frequency([
                [1, Gen::elements(['http', 'https', 'HTTP', 'HttpS'])],
                [1, Gen::elements(['javascript', 'data', 'file', 'ftp', 'ws', 'chrome-extension'])],
                [1, Gen::stringMatching('[a-z][a-z0-9]{0,8}')],
            ]),
        ];
    }

    public function acceptsAUrlAtTheMaximumLength(): void
    {
        $url = 'https://example.com/' . \str_repeat('a', Referrer::MAX_URL_LENGTH - 20);

        Assert::same(\mb_strlen(Referrer::of($url)?->url ?? ''), Referrer::MAX_URL_LENGTH);
    }

    public function acceptsAMultibyteUrlWhoseByteLengthExceedsTheLimit(): void
    {
        $url = 'https://x.example/' . \str_repeat('а', 242);

        Assert::instanceOf(Referrer::of($url), Referrer::class);
    }

    public function acceptsAHostAtTheMaximumLength(): void
    {
        $url = 'https://' . \str_repeat('a', Referrer::MAX_HOST_LENGTH) . '/x';

        Assert::instanceOf(Referrer::of($url), Referrer::class);
    }

    public function lowercasesMultibyteHost(): void
    {
        Assert::same(Referrer::of('https://МОСКВА.РФ/x')?->host, 'москва.рф');
    }

    public function detectsInternalReferrerForMultibyteHost(): void
    {
        $referrer = Referrer::of('https://москва.рф/x');

        Assert::true($referrer?->isInternal('МОСКВА.РФ'));
        Assert::false($referrer?->isInternal('example.com'));
    }

    public function detectsInternalReferrer(): void
    {
        $referrer = Referrer::of('https://shop.example.com/cart');

        Assert::true($referrer?->isInternal('shop.example.com'));
        Assert::true($referrer?->isInternal(' Shop.Example.com '));
        Assert::false($referrer?->isInternal('ads.example.com'));
    }

    public function externalReturnsNullForAnInternalReferrer(): void
    {
        Assert::null(Referrer::external('https://shop.example.com/cart', 'shop.example.com'));
        Assert::null(Referrer::external('https://shop.example.com/cart', ' Shop.Example.com '));
    }

    public function externalReturnsTheReferrerWhenItIsNotInternal(): void
    {
        $referrer = Referrer::external('https://ads.example.com/landing', 'shop.example.com');

        Assert::instanceOf($referrer, Referrer::class);
        Assert::same($referrer->host, 'ads.example.com');
    }

    public function externalReturnsNullForAnUnusableUrl(): void
    {
        Assert::null(Referrer::external('', 'shop.example.com'));
    }

    public function equalsComparesUrl(): void
    {
        $a = Referrer::of('https://example.com/a');
        $b = Referrer::of('https://example.com/a');
        $c = Referrer::of('https://example.com/b');

        Assert::true($a?->equals($b));
        Assert::false($a?->equals($c));
    }
}
