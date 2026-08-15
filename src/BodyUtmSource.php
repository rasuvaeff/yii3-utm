<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a touchpoint from a nested `utm` key of the parsed body — the
 * recommended transport for a cross-domain SPA at registration or checkout.
 *
 * Accepted shape:
 *
 * ```json
 * {"utm": {"utm_source": "...", "click_ids": {"gclid": "..."},
 *          "landing_page": "...", "referrer": "...", "occurred_at": 1754042400}}
 * ```
 *
 * Only the nested key is read: a flat body would let unrelated form fields
 * named `source` or `medium` become attribution data.
 *
 * @api
 */
final readonly class BodyUtmSource implements UtmSource
{
    public const string DEFAULT_KEY = 'utm';

    /**
     * @param non-empty-string $key
     * @param list<non-empty-string> $clickIdKeys
     */
    public function __construct(
        private ClockInterface $clock,
        private LandingPageSanitizer $sanitizer = new DefaultLandingPageSanitizer(),
        private string $key = self::DEFAULT_KEY,
        private array $clickIdKeys = ClickIds::KNOWN_KEYS,
    ) {}

    #[\Override]
    public function extract(ServerRequestInterface $request): ?UtmTouchpoint
    {
        $body = $request->getParsedBody();

        if (!\is_array($body)) {
            return null;
        }

        /** @var mixed $payload */
        $payload = $body[$this->key] ?? null;

        if (!\is_array($payload)) {
            return null;
        }

        $touchpoint = UtmTouchpoint::of(
            utm: UtmParameters::fromArray($payload),
            occurredAt: TouchpointTime::parse($this->timeValue($payload['occurred_at'] ?? null), $this->clock),
            clickIds: $this->clickIds($payload['click_ids'] ?? null),
            referrer: Referrer::external(
                $this->sanitizer->sanitize($this->stringValue($payload['referrer'] ?? null)),
                $request->getUri()->getHost(),
            ),
            landingPage: $this->sanitizer->sanitize($this->stringValue($payload['landing_page'] ?? null)),
        );

        return $touchpoint->isEmpty() ? null : $touchpoint;
    }

    private function clickIds(mixed $value): ClickIds
    {
        if (!\is_array($value)) {
            return ClickIds::empty();
        }

        return ClickIds::fromArray(\array_intersect_key($value, \array_fill_keys($this->clickIdKeys, value: true)));
    }

    private function timeValue(mixed $value): ?string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : null);
    }

    private function stringValue(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
