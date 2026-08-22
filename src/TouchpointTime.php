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
     * Caps a claim about the future to `now`, and rejects a claim older than
     * `now - maxAgeSeconds`.
     *
     * The two halves are deliberately asymmetric. A moment in the future cannot
     * be true, and `now` is the nearest moment that can be — capping is a
     * correction. A moment before the retention window may well be true; moving
     * it to the window boundary would invent a timestamp that changes on every
     * request and would keep the touchpoint alive forever, since it lands back
     * inside the window each time. Out of the window means out of the history.
     *
     * The clock is read once: two reads could straddle a second boundary and
     * decide "future" and "stale" against different values of `now`.
     *
     * @param int<0, max> $maxAgeSeconds
     *
     * @return \DateTimeImmutable|null null when the claim is older than the window
     */
    public static function withinWindow(
        \DateTimeImmutable $occurredAt,
        ClockInterface $clock,
        int $maxAgeSeconds,
    ): ?\DateTimeImmutable {
        $now = $clock->now();

        if ($occurredAt > $now) {
            return $now;
        }

        return $occurredAt < $now->sub(new \DateInterval('PT' . $maxAgeSeconds . 'S')) ? null : $occurredAt;
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
