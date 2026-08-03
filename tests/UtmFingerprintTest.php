<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\InteractionType;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmFingerprint;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmFingerprint::class)]
final class UtmFingerprintTest
{
    public function isDeterministic(): void
    {
        $touchpoint = $this->touchpoint();

        Assert::same(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint),
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint),
        );
    }

    public function producesSha256Hex(): void
    {
        $fingerprint = UtmFingerprint::of('user-1', InteractionType::purchase(), $this->touchpoint());

        Assert::same(\strlen($fingerprint), 64);
        Assert::same(\preg_match('/^[0-9a-f]{64}\z/', $fingerprint), 1);
    }

    public function dependsOnEntity(): void
    {
        $touchpoint = $this->touchpoint();

        Assert::true(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint)
            !== UtmFingerprint::of('user-2', InteractionType::purchase(), $touchpoint),
        );
    }

    public function dependsOnInteractionType(): void
    {
        $touchpoint = $this->touchpoint();

        Assert::true(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint)
            !== UtmFingerprint::of('user-1', InteractionType::registration(), $touchpoint),
        );
    }

    public function ignoresOccurredAt(): void
    {
        $touchpoint = $this->touchpoint();
        $moved = $touchpoint->withOccurredAt(new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC')));

        Assert::same(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $moved),
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint),
        );
    }

    public function usesReferrerHostNotFullUrl(): void
    {
        $first = $this->touchpoint(referrer: 'https://ads.example.com/a?session=1');
        $second = $this->touchpoint(referrer: 'https://ads.example.com/b?session=2');

        Assert::same(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $second),
            UtmFingerprint::of('user-1', InteractionType::purchase(), $first),
        );
    }

    public function dependsOnClickIds(): void
    {
        $with = $this->touchpoint(clickIds: ['gclid' => 'abc']);
        $without = $this->touchpoint();

        Assert::true(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $with)
            !== UtmFingerprint::of('user-1', InteractionType::purchase(), $without),
        );
    }

    public function dedupeKeyCombinesEventAndFingerprint(): void
    {
        $fingerprint = UtmFingerprint::of('user-1', InteractionType::purchase(), $this->touchpoint());
        $key = UtmFingerprint::dedupeKey('order-42', $fingerprint);

        Assert::same(\strlen($key), 64);
        Assert::same(UtmFingerprint::dedupeKey('order-42', $fingerprint), $key);
        Assert::true(UtmFingerprint::dedupeKey('order-43', $fingerprint) !== $key);
    }

    public function signatureIsStableJson(): void
    {
        Assert::string(UtmFingerprint::signature($this->touchpoint()))->contains('"utm"');
    }

    public function signatureUsesNamedKeysWithLandingPageAndReferrerHost(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            referrer: Referrer::of('https://ads.example.com/y'),
            landingPage: 'https://shop.example.com/x',
        );

        Assert::true(\str_contains(UtmFingerprint::signature($touchpoint), '"landing_page":"https://shop.example.com/x"'));
        Assert::true(\str_contains(UtmFingerprint::signature($touchpoint), '"referrer_host":"ads.example.com"'));
    }

    public function signatureHandlesMissingReferrer(): void
    {
        Assert::true(\str_contains(UtmFingerprint::signature($this->touchpoint()), '"referrer_host":null'));
    }

    public function signatureLeavesSlashesUnescaped(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            landingPage: 'https://shop.example.com/path',
        );

        Assert::true(\str_contains(UtmFingerprint::signature($touchpoint), 'https://shop.example.com/path'));
        Assert::false(\str_contains(UtmFingerprint::signature($touchpoint), '\/'));
    }

    public function signatureLeavesMultibyteUnescaped(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'москва'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
        );

        Assert::true(\str_contains(UtmFingerprint::signature($touchpoint), 'москва'));
    }

    public function ofMatchesHashOfTheCanonicalPayloadWithSlashes(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            landingPage: 'https://shop.example.com/path',
        );

        $payload = \json_encode(
            ['entity_id' => 'user-1', 'interaction_type' => 'purchase', 'touchpoint' => UtmFingerprint::signature($touchpoint)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        Assert::same(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint),
            \hash('sha256', $payload),
        );
    }

    public function ofMatchesHashOfTheCanonicalPayloadWithMultibyte(): void
    {
        $touchpoint = UtmTouchpoint::of(
            utm: new UtmParameters(source: 'москва'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
        );

        $payload = \json_encode(
            ['entity_id' => 'user-1', 'interaction_type' => 'purchase', 'touchpoint' => UtmFingerprint::signature($touchpoint)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        Assert::same(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $touchpoint),
            \hash('sha256', $payload),
        );
    }

    public function dedupeKeyMatchesHashOfEventIdAndFingerprintJoinedByNullByte(): void
    {
        Assert::same(
            UtmFingerprint::dedupeKey('order-42', 'fp-value'),
            \hash('sha256', "order-42\0fp-value"),
        );
    }

    #[Property(runs: 200)]
    public function changesWhenAnyCampaignFieldChanges(string $field, string $value): void
    {
        $base = $this->touchpoint();
        $changed = UtmTouchpoint::of(
            utm: UtmParameters::fromArray([...$base->utm->toArray(), $field => $value]),
            occurredAt: $base->occurredAt,
        );

        $expectSame = $base->utm->toArray()[$field] === UtmParameters::fromArray([$field => $value])->toArray()[$field];

        Assert::same(
            UtmFingerprint::of('user-1', InteractionType::purchase(), $changed)
            === UtmFingerprint::of('user-1', InteractionType::purchase(), UtmTouchpoint::of($base->utm, $base->occurredAt)),
            $expectSame,
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function changesWhenAnyCampaignFieldChangesGenerators(): array
    {
        return [
            'field' => Gen::elements(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id']),
            'value' => Gen::stringFrom('abcdef', 1, 12),
        ];
    }

    /**
     * @param array<string, string> $clickIds
     */
    private function touchpoint(array $clickIds = [], ?string $referrer = null): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google', medium: 'cpc', campaign: 'summer'),
            occurredAt: new \DateTimeImmutable('2026-08-01 10:00:00', new \DateTimeZone('UTC')),
            clickIds: ClickIds::fromArray($clickIds),
            referrer: $referrer === null ? null : Referrer::of($referrer),
            landingPage: 'https://shop.example.com/',
        );
    }
}
