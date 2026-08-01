<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * One contact between a visitor and a campaign: what was on the link, where it
 * landed, where it came from and when the source claims it happened.
 *
 * `$occurredAt` is reported by the source — a query string, a header, a body,
 * a cookie — and is never authenticated. Ordering of stored attribution is
 * therefore server-assigned; this timestamp is analytics data.
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class UtmTouchpoint
{
    public const int MAX_LANDING_PAGE_LENGTH = 500;

    public function __construct(
        public UtmParameters $utm,
        public ClickIds $clickIds,
        public ?Referrer $referrer,
        public ?string $landingPage,
        public \DateTimeImmutable $occurredAt,
    ) {}

    public static function of(
        UtmParameters $utm,
        \DateTimeImmutable $occurredAt,
        ?ClickIds $clickIds = null,
        ?Referrer $referrer = null,
        ?string $landingPage = null,
    ): self {
        return new self(
            utm: $utm,
            clickIds: $clickIds ?? ClickIds::empty(),
            referrer: $referrer,
            landingPage: self::normalizeLandingPage($landingPage),
            occurredAt: $occurredAt,
        );
    }

    /**
     * A touchpoint is empty when it identifies no campaign at all: no `utm_*`,
     * no click id and no referrer to attribute the visit to.
     */
    public function isEmpty(): bool
    {
        return $this->utm->isEmpty()
            && $this->clickIds->isEmpty()
            && $this->referrer === null;
    }

    public function withOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        return new self(
            utm: $this->utm,
            clickIds: $this->clickIds,
            referrer: $this->referrer,
            landingPage: $this->landingPage,
            occurredAt: $occurredAt,
        );
    }

    private static function normalizeLandingPage(?string $landingPage): ?string
    {
        if ($landingPage === null) {
            return null;
        }

        $clean = \trim($landingPage);

        if ($clean === '') {
            return null;
        }

        return \mb_substr($clean, 0, self::MAX_LANDING_PAGE_LENGTH);
    }
}
