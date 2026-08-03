<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Captures the touchpoint of the current request and maintains the stored
 * history.
 *
 * One middleware with pluggable {@see UtmSource} implementations instead of one
 * middleware per transport: the combination is configuration, and the public
 * surface that has to stay backward compatible stays small.
 *
 * The request attributes of {@see UtmAttributes} are always set, including on
 * ignored paths and without consent, so downstream code never has to tell
 * "middleware did not run" from "nothing was captured".
 *
 * Capture writes a `Set-Cookie` header and therefore makes a response
 * uncacheable — use `ignoredPaths` or {@see NullUtmHistoryStore} for cacheable
 * routes.
 *
 * @api
 */
final readonly class UtmCaptureMiddleware implements MiddlewareInterface
{
    public const int DEFAULT_MAX_TOUCHPOINT_AGE = 90 * 24 * 60 * 60;

    /**
     * @param list<UtmSource> $sources in priority order; the first non-null result wins
     * @param list<string> $ignoredPaths request paths to skip, matched by prefix
     * @param int<1, max> $maxTouchpoints
     * @param int<0, max> $maxTouchpointAge how old a claimed moment may be, in seconds
     * @param bool $updateExisting whether a touchpoint similar to the newest stored one is appended
     * @param bool $captureOrganic whether a visit with neither campaign nor click id becomes a touchpoint
     * @param bool $clearHistoryWithoutConsent whether a stored history is dropped when consent is absent
     */
    public function __construct(
        private array $sources,
        private UtmHistoryStore $store,
        private ClockInterface $clock,
        private ConsentPolicy $consentPolicy = new AllowAllConsentPolicy(),
        private UtmSimilarity $similarity = UtmSimilarity::Full,
        private array $ignoredPaths = [],
        private int $maxTouchpoints = 5,
        private int $maxTouchpointAge = self::DEFAULT_MAX_TOUCHPOINT_AGE,
        private bool $enabled = true,
        private bool $updateExisting = false,
        private bool $captureOrganic = false,
        private bool $clearHistoryWithoutConsent = false,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled || $this->isIgnored($request)) {
            return $handler->handle($this->withAttributes($request, null, UtmHistory::empty()));
        }

        if (!$this->consentPolicy->allowsPersistence($request)) {
            $response = $handler->handle($this->withAttributes($request, null, UtmHistory::empty()));

            return $this->clearHistoryWithoutConsent ? $this->store->forget($response) : $response;
        }

        $stored = $this->clampStored($this->store->read($request))->limited($this->maxTouchpoints);
        $current = $this->capture($request);
        $history = $this->merge($stored, $current);

        $response = $handler->handle($this->withAttributes($request, $current, $history));

        return $history === $stored ? $response : $this->store->write($response, $history);
    }

    private function capture(ServerRequestInterface $request): ?UtmTouchpoint
    {
        foreach ($this->sources as $source) {
            $touchpoint = $source->extract($request);

            if (!$touchpoint instanceof UtmTouchpoint) {
                continue;
            }

            if (!$this->captureOrganic && $touchpoint->utm->isEmpty() && $touchpoint->clickIds->isEmpty()) {
                continue;
            }

            return $touchpoint->withOccurredAt(
                TouchpointTime::clamp($touchpoint->occurredAt, $this->clock, $this->maxTouchpointAge),
            );
        }

        return null;
    }

    /**
     * A stored touchpoint's `occurredAt` was clamped when it was captured, but
     * the store itself does not re-check it: a hand-edited cookie value could
     * carry a claim far outside the window. Clamping again on every read keeps
     * that guarantee for values the codec did not just produce.
     */
    private function clampStored(UtmHistory $stored): UtmHistory
    {
        if ($stored->isEmpty()) {
            return $stored;
        }

        return UtmHistory::of(...\array_map(
            fn(UtmTouchpoint $t): UtmTouchpoint => $t->withOccurredAt(
                TouchpointTime::clamp($t->occurredAt, $this->clock, $this->maxTouchpointAge),
            ),
            $stored->all(),
        ));
    }

    private function merge(UtmHistory $stored, ?UtmTouchpoint $current): UtmHistory
    {
        if (!$current instanceof UtmTouchpoint) {
            return $stored;
        }

        $newest = $stored->latest();

        if (!$this->updateExisting && $newest instanceof UtmTouchpoint && $this->similarity->isSimilar($newest, $current)) {
            return $stored;
        }

        return $stored->with($current)->limited($this->maxTouchpoints);
    }

    private function withAttributes(
        ServerRequestInterface $request,
        ?UtmTouchpoint $current,
        UtmHistory $history,
    ): ServerRequestInterface {
        return $request
            ->withAttribute(UtmAttributes::CURRENT, $current)
            ->withAttribute(UtmAttributes::HISTORY, $history)
            ->withAttribute(UtmAttributes::EFFECTIVE, $current ?? $history->latest());
    }

    private function isIgnored(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        foreach ($this->ignoredPaths as $ignored) {
            if ($ignored !== '' && \str_starts_with($path, $ignored)) {
                return true;
            }
        }

        return false;
    }
}
