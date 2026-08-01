<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UtmParameters::class)]
final class UtmParametersTest
{
    public function readsPrefixedKeys(): void
    {
        $utm = UtmParameters::fromArray([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer',
            'utm_term' => 'shoes',
            'utm_content' => 'banner-a',
            'utm_id' => 'c-42',
        ]);

        Assert::same($utm->source, 'google');
        Assert::same($utm->medium, 'cpc');
        Assert::same($utm->campaign, 'summer');
        Assert::same($utm->term, 'shoes');
        Assert::same($utm->content, 'banner-a');
        Assert::same($utm->id, 'c-42');
    }

    public function readsBareKeys(): void
    {
        $utm = UtmParameters::fromArray(['source' => 'newsletter', 'medium' => 'email']);

        Assert::same($utm->source, 'newsletter');
        Assert::same($utm->medium, 'email');
    }

    public function prefersPrefixedKeyOverBareOne(): void
    {
        $utm = UtmParameters::fromArray(['utm_source' => 'google', 'source' => 'bing']);

        Assert::same($utm->source, 'google');
    }

    public function mapsContentToContentNotCampaign(): void
    {
        $utm = UtmParameters::fromArray(['utm_campaign' => 'summer', 'utm_content' => 'banner-a']);

        Assert::same($utm->campaign, 'summer');
        Assert::same($utm->content, 'banner-a');
    }

    #[DataProvider('rejectedValuesProvider')]
    public function rejectsUnusableValues(mixed $value): void
    {
        Assert::null(UtmParameters::fromArray(['utm_source' => $value])->source);
    }

    public static function rejectedValuesProvider(): iterable
    {
        yield 'empty string' => [''];

        yield 'whitespace only' => ["  \t "];

        yield 'control characters only' => ["\x00\x01\x1F"];

        yield 'integer' => [42];

        yield 'array' => [['google']];

        yield 'null' => [null];
    }

    public function stripsControlCharactersAndTrims(): void
    {
        Assert::same(UtmParameters::fromArray(['utm_source' => "  goo\x00gle\n "])->source, 'google');
    }

    public function truncatesToTheMaximumLength(): void
    {
        $source = UtmParameters::fromArray(['utm_source' => \str_repeat('a', 400)])->source;

        Assert::same(\mb_strlen((string) $source), UtmParameters::MAX_VALUE_LENGTH);
    }

    public function emptyInputProducesEmptyParameters(): void
    {
        Assert::true(UtmParameters::fromArray([])->isEmpty());
        Assert::false(UtmParameters::fromArray(['utm_id' => 'c-1'])->isEmpty());
    }

    public function toArrayUsesStableSnakeCaseKeys(): void
    {
        Assert::same(
            \array_keys((new UtmParameters())->toArray()),
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'],
        );
    }

    public function equalsComparesEveryField(): void
    {
        $a = new UtmParameters(source: 'google', medium: 'cpc');

        Assert::true($a->equals(new UtmParameters(source: 'google', medium: 'cpc')));
        Assert::false($a->equals(new UtmParameters(source: 'google', medium: 'organic')));
    }

    #[Property(runs: 200)]
    public function roundTripsThroughAnArray(
        ?string $source,
        ?string $medium,
        ?string $campaign,
        ?string $term,
        ?string $content,
        ?string $id,
    ): void {
        $utm = UtmParameters::fromArray([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $campaign,
            'utm_term' => $term,
            'utm_content' => $content,
            'utm_id' => $id,
        ]);

        Assert::same(UtmParameters::fromArray($utm->toArray())->toArray(), $utm->toArray());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function roundTripsThroughAnArrayGenerators(): array
    {
        return [
            'source' => self::valueGenerator(),
            'medium' => self::valueGenerator(),
            'campaign' => self::valueGenerator(),
            'term' => self::valueGenerator(),
            'content' => self::valueGenerator(),
            'id' => self::valueGenerator(),
        ];
    }

    #[Property(runs: 200)]
    public function normalizationIsIdempotent(?string $source): void
    {
        $once = UtmParameters::fromArray(['utm_source' => $source]);
        $twice = UtmParameters::fromArray($once->toArray());

        Assert::same($twice->source, $once->source);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function normalizationIsIdempotentGenerators(): array
    {
        return ['source' => self::valueGenerator()];
    }

    private static function valueGenerator(): ArbitraryInterface
    {
        return Gen::nullable(Gen::stringAscii());
    }
}
