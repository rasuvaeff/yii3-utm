<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Utm\HeaderUtmSource;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(HeaderUtmSource::class)]
final class HeaderUtmSourceTest
{
    private FrozenClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
    }

    public function readsEverySupportedField(): void
    {
        $request = $this->request()
            ->withHeader('X-Utm-Source', 'newsletter')
            ->withHeader('X-Utm-Medium', 'email')
            ->withHeader('X-Utm-Id', 'c-7')
            ->withHeader('X-Utm-Click-Ids', '{"gclid":"g-1","li_fat_id":"li-1"}')
            ->withHeader('X-Utm-Landing-Page', 'https://shop.example.com/x?token=secret')
            ->withHeader('X-Utm-Referrer', 'https://mail.example.net/inbox')
            ->withHeader('X-Utm-Occurred-At', '2026-07-01T10:00:00+00:00');

        $touchpoint = (new HeaderUtmSource($this->clock))->extract($request);

        Assert::same($touchpoint?->utm->source, 'newsletter');
        Assert::same($touchpoint?->utm->id, 'c-7');
        Assert::same($touchpoint?->clickIds->toArray(), ['gclid' => 'g-1', 'li_fat_id' => 'li-1']);
        Assert::same($touchpoint?->landingPage, 'https://shop.example.com/x');
        Assert::same($touchpoint?->referrer?->host, 'mail.example.net');
        Assert::same($touchpoint?->occurredAt->format('Y-m-d H:i:s'), '2026-07-01 10:00:00');
    }

    public function ignoresMalformedClickIdJson(): void
    {
        $request = $this->request()
            ->withHeader('X-Utm-Source', 'google')
            ->withHeader('X-Utm-Click-Ids', '{broken');

        Assert::true((new HeaderUtmSource($this->clock))->extract($request)?->clickIds->isEmpty());
    }

    public function restrictsClickIdsAndSupportsACustomPrefix(): void
    {
        $request = $this->request()
            ->withHeader('Marketing-Source', 'google')
            ->withHeader('Marketing-Click-Ids', '{"gclid":"ignored","fbclid":"accepted"}');
        $source = new HeaderUtmSource(
            clock: $this->clock,
            prefix: 'Marketing-',
            clickIdKeys: ['fbclid'],
        );

        $touchpoint = $source->extract($request);

        Assert::same($touchpoint?->utm->source, 'google');
        Assert::same($touchpoint?->clickIds->toArray(), ['fbclid' => 'accepted']);
    }

    public function acceptsAUnixTimestamp(): void
    {
        $request = $this->request()
            ->withHeader('X-Utm-Source', 'google')
            ->withHeader('X-Utm-Occurred-At', '1751364000');

        Assert::same((new HeaderUtmSource($this->clock))->extract($request)?->occurredAt->getTimestamp(), 1751364000);
    }

    public function fallsBackToTheServerClockOnGarbageTime(): void
    {
        $request = $this->request()
            ->withHeader('X-Utm-Source', 'google')
            ->withHeader('X-Utm-Occurred-At', 'yesterday-ish nonsense');

        Assert::same(
            (new HeaderUtmSource($this->clock))->extract($request)?->occurredAt->format('Y-m-d H:i:s'),
            '2026-08-01 12:00:00',
        );
    }

    public function ignoresAnInternalReferrer(): void
    {
        $request = $this->request()->withHeader('X-Utm-Referrer', 'https://api.example.com/previous-page');

        Assert::null((new HeaderUtmSource($this->clock))->extract($request));
    }

    public function returnsNullWithoutAnySignal(): void
    {
        Assert::null((new HeaderUtmSource($this->clock))->extract($this->request()));
    }

    private function request(): ServerRequest
    {
        return new ServerRequest('GET', 'https://api.example.com/register');
    }
}
