<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\Channel;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DefaultChannelResolver::class)]
final class DefaultChannelResolverTest
{
    private DefaultChannelResolver $resolver;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->resolver = new DefaultChannelResolver();
    }

    public function clickIdOutranksEverything(): void
    {
        $touchpoint = $this->touchpoint(utm: new UtmParameters(medium: 'organic'), clickIds: ['gclid' => 'abc']);

        Assert::same($this->resolver->resolve($touchpoint), Channel::Paid);
    }

    #[DataProvider('mediumProvider')]
    public function classifiesByMedium(string $medium, Channel $expected): void
    {
        Assert::same($this->resolver->resolve($this->touchpoint(new UtmParameters(medium: $medium))), $expected);
    }

    public static function mediumProvider(): iterable
    {
        yield 'cpc' => ['cpc', Channel::Paid];

        yield 'display' => ['display', Channel::Paid];

        yield 'uppercase cpc' => ['CPC', Channel::Paid];

        yield 'email' => ['email', Channel::Email];

        yield 'newsletter' => ['newsletter', Channel::Email];

        yield 'social' => ['social', Channel::Social];

        yield 'organic' => ['organic', Channel::Organic];

        yield 'referral' => ['referral', Channel::Referral];
    }

    public function unknownMediumWithoutReferrerIsOther(): void
    {
        Assert::same($this->resolver->resolve($this->touchpoint(new UtmParameters(medium: 'carrier-pigeon'))), Channel::Other);
    }

    public function noCampaignAndNoReferrerIsDirect(): void
    {
        Assert::same($this->resolver->resolve($this->touchpoint(new UtmParameters())), Channel::Direct);
    }

    #[DataProvider('referrerProvider')]
    public function classifiesByReferrer(string $referrer, Channel $expected): void
    {
        $touchpoint = $this->touchpoint(new UtmParameters(), referrer: $referrer);

        Assert::same($this->resolver->resolve($touchpoint), $expected);
    }

    public static function referrerProvider(): iterable
    {
        yield 'social host' => ['https://vk.com/wall1', Channel::Social];

        yield 'social subdomain' => ['https://m.facebook.com/x', Channel::Social];

        yield 'search host' => ['https://yandex.ru/search/?text=x', Channel::Organic];

        yield 'search subdomain' => ['https://www.google.com/search?q=x', Channel::Organic];

        yield 'anything else' => ['https://blog.example.org/post', Channel::Referral];
    }

    public function respectsCustomVocabularies(): void
    {
        $resolver = new DefaultChannelResolver(paidMediums: ['partner']);

        Assert::same($resolver->resolve($this->touchpoint(new UtmParameters(medium: 'partner'))), Channel::Paid);
        Assert::same($resolver->resolve($this->touchpoint(new UtmParameters(medium: 'cpc'))), Channel::Other);
    }

    public function lowercasesMultibyteMediumBeforeMatching(): void
    {
        $resolver = new DefaultChannelResolver(paidMediums: ['поиск']);

        Assert::same($resolver->resolve($this->touchpoint(new UtmParameters(medium: 'ПОИСК'))), Channel::Paid);
        Assert::same($resolver->resolve($this->touchpoint(new UtmParameters(medium: 'cpc'))), Channel::Other);
    }

    public function referrerSubdomainMatchRequiresADotPrefix(): void
    {
        $touchpoint = $this->touchpoint(new UtmParameters(), referrer: 'https://notfacebook.com/x');

        Assert::same($this->resolver->resolve($touchpoint), Channel::Referral);
    }

    /**
     * @param array<string, string> $clickIds
     */
    private function touchpoint(UtmParameters $utm, array $clickIds = [], ?string $referrer = null): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: $utm,
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            clickIds: ClickIds::fromArray($clickIds),
            referrer: $referrer === null ? null : Referrer::of($referrer),
        );
    }
}
