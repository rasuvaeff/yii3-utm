<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Advertising click identifiers carried by a touchpoint.
 *
 * Auto-tagging platforms attach a click id and no `utm_*` parameters at all —
 * Google Ads sends a bare `gclid` — so a capture layer that only looks for
 * `utm_*` loses the most expensive traffic there is.
 *
 * Only whitelisted keys survive, in whitelist order: the serialised form feeds
 * the attribution fingerprint, and an input-dependent key order would make that
 * fingerprint non-deterministic. The serialised length is capped at
 * {@see self::MAX_SERIALIZED_LENGTH} — the width of the storage column — by
 * dropping trailing keys, because truncating in the database instead would
 * disagree with a fingerprint computed from the full value.
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class ClickIds
{
    public const int MAX_VALUE_LENGTH = 255;
    public const int MAX_SERIALIZED_LENGTH = 500;

    /**
     * @var list<non-empty-string>
     */
    public const array KNOWN_KEYS = [
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'yclid',
        'ttclid',
        'msclkid',
        'li_fat_id',
        'twclid',
    ];

    private const string VALUE_PATTERN = '/^[A-Za-z0-9._~-]+\z/';

    /**
     * @param array<non-empty-string, non-empty-string> $values validated, whitelist-ordered
     */
    private function __construct(
        private array $values,
    ) {}

    public static function empty(): self
    {
        return new self(values: []);
    }

    /**
     * @param array<string, mixed> $data untrusted map
     */
    public static function fromArray(array $data): self
    {
        $accepted = [];
        $length = 2;

        foreach (self::KNOWN_KEYS as $key) {
            $value = self::normalize($data[$key] ?? null);

            if ($value === null) {
                continue;
            }

            $entryLength = \strlen(\json_encode($key, JSON_THROW_ON_ERROR))
                + \strlen(\json_encode($value, JSON_THROW_ON_ERROR))
                + ($accepted === [] ? 1 : 2);

            if ($length + $entryLength > self::MAX_SERIALIZED_LENGTH) {
                continue;
            }

            $accepted[$key] = $value;
            $length += $entryLength;
        }

        return new self(values: $accepted);
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->values);
    }

    public function equals(self $other): bool
    {
        return $this->values === $other->values;
    }

    /**
     * @return non-empty-string
     */
    public function toJson(): string
    {
        if ($this->values === []) {
            return '{}';
        }

        return \json_encode($this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return non-empty-string|null
     */
    private static function normalize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $clean = \trim($value);

        if ($clean === '' || \strlen($clean) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        return \preg_match(self::VALUE_PATTERN, $clean) === 1 ? $clean : null;
    }
}
