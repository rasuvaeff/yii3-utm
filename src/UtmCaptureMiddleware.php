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
     * @param list<string> $ignoredPaths request paths to skip, matched on a segment
     *        boundary: `/api` skips `/api` and `/api/v1`, but not `/api-docs`
     * @param int<1, max> $maxTouchpoints
     * @param int<0, max> $maxTouchpointAge how old a claimed moment may be, in seconds;
     *        an older claim produces no touchpoint and drops a stored one
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

        $read = $this->store->read($request);
        $stored = $this->withinWindow($read)->limited($this->maxTouchpoints);
        $current = $this->capture($request);
        $history = $this->merge($stored, $current);

        $response = $handler->handle($this->withAttributes($request, $current, $history));

        // Compared against what was read, not against the filtered value: a
        // touchpoint dropped for age leaves `$history === $stored`, and writing
        // only on that condition would leave the stale entry in the cookie
        // forever, re-dropped on every request. Every step below returns the
        // very same object when it changes nothing, so identity here still
        // means "nothing to persist" and a response stays cacheable.
        return $history === $read ? $response : $this->store->write($response, $history);
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

            $occurredAt = TouchpointTime::withinWindow(
                $touchpoint->occurredAt,
                $this->clock,
                $this->maxTouchpointAge,
            );

            if (!$occurredAt instanceof \DateTimeImmutable) {
                continue;
            }

            return $touchpoint->withOccurredAt($occurredAt);
        }

        return null;
    }

    /**
     * A stored touchpoint's `occurredAt` was checked when it was captured, but
     * the store itself does not re-check it: a hand-edited cookie value could
     * carry a claim far outside the window. Re-applying the window on every read
     * keeps that guarantee for values the codec did not just produce.
     *
     * Returns the argument unchanged when nothing was dropped or capped, so that
     * {@see self::process()} can tell "the cookie is already correct" from
     * "the cookie has to be rewritten" by identity.
     */
    private function withinWindow(UtmHistory $stored): UtmHistory
    {
        $kept = [];
        $changed = false;

        foreach ($stored->all() as $touchpoint) {
            $occurredAt = TouchpointTime::withinWindow(
                $touchpoint->occurredAt,
                $this->clock,
                $this->maxTouchpointAge,
            );

            if (!$occurredAt instanceof \DateTimeImmutable) {
                $changed = true;

                continue;
            }

            if ($occurredAt === $touchpoint->occurredAt) {
                $kept[] = $touchpoint;

                continue;
            }

            $changed = true;
            $kept[] = $touchpoint->withOccurredAt($occurredAt);
        }

        return $changed ? UtmHistory::of(...$kept) : $stored;
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

    /**
     * A bare `str_starts_with()` made `/api` swallow `/api-docs` and `/health`
     * swallow `/healthz-external`: capture then silently stopped on routes the
     * application never meant to exclude. The comparison is made on a segment
     * boundary — the entry itself, or anything below it.
     */
    private function isIgnored(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        foreach ($this->ignoredPaths as $ignored) {
            if ($ignored === '') {
                continue;
            }

            if ($path === $ignored || \str_starts_with($path, \rtrim($ignored, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
