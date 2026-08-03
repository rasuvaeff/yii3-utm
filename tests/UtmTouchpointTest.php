<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmTouchpoint::class)]
final class UtmTouchpointTest
{
    public function defaultsToEmptyClickIds(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: $this->at('2026-08-01 10:00:00'),
        );

        Assert::true($touchpoint->clickIds->isEmpty());
        Assert::null($touchpoint->referrer);
        Assert::null($touchpoint->landingPage);
    }

    public function isEmptyWithoutAnyIdentification(): void
    {
        Assert::true(UtmTouchpoint::of(new UtmParameters(), $this->at('2026-08-01 10:00:00'))->isEmpty());
    }

    public function clickIdAloneMakesItNonEmpty(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            clickIds: ClickIds::fromArray(['gclid' => 'abc']),
        );

        Assert::false($touchpoint->isEmpty());
    }

    public function referrerAloneMakesItNonEmpty(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            referrer: Referrer::of('https://news.example.com/post'),
        );

        Assert::false($touchpoint->isEmpty());
    }

    public function normalizesLandingPage(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'g'),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            landingPage: '   ',
        );

        Assert::null($touchpoint->landingPage);
    }

    public function stripsControlCharsFromLandingPage(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'g'),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            landingPage: "https://example.com/path\x01injected\x7F",
        );

        Assert::same($touchpoint->landingPage, 'https://example.com/pathinjected');
    }

    public function truncatesLandingPage(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'g'),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            landingPage: 'https://example.com/' . \str_repeat('a', 600),
        );

        Assert::same(\mb_strlen((string) $touchpoint->landingPage), UtmTouchpoint::MAX_LANDING_PAGE_LENGTH);
    }

    public function truncatesMultibyteLandingPageByCodePoints(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'g'),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            landingPage: 'https://example.com/' . \str_repeat('а', 600),
        );

        Assert::same(\mb_strlen((string) $touchpoint->landingPage), UtmTouchpoint::MAX_LANDING_PAGE_LENGTH);
        Assert::true(\str_contains((string) $touchpoint->landingPage, 'а'));
    }

    public function withOccurredAtKeepsEverythingElse(): void
    {
        $original = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: $this->at('2026-08-01 10:00:00'),
            clickIds: ClickIds::fromArray(['gclid' => 'abc']),
            referrer: Referrer::of('https://ads.example.com/x'),
            landingPage: 'https://shop.example.com/',
        );

        $moved = $original->withOccurredAt($this->at('2026-08-02 11:00:00'));

        Assert::same($moved->occurredAt->format('Y-m-d H:i:s'), '2026-08-02 11:00:00');
        Assert::same($moved->utm->source, 'google');
        Assert::same($moved->clickIds->get('gclid'), 'abc');
        Assert::same($moved->referrer?->host, 'ads.example.com');
        Assert::same($moved->landingPage, 'https://shop.example.com/');
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
