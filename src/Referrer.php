<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * The page a visitor arrived from.
 *
 * The value object accepts an already sanitised URL: stripping query
 * parameters is the job of {@see LandingPageSanitizer} in the capture layer, so
 * that this type stays free of service dependencies.
 *
 * The scheme, however, is checked here and not delegated. This constructor is
 * the single gate every referrer passes — the capture sources, the cookie codec
 * and a storage adapter reading a row back all go through it — so a
 * `javascript:` or `data:` URL rejected only in the capture layer would still
 * arrive from a hand-edited cookie or from a row written by an older version.
 *
 * Only the host takes part in the attribution fingerprint — a full URL with its
 * query string would differ on every visit and defeat deduplication.
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class Referrer
{
    public const int MAX_URL_LENGTH = 500;
    public const int MAX_HOST_LENGTH = 255;

    /**
     * @var list<non-empty-string>
     */
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @param non-empty-string $url
     * @param non-empty-string $host
     */
    private function __construct(
        public string $url,
        public string $host,
    ) {}

    /**
     * @param string $sanitizedUrl absolute `http`/`https` URL already passed
     *        through a sanitizer; anything else yields null
     */
    public static function of(string $sanitizedUrl): ?self
    {
        $url = \trim(\preg_replace('/[\x00-\x1F\x7F]+/u', '', $sanitizedUrl) ?? '');

        if ($url === '' || \mb_strlen($url) > self::MAX_URL_LENGTH) {
            return null;
        }

        $scheme = \parse_url($url, PHP_URL_SCHEME);

        if (!\is_string($scheme) || !\in_array(\mb_strtolower($scheme), self::ALLOWED_SCHEMES, strict: true)) {
            return null;
        }

        $host = \parse_url($url, PHP_URL_HOST);

        if (!\is_string($host) || $host === '' || \mb_strlen($host) > self::MAX_HOST_LENGTH) {
            return null;
        }

        return new self(url: $url, host: \mb_strtolower($host));
    }

    public function isInternal(string $currentHost): bool
    {
        return $this->host === \mb_strtolower(\trim($currentHost));
    }

    /**
     * Same as {@see self::of()}, but returns null for a referrer internal to
     * `$currentHost` — a visit from one page of the current site to another
     * is not a touchpoint to attribute the visit to.
     *
     * @param string $sanitizedUrl absolute URL already passed through a sanitizer
     */
    public static function external(string $sanitizedUrl, string $currentHost): ?self
    {
        $referrer = self::of($sanitizedUrl);

        if (!$referrer instanceof self || $referrer->isInternal($currentHost)) {
            return null;
        }

        return $referrer;
    }

    public function equals(self $other): bool
    {
        return $this->url === $other->url;
    }
}
