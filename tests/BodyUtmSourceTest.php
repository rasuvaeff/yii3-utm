<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Utm\BodyUtmSource;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(BodyUtmSource::class)]
final class BodyUtmSourceTest
{
    private FrozenClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
    }

    public function readsTheNestedKey(): void
    {
        $request = $this->request()->withParsedBody([
            'email' => 'user@example.com',
            'utm' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'click_ids' => ['gclid' => 'abc', 'evil' => 'x'],
                'landing_page' => 'https://shop.example.com/summer?token=secret',
                'referrer' => 'https://ads.example.com/banner',
                'occurred_at' => 1751364000,
            ],
        ]);

        $touchpoint = (new BodyUtmSource($this->clock))->extract($request);

        Assert::same($touchpoint?->utm->source, 'google');
        Assert::same($touchpoint?->clickIds->toArray(), ['gclid' => 'abc']);
        Assert::same($touchpoint?->landingPage, 'https://shop.example.com/summer');
        Assert::same($touchpoint?->referrer?->host, 'ads.example.com');
        Assert::same($touchpoint?->occurredAt->getTimestamp(), 1751364000);
    }

    public function supportsACustomKeyAndRestrictsClickIds(): void
    {
        $request = $this->request()->withParsedBody([
            'marketing' => [
                'utm_source' => 'google',
                'click_ids' => ['gclid' => 'ignored', 'fbclid' => 'accepted'],
            ],
        ]);
        $source = new BodyUtmSource(
            clock: $this->clock,
            key: 'marketing',
            clickIdKeys: ['fbclid'],
        );

        $touchpoint = $source->extract($request);

        Assert::same($touchpoint?->utm->source, 'google');
        Assert::same($touchpoint?->clickIds->toArray(), ['fbclid' => 'accepted']);
    }

    public function ignoresFlatFieldsOutsideTheNestedKey(): void
    {
        $request = $this->request()->withParsedBody(['utm_source' => 'google', 'source' => 'bing']);

        Assert::null((new BodyUtmSource($this->clock))->extract($request));
    }

    public function ignoresANonArrayPayload(): void
    {
        $request = $this->request()->withParsedBody(['utm' => 'google']);

        Assert::null((new BodyUtmSource($this->clock))->extract($request));
    }

    public function ignoresAnInternalReferrer(): void
    {
        $request = $this->request()->withParsedBody([
            'utm' => ['referrer' => 'https://api.example.com/previous-page'],
        ]);

        Assert::null((new BodyUtmSource($this->clock))->extract($request));
    }

    public function ignoresAnAbsentBody(): void
    {
        Assert::null((new BodyUtmSource($this->clock))->extract($this->request()));
    }

    private function request(): ServerRequest
    {
        return new ServerRequest('POST', 'https://api.example.com/register');
    }
}
