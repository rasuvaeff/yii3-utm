<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\InteractionType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(InteractionType::class)]
final class InteractionTypeTest
{
    public function acceptsCustomTypes(): void
    {
        Assert::same(InteractionType::of('lead')->value, 'lead');
        Assert::same(InteractionType::of('trial_started')->value, 'trial_started');
    }

    public function providesNamedTypes(): void
    {
        Assert::same(InteractionType::registration()->value, 'registration');
        Assert::same(InteractionType::purchase()->value, 'purchase');
    }

    #[DataProvider('invalidValuesProvider')]
    public function rejectsInvalidValues(string $value): void
    {
        try {
            InteractionType::of($value);
        } catch (\InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid interaction type');

            return;
        }

        Assert::true(false);
    }

    public static function invalidValuesProvider(): iterable
    {
        yield 'empty' => [''];

        yield 'uppercase' => ['Registration'];

        yield 'leading digit' => ['1lead'];

        yield 'leading underscore' => ['_lead'];

        yield 'dash' => ['trial-started'];

        yield 'space' => ['trial started'];

        yield 'too long' => [\str_repeat('a', InteractionType::MAX_LENGTH + 1)];

        yield 'trailing newline' => ["lead\n"];
    }

    public function acceptsTheMaximumLength(): void
    {
        Assert::same(\strlen(InteractionType::of(\str_repeat('a', InteractionType::MAX_LENGTH))->value), 32);
    }

    public function equalsComparesValue(): void
    {
        Assert::true(InteractionType::of('lead')->equals(InteractionType::of('lead')));
        Assert::false(InteractionType::of('lead')->equals(InteractionType::purchase()));
    }

    public function castsToString(): void
    {
        Assert::same((string) InteractionType::purchase(), 'purchase');
    }
}
