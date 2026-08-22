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
 * Forgiving is not the same as trusting. The cookie is exactly as untrusted as
 * a query string, so the referrer and the landing page it carries go through
 * the same {@see LandingPageSanitizer} the capture sources use. Without that,
 * a hand-edited cookie puts a `javascript:` URL or an arbitrary landing page
 * into the history and, through {@see UtmAttributionService}, into storage.
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

    private LandingPageSanitizer $sanitizer;

    /**
     * @param int<1, max> $maxLength largest cookie value to produce, in bytes,
     *        measured on the **percent-encoded** value that reaches the header
     * @param LandingPageSanitizer|null $sanitizer applied to the referrer and the
     *        landing page of a decoded entry; defaults to {@see DefaultLandingPageSanitizer}
     */
    public function __construct(
        private int $maxLength = self::DEFAULT_MAX_LENGTH,
        ?LandingPageSanitizer $sanitizer = null,
    ) {
        $this->sanitizer = $sanitizer ?? new DefaultLandingPageSanitizer();
    }

    /**
     * Serialises the history, dropping the oldest touchpoints until the value
     * fits {@see $maxLength}.
     *
     * The budget is spent on `urlencode($encoded)`, not on the raw JSON:
     * `Yiisoft\Cookies\Cookie` percent-encodes the value when it renders the
     * header, turning every JSON structural character — and every byte of a
     * non-ASCII value, since the payload keeps `JSON_UNESCAPED_UNICODE` — into
     * three bytes. Measuring the raw string instead lets a history of Cyrillic
     * campaigns produce a `Set-Cookie` well past the 4096-byte browser limit,
     * which browsers drop silently.
     */
    public function encode(UtmHistory $history): string
    {
        $touchpoints = $history->all();

        while ($touchpoints !== []) {
            $encoded = \json_encode(
                ['v' => self::VERSION, 't' => \array_map($this->toEntry(...), $touchpoints)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (\strlen(\urlencode($encoded)) <= $this->maxLength) {
                return $encoded;
            }

            \array_pop($touchpoints);
        }

        return \json_encode(['v' => self::VERSION, 't' => []], JSON_THROW_ON_ERROR);
    }

    /**
     * The inbound check stays on the **raw** length on purpose: a PSR-7
     * `getCookieParams()` value is already percent-decoded, and cookies written
     * before the encoding fix carry up to `$maxLength` raw bytes. Tightening
     * this symmetrically would throw away the history of every existing visitor
     * on upgrade. Encoding got stricter; decoding stays permissive.
     */
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
        return \is_string($value) ? Referrer::of($this->sanitizer->sanitize($value)) : null;
    }

    private function landingPage(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $sanitized = $this->sanitizer->sanitize($value);

        return $sanitized === '' ? null : $sanitized;
    }
}
