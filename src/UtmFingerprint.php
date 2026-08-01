<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Canonical serialisation of a touchpoint and the hashes derived from it.
 *
 * The signature fixes both the field set and the field order, so the same
 * touchpoint always produces the same fingerprint — the dedupe key of the
 * attribution journal is built on top of it.
 *
 * The referrer contributes its host only: a full URL varies from visit to visit
 * and would make every redelivery look like a new touchpoint.
 *
 * @psalm-immutable
 *
 * @internal
 */
final readonly class UtmFingerprint
{
    /**
     * @return non-empty-string
     */
    public static function signature(UtmTouchpoint $touchpoint): string
    {
        return \json_encode(
            [
                'utm' => $touchpoint->utm->toArray(),
                'click_ids' => $touchpoint->clickIds->toArray(),
                'landing_page' => $touchpoint->landingPage,
                'referrer_host' => $touchpoint->referrer?->host,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param non-empty-string $entityId
     *
     * @return non-empty-string
     */
    public static function of(string $entityId, InteractionType $interactionType, UtmTouchpoint $touchpoint): string
    {
        return \hash(
            'sha256',
            \json_encode(
                [
                    'entity_id' => $entityId,
                    'interaction_type' => $interactionType->value,
                    'touchpoint' => self::signature($touchpoint),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * @param non-empty-string $eventId
     * @param non-empty-string $fingerprint
     *
     * @return non-empty-string
     */
    public static function dedupeKey(string $eventId, string $fingerprint): string
    {
        return \hash('sha256', $eventId . "\0" . $fingerprint);
    }
}
