<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Record produced by {@see InMemoryUtmAttributionRepository}.
 *
 * @api
 */
final readonly class InMemoryUtmAttributionRecord implements UtmAttributionRecord
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private string $id,
        private UtmAttribution $attribution,
        private \DateTimeImmutable $recordedAt,
    ) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function attribution(): UtmAttribution
    {
        return $this->attribution;
    }

    #[\Override]
    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
