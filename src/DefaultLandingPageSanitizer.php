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
     * @param int<1, max> $maxLength
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

        $query = $this->filterQuery($parts['query'] ?? '');

        if ($query !== '') {
            $sanitized .= '?' . $query;
        }

        return \mb_substr($sanitized, 0, $this->maxLength);
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
