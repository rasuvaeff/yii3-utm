<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Rasuvaeff\Yii3Utm\Exception\InvalidUtmValue;

/**
 * Immutable command: attribute one touchpoint to one business event.
 *
 * `fingerprint` and `dedupeKey` are computed in the constructor and cannot be
 * supplied by the caller. A mismatched fingerprint would silently defeat the
 * unique index of the journal — writing a duplicate row where a no-op was
 * expected, or suppressing a row that should exist.
 *
 * The whole touchpoint takes part in the fingerprint: campaign tuple, click
 * ids, landing page and referrer host. `occurredAt` does not — the same
 * contact redelivered with a different claimed timestamp is still the same
 * contact.
 *
 * @api
 */
final readonly class UtmAttribution
{
    public const int MAX_IDENTIFIER_LENGTH = 191;

    /**
     * @var non-empty-string
     */
    public string $fingerprint;

    /**
     * @var non-empty-string
     */
    public string $dedupeKey;

    /**
     * @param non-empty-string $entityId identifier of the attributed entity, application-owned
     * @param non-empty-string $eventId idempotency key: stable across retries of one business event, new for a new one
     *
     * @throws InvalidUtmValue when an identifier is empty, not valid UTF-8, or longer than {@see self::MAX_IDENTIFIER_LENGTH} bytes
     */
    public function __construct(
        public string $entityId,
        public string $eventId,
        public InteractionType $interactionType,
        public UtmTouchpoint $touchpoint,
    ) {
        $this->assertIdentifier($entityId, 'entity id');
        $this->assertIdentifier($eventId, 'event id');

        $this->fingerprint = UtmFingerprint::of($entityId, $interactionType, $touchpoint);
        $this->dedupeKey = UtmFingerprint::dedupeKey($eventId, $this->fingerprint);
    }

    /**
     * @throws InvalidUtmValue
     */
    private function assertIdentifier(string $value, string $name): void
    {
        if (\trim($value) === '') {
            throw InvalidUtmValue::identifier($name, 'must not be empty');
        }

        if (\strlen($value) > self::MAX_IDENTIFIER_LENGTH) {
            throw InvalidUtmValue::identifier(
                $name,
                \sprintf('must not exceed %d bytes', self::MAX_IDENTIFIER_LENGTH),
            );
        }

        if (!\mb_check_encoding($value, 'UTF-8')) {
            throw InvalidUtmValue::identifier($name, 'must be valid UTF-8');
        }
    }
}
