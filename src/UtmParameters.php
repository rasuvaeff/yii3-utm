<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Campaign tuple of a single touchpoint: the five standard `utm_*` parameters
 * plus GA4 `utm_id`.
 *
 * Values arrive from untrusted transports, so every factory normalises them:
 * control characters are stripped, whitespace trimmed, the result truncated to
 * {@see self::MAX_VALUE_LENGTH} characters and empty strings collapsed to
 * `null`. The object carries neither a timestamp nor a landing page — both
 * belong to {@see UtmTouchpoint}.
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class UtmParameters
{
    public const int MAX_VALUE_LENGTH = 255;

    public function __construct(
        public ?string $source = null,
        public ?string $medium = null,
        public ?string $campaign = null,
        public ?string $term = null,
        public ?string $content = null,
        public ?string $id = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data untrusted map with `utm_`-prefixed or bare keys
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: self::normalize(self::pick($data, 'utm_source', 'source')),
            medium: self::normalize(self::pick($data, 'utm_medium', 'medium')),
            campaign: self::normalize(self::pick($data, 'utm_campaign', 'campaign')),
            term: self::normalize(self::pick($data, 'utm_term', 'term')),
            content: self::normalize(self::pick($data, 'utm_content', 'content')),
            id: self::normalize(self::pick($data, 'utm_id', 'id')),
        );
    }

    /**
     * @return array{
     *     utm_source: string|null,
     *     utm_medium: string|null,
     *     utm_campaign: string|null,
     *     utm_term: string|null,
     *     utm_content: string|null,
     *     utm_id: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'utm_source' => $this->source,
            'utm_medium' => $this->medium,
            'utm_campaign' => $this->campaign,
            'utm_term' => $this->term,
            'utm_content' => $this->content,
            'utm_id' => $this->id,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->source === null
            && $this->medium === null
            && $this->campaign === null
            && $this->term === null
            && $this->content === null
            && $this->id === null;
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function pick(array $data, string $prefixed, string $bare): mixed
    {
        return $data[$prefixed] ?? $data[$bare] ?? null;
    }

    private static function normalize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $clean = \trim(\preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '');

        if ($clean === '') {
            return null;
        }

        $truncated = \mb_substr($clean, 0, self::MAX_VALUE_LENGTH);

        return $truncated === '' ? null : $truncated;
    }
}
