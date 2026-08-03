<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * The single place that knows how a touchpoint history looks inside a cookie.
 *
 * Format: `{"v":1,"t":[{"s":…,"m":…,"c":…,"tr":…,"ct":…,"i":…,"ci":{…},"r":…,"lp":…,"at":…}]}`
 * with `at` as a Unix timestamp. Keys are short because the whole history has
 * to fit in a single cookie.
 *
 * Decoding is deliberately forgiving and never throws: a foreign version,
 * malformed JSON, an oversized value or a broken entry yields an empty history
 * — the cookie is client-controlled and must not be able to break a request.
 *
 * @psalm-type UtmCookieEntry = array{
 *     s?: mixed, m?: mixed, c?: mixed, tr?: mixed, ct?: mixed, i?: mixed,
 *     ci?: mixed, r?: mixed, lp?: mixed, at?: mixed,
 * }
 *
 * @api
 */
final readonly class UtmCookieCodec
{
    public const int VERSION = 1;
    public const int DEFAULT_MAX_LENGTH = 3500;

    /**
     * @param int<1, max> $maxLength largest cookie value to produce or accept, in bytes
     */
    public function __construct(
        private int $maxLength = self::DEFAULT_MAX_LENGTH,
    ) {}

    /**
     * Serialises the history, dropping the oldest touchpoints until the value
     * fits {@see $maxLength}.
     */
    public function encode(UtmHistory $history): string
    {
        $touchpoints = $history->all();

        while ($touchpoints !== []) {
            $encoded = \json_encode(
                ['v' => self::VERSION, 't' => \array_map($this->toEntry(...), $touchpoints)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (\strlen($encoded) <= $this->maxLength) {
                return $encoded;
            }

            \array_pop($touchpoints);
        }

        return \json_encode(['v' => self::VERSION, 't' => []], JSON_THROW_ON_ERROR);
    }

    public function decode(?string $value): UtmHistory
    {
        if ($value === null || $value === '' || \strlen($value) > $this->maxLength) {
            return UtmHistory::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return UtmHistory::empty();
        }

        if (!\is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION || !\is_array($decoded['t'] ?? null)) {
            return UtmHistory::empty();
        }

        $touchpoints = [];

        /** @var mixed $entry */
        foreach ($decoded['t'] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            /** @var UtmCookieEntry $entry */
            $touchpoint = $this->toTouchpoint($entry);

            if ($touchpoint instanceof UtmTouchpoint) {
                $touchpoints[] = $touchpoint;
            }
        }

        return UtmHistory::of(...$touchpoints);
    }

    /**
     * @return array<string, mixed>
     */
    private function toEntry(UtmTouchpoint $touchpoint): array
    {
        $entry = [
            's' => $touchpoint->utm->source,
            'm' => $touchpoint->utm->medium,
            'c' => $touchpoint->utm->campaign,
            'tr' => $touchpoint->utm->term,
            'ct' => $touchpoint->utm->content,
            'i' => $touchpoint->utm->id,
            'ci' => $touchpoint->clickIds->toArray(),
            'r' => $touchpoint->referrer?->url,
            'lp' => $touchpoint->landingPage,
            'at' => $touchpoint->occurredAt->getTimestamp(),
        ];

        return \array_filter(
            $entry,
            static fn(mixed $v): bool => $v !== null && $v !== [],
        );
    }

    /**
     * @param UtmCookieEntry $entry
     */
    private function toTouchpoint(array $entry): ?UtmTouchpoint
    {
        $occurredAt = $this->occurredAt($entry['at'] ?? null);

        if (!$occurredAt instanceof \DateTimeImmutable) {
            return null;
        }

        $utm = UtmParameters::fromArray([
            'utm_source' => $entry['s'] ?? null,
            'utm_medium' => $entry['m'] ?? null,
            'utm_campaign' => $entry['c'] ?? null,
            'utm_term' => $entry['tr'] ?? null,
            'utm_content' => $entry['ct'] ?? null,
            'utm_id' => $entry['i'] ?? null,
        ]);

        $touchpoint = UtmTouchpoint::of(
            utm: $utm,
            occurredAt: $occurredAt,
            clickIds: $this->clickIds($entry['ci'] ?? null),
            referrer: $this->referrer($entry['r'] ?? null),
            landingPage: $this->landingPage($entry['lp'] ?? null),
        );

        return $touchpoint->isEmpty() ? null : $touchpoint;
    }

    private function occurredAt(mixed $value): ?\DateTimeImmutable
    {
        return \is_int($value)
            ? (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'))
            : null;
    }

    private function clickIds(mixed $value): ClickIds
    {
        return \is_array($value) ? ClickIds::fromArray($value) : ClickIds::empty();
    }

    private function referrer(mixed $value): ?Referrer
    {
        return \is_string($value) ? Referrer::of($value) : null;
    }

    private function landingPage(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
