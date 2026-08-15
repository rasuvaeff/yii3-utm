<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\Exception\InvalidUtmValue;
use Rasuvaeff\Yii3Utm\Exception\UtmException;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\UtmAttribution;
use Rasuvaeff\Yii3Utm\UtmFingerprint;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(UtmAttribution::class)]
final class UtmAttributionTest
{
    public function derivesFingerprintAndDedupeKey(): void
    {
        $attribution = $this->attribution();

        Assert::same(
            $attribution->fingerprint,
            UtmFingerprint::of('user-1', InteractionType::purchase(), $this->touchpoint()),
        );
        Assert::same(
            $attribution->dedupeKey,
            UtmFingerprint::dedupeKey('order-1', $attribution->fingerprint),
        );
    }

    public function sameInputProducesSameDedupeKey(): void
    {
        Assert::same($this->attribution()->dedupeKey, $this->attribution()->dedupeKey);
    }

    public function differentEventProducesDifferentDedupeKey(): void
    {
        Assert::true($this->attribution(eventId: 'order-2')->dedupeKey !== $this->attribution()->dedupeKey);
    }

    public function differentTouchpointProducesDifferentDedupeKey(): void
    {
        $other = new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: UtmTouchpoint::of(
                utm: new UtmParameters(source: 'bing'),
                occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            ),
        );

        Assert::true($other->dedupeKey !== $this->attribution()->dedupeKey);
    }

    public function claimedTimeDoesNotAffectDeduplication(): void
    {
        $moved = new UtmAttribution(
            entityId: 'user-1',
            eventId: 'order-1',
            interactionType: InteractionType::purchase(),
            touchpoint: $this->touchpoint()->withOccurredAt(
                new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC')),
            ),
        );

        Assert::same($moved->dedupeKey, $this->attribution()->dedupeKey);
    }

    #[DataProvider('invalidIdentifiersProvider')]
    public function rejectsInvalidIdentifiers(string $entityId, string $eventId, string $expectedFragment): void
    {
        try {
            $this->attribution(entityId: $entityId, eventId: $eventId);
        } catch (InvalidUtmValue $e) {
            Assert::instanceOf($e, UtmException::class);
            Assert::string($e->getMessage())->contains($expectedFragment);

            return;
        }

        Assert::true(actual: false);
    }

    public static function invalidIdentifiersProvider(): iterable
    {
        yield 'empty entity' => ['', 'order-1', 'entity id'];

        yield 'blank entity' => ['   ', 'order-1', 'entity id'];

        yield 'empty event' => ['user-1', '', 'event id'];

        yield 'overlong entity' => [\str_repeat('u', 192), 'order-1', 'must not exceed 191 bytes'];

        yield 'overlong event' => ['user-1', \str_repeat('o', 192), 'must not exceed 191 bytes'];

        yield 'invalid utf-8 entity' => ["user-\xFF", 'order-1', 'must be valid UTF-8'];

        yield 'invalid utf-8 event' => ['user-1', "order-\xFF", 'must be valid UTF-8'];
    }

    public function acceptsIdentifiersAtTheLimit(): void
    {
        $attribution = $this->attribution(entityId: \str_repeat('u', UtmAttribution::MAX_IDENTIFIER_LENGTH));

        Assert::same(\strlen($attribution->entityId), 191);
    }

    private function attribution(
        string $entityId = 'user-1',
        string $eventId = 'order-1',
    ): UtmAttribution {
        return new UtmAttribution(
            entityId: $entityId,
            eventId: $eventId,
            interactionType: InteractionType::purchase(),
            touchpoint: $this->touchpoint(),
        );
    }

    private function touchpoint(): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google', medium: 'cpc'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
        );
    }
}
