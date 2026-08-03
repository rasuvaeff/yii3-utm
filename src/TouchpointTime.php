<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;

/**
 * Parses and clamps the moment a client claims a touchpoint happened.
 *
 * A claimed timestamp is accepted only to keep analytics readable and to bound
 * retention. It never authenticates anything and never decides storage order.
 *
 * @internal
 */
final readonly class TouchpointTime
{
    public static function parse(?string $value, ClockInterface $clock): \DateTimeImmutable
    {
        $now = $clock->now();

        if ($value === null || \trim($value) === '') {
            return $now;
        }

        $parsed = self::tryParse(\trim($value));

        return $parsed ?? $now;
    }

    /**
     * Keeps the moment inside `[now - maxAgeSeconds, now]`.
     *
     * @param int<0, max> $maxAgeSeconds
     */
    public static function clamp(
        \DateTimeImmutable $occurredAt,
        ClockInterface $clock,
        int $maxAgeSeconds,
    ): \DateTimeImmutable {
        $now = $clock->now();

        if ($occurredAt > $now) {
            return $now;
        }

        $earliest = $now->sub(new \DateInterval('PT' . $maxAgeSeconds . 'S'));

        return $occurredAt < $earliest ? $earliest : $occurredAt;
    }

    private static function tryParse(string $value): ?\DateTimeImmutable
    {
        if (\preg_match('/^\d{1,11}\z/', $value) === 1) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'));
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
