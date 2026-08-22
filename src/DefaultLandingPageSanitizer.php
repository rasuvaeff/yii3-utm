<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Keeps scheme, host, port and path; drops the fragment and every query
 * parameter outside the allow-list.
 *
 * The default allow-list is exactly what this package understands — campaign
 * parameters and click identifiers. Everything else in a landing URL is at best
 * noise and at worst a password-reset token or an e-mail address that would end
 * up in the attribution journal.
 *
 * @api
 */
final readonly class DefaultLandingPageSanitizer implements LandingPageSanitizer
{
    /**
     * @var list<string>
     */
    public const array DEFAULT_ALLOWED_QUERY_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        ...ClickIds::KNOWN_KEYS,
    ];

    /**
     * @param list<string> $allowedQueryKeys
     * @param int<1, max> $maxLength budget in code points; `sanitize()` is idempotent on its
     *        own output as long as the budget still admits `scheme://` plus one character of
     *        the host, since a value cut shorter than that stops parsing as an absolute URL
     *        at all and sanitises to an empty string on the next pass
     */
    public function __construct(
        private array $allowedQueryKeys = self::DEFAULT_ALLOWED_QUERY_KEYS,
        private int $maxLength = UtmTouchpoint::MAX_LANDING_PAGE_LENGTH,
    ) {}

    #[\Override]
    public function sanitize(string $url): string
    {
        $trimmed = \trim(\preg_replace('/[\x00-\x1F\x7F]+/u', '', $url) ?? '');

        if ($trimmed === '') {
            return '';
        }

        $parts = \parse_url($trimmed);

        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? \mb_strtolower($parts['scheme']) : 'https';

        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $sanitized = $scheme . '://' . \mb_strtolower($parts['host']);

        if (isset($parts['port'])) {
            $sanitized .= ':' . $parts['port'];
        }

        $sanitized .= $parts['path'] ?? '';

        return $this->fit($sanitized, $this->filterQuery($parts['query'] ?? ''));
    }

    /**
     * Joins the base and the filtered query within the budget, cutting only on
     * a boundary this very method can read back.
     *
     * A plain `mb_substr()` over the joined value broke the one property the
     * sanitizer owes its callers — `sanitize(sanitize($url)) === sanitize($url)`.
     * A cut inside a percent-triplet left `%D`, which the next pass re-encoded
     * to `%25D`; a cut inside a pair left a partial key (`gcl`), which the next
     * pass dropped as unknown; a cut right after `?` left a separator that the
     * next pass never re-emitted. Storage tolerated all three, but the stored
     * value stopped being a URL, and no consumer could compare it with a freshly
     * sanitized one.
     */
    private function fit(string $base, string $query): string
    {
        if ($query === '') {
            return $this->cut($base);
        }

        // No shortcut for "everything fits": the loop below already keeps every
        // pair when the budget allows it, and a guard whose branches produce
        // the same string is a guard no test can tell apart.
        $budget = $this->maxLength - \mb_strlen($base) - 1;
        $kept = '';

        foreach (\explode('&', $query) as $pair) {
            $candidate = $kept === '' ? $pair : $kept . '&' . $pair;

            if (\mb_strlen($candidate) > $budget) {
                break;
            }

            $kept = $candidate;
        }

        return $kept === '' ? $this->cut($base) : $base . '?' . $kept;
    }

    /**
     * Cuts a value with no query left to protect. Two fragments can survive the
     * cut and are dropped: an incomplete percent-triplet, and the `:` of a port
     * whose digits did not fit — `parse_url()` reads `http://localhost:` back
     * without a port, so keeping the separator would make the next pass return
     * a different string.
     */
    private function cut(string $value): string
    {
        if (\mb_strlen($value) <= $this->maxLength) {
            return $value;
        }

        // Deliberately byte-wise: a hex digit, `%` and `:` are ASCII, so no
        // continuation byte of a multibyte character can match, while `/u`
        // would return null on a value whose encoding the client broke.
        return \preg_replace('/(?:%[0-9A-Fa-f]?|:)\z/', '', \mb_substr($value, 0, $this->maxLength)) ?? '';
    }

    private function filterQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        \parse_str($query, $parsed);

        $kept = [];

        foreach ($this->allowedQueryKeys as $key) {
            /** @var mixed $value */
            $value = $parsed[$key] ?? null;

            if (\is_string($value) && $value !== '') {
                $kept[$key] = $value;
            }
        }

        return \http_build_query($kept);
    }
}
