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
    }

    public function detectsInternalReferrer(): void
    {
        $referrer = Referrer::of('https://shop.example.com/cart');

        Assert::true($referrer?->isInternal('shop.example.com'));
        Assert::true($referrer?->isInternal(' Shop.Example.com '));
        Assert::false($referrer?->isInternal('ads.example.com'));
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
