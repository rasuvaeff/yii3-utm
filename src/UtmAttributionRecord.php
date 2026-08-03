<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * A stored attribution row.
 *
 * `recordedAt()` is assigned by the server when the row is written and, with
 * the identifier, defines the canonical order of the journal. The timestamp
 * inside the touchpoint is what the source claimed and never orders anything.
 *
 * @api
 */
interface UtmAttributionRecord
{
    /**
     * @return non-empty-string storage identifier, monotonically increasing with insertion order
     */
    public function id(): string;

    public function attribution(): UtmAttribution;

    public function recordedAt(): \DateTimeImmutable;
}
