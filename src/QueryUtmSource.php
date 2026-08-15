<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a touchpoint from the query string of a server-rendered request.
 *
 * The landing page is the current URI and the referrer comes from the `Referer`
 * header; both go through the sanitizer before they are stored anywhere. The
 * moment of contact is the server clock — a query string carries no trustworthy
 * timestamp.
 *
 * @api
 */
final readonly class QueryUtmSource implements UtmSource
{
    /**
     * @var list<non-empty-string>
     */
    public const array DEFAULT_UTM_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
    ];

    /**
     * @param list<non-empty-string> $utmKeys
     * @param list<non-empty-string> $clickIdKeys
     */
    public function __construct(
        private ClockInterface $clock,
        private LandingPageSanitizer $sanitizer = new DefaultLandingPageSanitizer(),
        private array $utmKeys = self::DEFAULT_UTM_KEYS,
        private array $clickIdKeys = ClickIds::KNOWN_KEYS,
    ) {}

    #[\Override]
    public function extract(ServerRequestInterface $request): ?UtmTouchpoint
    {
        $query = $request->getQueryParams();
        $utm = \array_intersect_key($query, \array_fill_keys($this->utmKeys, value: true));
        $clickIds = \array_intersect_key($query, \array_fill_keys($this->clickIdKeys, value: true));

        $touchpoint = UtmTouchpoint::of(
            utm: UtmParameters::fromArray($utm),
            occurredAt: $this->clock->now(),
            clickIds: ClickIds::fromArray($clickIds),
            referrer: Referrer::external(
                $this->sanitizer->sanitize($request->getHeaderLine('Referer')),
                $request->getUri()->getHost(),
            ),
            landingPage: $this->sanitizer->sanitize((string) $request->getUri()),
        );

        return $touchpoint->isEmpty() ? null : $touchpoint;
    }
}
