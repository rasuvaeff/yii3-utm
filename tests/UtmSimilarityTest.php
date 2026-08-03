<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmSimilarity::class)]
final class UtmSimilarityTest
{
    public function fullComparesEveryCampaignFieldAndClickIds(): void
    {
        $a = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', content: 'banner-a'));
        $b = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', content: 'banner-b'));

        Assert::false(UtmSimilarity::Full->isSimilar($a, $b));
        Assert::true(UtmSimilarity::Full->isSimilar($a, $this->touchpoint($a->utm)));
    }

    public function fullDistinguishesByClickId(): void
    {
        $utm = new UtmParameters(source: 'google', medium: 'cpc');
        $a = $this->touchpoint($utm, ClickIds::fromArray(['gclid' => 'one']));
        $b = $this->touchpoint($utm, ClickIds::fromArray(['gclid' => 'two']));

        Assert::false(UtmSimilarity::Full->isSimilar($a, $b));
    }

    public function campaignIgnoresTermAndContent(): void
    {
        $a = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer', content: 'a'));
        $b = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer', content: 'b'));

        Assert::true(UtmSimilarity::Campaign->isSimilar($a, $b));
    }

    public function campaignSeparatesDifferentCampaigns(): void
    {
        $a = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer'));
        $b = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'winter'));

        Assert::false(UtmSimilarity::Campaign->isSimilar($a, $b));
    }

    public function campaignRequiresBothSourceAndMedium(): void
    {
        $a = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer'));
        $b = $this->touchpoint(new UtmParameters(source: 'bing', medium: 'cpc', campaign: 'summer'));

        Assert::false(UtmSimilarity::Campaign->isSimilar($a, $b));
    }

    public function sourceMediumIgnoresCampaign(): void
    {
        $a = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer'));
        $b = $this->touchpoint(new UtmParameters(source: 'google', medium: 'cpc', campaign: 'winter'));

        Assert::true(UtmSimilarity::SourceMedium->isSimilar($a, $b));
        Assert::false(UtmSimilarity::SourceMedium->isSimilar($a, $this->touchpoint(new UtmParameters(source: 'bing', medium: 'cpc'))));
    }

    #[Property(runs: 200)]
    public function isReflexive(UtmSimilarity $similarity, ?string $source, ?string $medium, ?string $campaign): void
    {
        $touchpoint = $this->touchpoint(new UtmParameters(source: $source, medium: $medium, campaign: $campaign));

        Assert::true($similarity->isSimilar($touchpoint, $touchpoint));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function isReflexiveGenerators(): array
    {
        return [
            'similarity' => Gen::elements(UtmSimilarity::cases()),
            'source' => self::valueGenerator(),
            'medium' => self::valueGenerator(),
            'campaign' => self::valueGenerator(),
        ];
    }

    #[Property(runs: 300)]
    public function isSymmetric(
        UtmSimilarity $similarity,
        ?string $sourceA,
        ?string $mediumA,
        ?string $campaignA,
        ?string $sourceB,
        ?string $mediumB,
        ?string $campaignB,
    ): void {
        $a = $this->touchpoint(new UtmParameters(source: $sourceA, medium: $mediumA, campaign: $campaignA));
        $b = $this->touchpoint(new UtmParameters(source: $sourceB, medium: $mediumB, campaign: $campaignB));

        Assert::same($similarity->isSimilar($a, $b), $similarity->isSimilar($b, $a));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function isSymmetricGenerators(): array
    {
        return [
            'similarity' => Gen::elements(UtmSimilarity::cases()),
            'sourceA' => self::valueGenerator(),
            'mediumA' => self::valueGenerator(),
            'campaignA' => self::valueGenerator(),
            'sourceB' => self::valueGenerator(),
            'mediumB' => self::valueGenerator(),
            'campaignB' => self::valueGenerator(),
        ];
    }

    private static function valueGenerator(): ArbitraryInterface
    {
        return Gen::nullable(Gen::elements(['google', 'bing', 'cpc', 'organic', 'summer', 'winter']));
    }

    private function touchpoint(UtmParameters $utm, ?ClickIds $clickIds = null): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: $utm,
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            clickIds: $clickIds,
        );
    }
}
