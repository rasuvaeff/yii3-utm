<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a touchpoint from `X-Utm-*` headers — the transport of a cross-domain
 * SPA that keeps its own history in `localStorage`.
 *
 * Everything here is client-controlled, including `X-Utm-Occurred-At`. The
 * value is parsed and clamped later; it is never treated as proof.
 *
 * @api
 */
final readonly class HeaderUtmSource implements UtmSource
{
    public const string DEFAULT_PREFIX = 'X-Utm-';

    /**
     * @param non-empty-string $prefix
     * @param list<non-empty-string> $clickIdKeys
     */
    public function __construct(
        private ClockInterface $clock,
        private LandingPageSanitizer $sanitizer = new DefaultLandingPageSanitizer(),
        private string $prefix = self::DEFAULT_PREFIX,
        private array $clickIdKeys = ClickIds::KNOWN_KEYS,
    ) {}

    #[\Override]
    public function extract(ServerRequestInterface $request): ?UtmTouchpoint
    {
        $campaign = [];

        foreach (['source', 'medium', 'campaign', 'term', 'content', 'id'] as $field) {
            $campaign['utm_' . $field] = $this->header($request, $field);
        }

        $touchpoint = UtmTouchpoint::of(
            utm: UtmParameters::fromArray($campaign),
            occurredAt: TouchpointTime::parse($this->header($request, 'occurred-at'), $this->clock),
            clickIds: $this->clickIds($request),
            referrer: Referrer::external(
                $this->sanitizer->sanitize((string) $this->header($request, 'referrer')),
                $request->getUri()->getHost(),
            ),
            landingPage: $this->sanitizer->sanitize((string) $this->header($request, 'landing-page')),
        );

        return $touchpoint->isEmpty() ? null : $touchpoint;
    }

    private function clickIds(ServerRequestInterface $request): ClickIds
    {
        $value = $this->header($request, 'click-ids');

        if ($value === null) {
            return ClickIds::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ClickIds::empty();
        }

        if (!\is_array($decoded)) {
            return ClickIds::empty();
        }

        $decoded = \array_intersect_key($decoded, \array_fill_keys($this->clickIdKeys, true));

        return ClickIds::fromArray($decoded);
    }

    private function header(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getHeaderLine($this->prefix . $name);

        return $value === '' ? null : $value;
    }
}
