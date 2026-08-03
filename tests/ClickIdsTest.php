<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\ClickIds;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ClickIds::class)]
final class ClickIdsTest
{
    public function keepsWhitelistedKeys(): void
    {
        $ids = ClickIds::fromArray(['gclid' => 'abc123', 'fbclid' => 'IwAR0-x_y']);

        Assert::same($ids->get('gclid'), 'abc123');
        Assert::same($ids->get('fbclid'), 'IwAR0-x_y');
        Assert::same($ids->count(), 2);
    }

    public function dropsUnknownKeys(): void
    {
        $ids = ClickIds::fromArray(['gclid' => 'abc', 'evilclid' => 'payload']);

        Assert::same($ids->toArray(), ['gclid' => 'abc']);
        Assert::false($ids->has('evilclid'));
    }

    #[DataProvider('rejectedValuesProvider')]
    public function rejectsUnusableValues(mixed $value): void
    {
        Assert::true(ClickIds::fromArray(['gclid' => $value])->isEmpty());
    }

    public static function rejectedValuesProvider(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace' => ['   '];

        yield 'illegal characters' => ['abc<script>'];

        yield 'space inside' => ['abc def'];

        yield 'too long' => [\str_repeat('a', ClickIds::MAX_VALUE_LENGTH + 1)];

        yield 'not a string' => [123];

        yield 'null' => [null];
    }

    public function emptyIsEmpty(): void
    {
        $ids = ClickIds::empty();

        Assert::true($ids->isEmpty());
        Assert::same($ids->count(), 0);
        Assert::same($ids->toJson(), '{}');
        Assert::null($ids->get('gclid'));
    }

    public function serializesToJsonObject(): void
    {
        Assert::same(ClickIds::fromArray(['gclid' => 'a', 'yclid' => 'b'])->toJson(), '{"gclid":"a","yclid":"b"}');
    }

    public function keepsWhitelistOrderRegardlessOfInputOrder(): void
    {
        $direct = ClickIds::fromArray(['gclid' => 'a', 'fbclid' => 'b', 'twclid' => 'c']);
        $shuffled = ClickIds::fromArray(['twclid' => 'c', 'fbclid' => 'b', 'gclid' => 'a']);

        Assert::same($shuffled->toJson(), $direct->toJson());
        Assert::same(\array_keys($shuffled->toArray()), ['gclid', 'fbclid', 'twclid']);
    }

    public function staysWithinTheSerializedBudget(): void
    {
        $oversized = [];

        foreach (ClickIds::KNOWN_KEYS as $key) {
            $oversized[$key] = \str_repeat('x', ClickIds::MAX_VALUE_LENGTH);
        }

        $ids = ClickIds::fromArray($oversized);

        Assert::true(\strlen($ids->toJson()) <= ClickIds::MAX_SERIALIZED_LENGTH);
        Assert::false($ids->isEmpty());
        Assert::true($ids->has('gclid'));
    }

    public function acceptsKeysUpToTheSerializedBoundary(): void
    {
        $ids = ClickIds::fromArray([
            'gclid' => \str_repeat('a', 255),
            'fbclid' => \str_repeat('b', 221),
        ]);

        Assert::true($ids->has('gclid'));
        Assert::true($ids->has('fbclid'));
    }

    public function dropsAKeyThatCrossesTheSerializedBoundary(): void
    {
        $ids = ClickIds::fromArray([
            'gclid' => \str_repeat('a', 255),
            'fbclid' => \str_repeat('b', 222),
        ]);

        Assert::true($ids->has('gclid'));
        Assert::false($ids->has('fbclid'));
    }

    public function continuesAfterAnEntryThatDoesNotFit(): void
    {
        $ids = ClickIds::fromArray([
            'gclid' => \str_repeat('a', 255),
            'gbraid' => \str_repeat('b', 225),
            'wbraid' => 'c',
        ]);

        Assert::true($ids->has('gclid'));
        Assert::false($ids->has('gbraid'));
        Assert::true($ids->has('wbraid'));
    }

    public function trimsSurroundingWhitespaceFromValues(): void
    {
        Assert::same(ClickIds::fromArray(['gclid' => '  abc123  '])->get('gclid'), 'abc123');
    }

    public function equalsComparesContent(): void
    {
        Assert::true(ClickIds::fromArray(['gclid' => 'a'])->equals(ClickIds::fromArray(['gclid' => 'a'])));
        Assert::false(ClickIds::fromArray(['gclid' => 'a'])->equals(ClickIds::fromArray(['gclid' => 'b'])));
    }

    #[Property(runs: 200)]
    public function serializedFormNeverExceedsTheColumnWidth(array $values): void
    {
        Assert::true(\strlen(ClickIds::fromArray($values)->toJson()) <= ClickIds::MAX_SERIALIZED_LENGTH);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function serializedFormNeverExceedsTheColumnWidthGenerators(): array
    {
        return ['values' => self::valuesGenerator()];
    }

    #[Property(runs: 200)]
    public function roundTripsThroughAnArray(array $values): void
    {
        $ids = ClickIds::fromArray($values);

        Assert::same(ClickIds::fromArray($ids->toArray())->toArray(), $ids->toArray());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function roundTripsThroughAnArrayGenerators(): array
    {
        return ['values' => self::valuesGenerator()];
    }

    #[Property(runs: 200)]
    public function keyOrderIsIndependentOfInputOrder(array $values): void
    {
        $reversed = \array_reverse($values, preserve_keys: true);

        Assert::same(ClickIds::fromArray($reversed)->toJson(), ClickIds::fromArray($values)->toJson());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function keyOrderIsIndependentOfInputOrderGenerators(): array
    {
        return ['values' => self::valuesGenerator()];
    }

    private static function valuesGenerator(): ArbitraryInterface
    {
        return Gen::map(
            Gen::arrayOf(
                Gen::tuple(
                    Gen::elements(ClickIds::KNOWN_KEYS),
                    Gen::stringFrom('abcdefgh0123456789-_', 1, 260),
                ),
                0,
                12,
            ),
            static function (array $pairs): array {
                $out = [];

                foreach ($pairs as [$key, $value]) {
                    $out[$key] = $value;
                }

                return $out;
            },
        );
    }
}
