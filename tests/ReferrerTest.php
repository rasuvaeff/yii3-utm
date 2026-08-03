<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

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

        yield 'too long' => ['https://example.com/' . \str_repeat('a', Referrer::MAX_URL_LENGTH)];

        yield 'host too long' => ['https://' . \str_repeat('a', Referrer::MAX_HOST_LENGTH + 1) . '.com/x'];
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
