<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Exception;

/**
 * Thrown for values the application controls — entity and event identifiers.
 *
 * Client-controlled campaign values never raise: they are normalised, dropped
 * or truncated instead.
 *
 * @api
 */
final class InvalidUtmValue extends \InvalidArgumentException implements UtmException
{
    public static function identifier(string $name, string $reason): self
    {
        return new self(\sprintf('Invalid %s: %s', $name, $reason));
    }
}
