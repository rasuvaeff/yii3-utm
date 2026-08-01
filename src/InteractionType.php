<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * The kind of business event an attribution row belongs to.
 *
 * Deliberately a validated string, not an enum: a library cannot know which
 * events its consumers track. `registration` and `purchase` are provided as
 * named constructors, everything else goes through {@see self::of()}.
 *
 * The pattern matches the width of the storage column, so a valid value always
 * fits.
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class InteractionType
{
    public const int MAX_LENGTH = 32;
    public const string PATTERN = '/^[a-z][a-z0-9_]{0,31}\z/';

    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        if ($value === '' || \preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid interaction type "%s"', $value));
        }

        return new self(value: $value);
    }

    public static function registration(): self
    {
        return new self(value: 'registration');
    }

    public static function purchase(): self
    {
        return new self(value: 'purchase');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
