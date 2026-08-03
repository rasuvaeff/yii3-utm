<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Utm\QueryUtmSource;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(QueryUtmSource::class)]
final class QueryUtmSourceTest
{
    private FrozenClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
    }

    public function readsCampaignClickIdsLandingPageAndReferrer(): void
    {
        $request = $this->request('https://shop.example.com/summer?utm_source=google&utm_medium=cpc&gclid=abc&token=secret')
            ->withHeader('Referer', 'https://www.google.com/search?q=shoes');

        $touchpoint = (new QueryUtmSource($this->clock))->extract($request);

        Assert::same($touchpoint?->utm->source, 'google');
        Assert::same($touchpoint?->clickIds->get('gclid'), 'abc');
        Assert::same($touchpoint?->referrer?->host, 'www.google.com');
        Assert::same($touchpoint?->landingPage, 'https://shop.example.com/summer?utm_source=google&utm_medium=cpc&gclid=abc');
        Assert::same($touchpoint?->occurredAt->format('Y-m-d H:i:s'), '2026-08-01 12:00:00');
    }

    public function acceptsABareClickId(): void
    {
        $touchpoint = (new QueryUtmSource($this->clock))
            ->extract($this->request('https://shop.example.com/?gclid=abc'));

        Assert::same($touchpoint?->clickIds->get('gclid'), 'abc');
        Assert::true($touchpoint?->utm->isEmpty());
    }

    public function restrictsCampaignAndClickIdKeys(): void
    {
        $source = new QueryUtmSource(
            clock: $this->clock,
            utmKeys: ['utm_source'],
            clickIdKeys: ['fbclid'],
        );
        $request = $this->request('https://shop.example.com/?utm_source=custom&utm_medium=ignored&fbclid=fb&gclid=ignored');

        $touchpoint = $source->extract($request);

        Assert::same($touchpoint?->utm->source, 'custom');
        Assert::same($touchpoint?->clickIds->toArray(), ['fbclid' => 'fb']);
    }

    public function returnsNullWhenThereIsNothingToCapture(): void
    {
        Assert::null((new QueryUtmSource($this->clock))->extract($this->request('https://shop.example.com/')));
    }

    public function reportsAReferrerAsATouchpoint(): void
    {
        $request = $this->request('https://shop.example.com/')
            ->withHeader('Referer', 'https://blog.example.org/post');

        $touchpoint = (new QueryUtmSource($this->clock))->extract($request);

        Assert::same($touchpoint?->referrer?->host, 'blog.example.org');
    }

    public function ignoresAnInternalReferrer(): void
    {
        $request = $this->request('https://shop.example.com/checkout')
            ->withHeader('Referer', 'https://shop.example.com/cart');

        Assert::null((new QueryUtmSource($this->clock))->extract($request));
    }

    private function request(string $uri): ServerRequestInterface
    {
        $request = new ServerRequest('GET', $uri);
        \parse_str((string) \parse_url($uri, PHP_URL_QUERY), $query);

        return $request->withQueryParams($query);
    }
}
