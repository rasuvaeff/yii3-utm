<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Utm\CallbackConsentPolicy;
use Rasuvaeff\Yii3Utm\CookieUtmHistoryStore;
use Rasuvaeff\Yii3Utm\NullUtmHistoryStore;
use Rasuvaeff\Yii3Utm\QueryUtmSource;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\Tests\Support\StaticUtmSource;
use Rasuvaeff\Yii3Utm\UtmAttributes;
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmHistoryStore;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmRequest;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(UtmCaptureMiddleware::class)]
final class UtmCaptureMiddlewareTest
{
    private FrozenClock $clock;
    private UtmCookieCodec $codec;
    private CookieUtmHistoryStore $store;
    private ?ServerRequestInterface $seen = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
        $this->codec = new UtmCookieCodec();
        $this->store = new CookieUtmHistoryStore(codec: $this->codec, clock: $this->clock);
        $this->seen = null;
    }

    public function capturesTheCurrentTouchpointAndWritesTheCookie(): void
    {
        $response = $this->handle($this->middleware(), $this->request('/?utm_source=google&utm_medium=cpc'));

        Assert::same(UtmRequest::current($this->seenRequest())?->utm->source, 'google');
        Assert::same(UtmRequest::history($this->seenRequest())->count(), 1);
        Assert::same(UtmRequest::effective($this->seenRequest())?->utm->source, 'google');
        Assert::same(\count($response->getHeader('Set-Cookie')), 1);
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('utm_history=');
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('HttpOnly');
    }

    public function attributesAreAlwaysPresentEvenWithNothingToCapture(): void
    {
        $this->handle($this->middleware(), $this->request('/'));

        Assert::null(UtmRequest::current($this->seenRequest()));
        Assert::true(UtmRequest::history($this->seenRequest())->isEmpty());
        Assert::null(UtmRequest::effective($this->seenRequest()));
        Assert::true($this->seenRequest()->getAttribute(UtmAttributes::HISTORY) instanceof UtmHistory);
    }

    public function effectiveFallsBackToStoredHistory(): void
    {
        $request = $this->request('/')->withCookieParams([
            'utm_history' => $this->codec->encode(UtmHistory::of($this->touchpoint('newsletter'))),
        ]);

        $this->handle($this->middleware(), $request);

        Assert::null(UtmRequest::current($this->seenRequest()));
        Assert::same(UtmRequest::effective($this->seenRequest())?->utm->source, 'newsletter');
    }

    public function doesNotRewriteTheCookieWhenNothingChanged(): void
    {
        $request = $this->request('/')->withCookieParams([
            'utm_history' => $this->codec->encode(UtmHistory::of($this->touchpoint('newsletter'))),
        ]);

        $response = $this->handle($this->middleware(), $request);

        Assert::same($response->getHeader('Set-Cookie'), []);
    }

    public function skipsATouchpointSimilarToTheStoredOne(): void
    {
        $request = $this->request('/?utm_source=google&utm_medium=cpc')->withCookieParams([
            'utm_history' => $this->codec->encode(UtmHistory::of($this->touchpoint('google', 'cpc'))),
        ]);

        $this->handle($this->middleware(), $request);

        Assert::same(UtmRequest::history($this->seenRequest())->count(), 1);
    }

    public function appendsASimilarTouchpointWhenUpdateExistingIsOn(): void
    {
        $request = $this->request('/?utm_source=google&utm_medium=cpc')->withCookieParams([
            'utm_history' => $this->codec->encode(UtmHistory::of($this->touchpoint('google', 'cpc'))),
        ]);

        $this->handle($this->middleware(updateExisting: true), $request);

        Assert::same(UtmRequest::history($this->seenRequest())->count(), 2);
    }

    public function capsStoredHistory(): void
    {
        $stored = UtmHistory::of(
            $this->touchpoint('a', 'cpc', '2026-06-01 10:00:00'),
            $this->touchpoint('b', 'cpc', '2026-06-02 10:00:00'),
            $this->touchpoint('c', 'cpc', '2026-06-03 10:00:00'),
        );

        $request = $this->request('/?utm_source=d&utm_medium=cpc')
            ->withCookieParams(['utm_history' => $this->codec->encode($stored)]);

        $this->handle($this->middleware(maxTouchpoints: 3), $request);

        Assert::same(UtmRequest::history($this->seenRequest())->count(), 3);
        Assert::same(UtmRequest::history($this->seenRequest())->latest()?->utm->source, 'd');
    }

    public function ignoresConfiguredPaths(): void
    {
        $response = $this->handle(
            $this->middleware(ignoredPaths: ['/health']),
            $this->request('/health?utm_source=google'),
        );

        Assert::null(UtmRequest::current($this->seenRequest()));
        Assert::same($response->getHeader('Set-Cookie'), []);
    }

    public function anEmptyIgnoredPatternMatchesNothing(): void
    {
        $this->handle($this->middleware(ignoredPaths: ['']), $this->request('/?utm_source=google'));

        Assert::same(UtmRequest::current($this->seenRequest())?->utm->source, 'google');
    }

    public function captureFallsThroughToTheNextSource(): void
    {
        $middleware = new UtmCaptureMiddleware(
            sources: [new StaticUtmSource(null), new QueryUtmSource($this->clock)],
            store: new NullUtmHistoryStore(),
            clock: $this->clock,
        );

        $this->handle($middleware, $this->request('/?utm_source=google'));

        Assert::same(UtmRequest::current($this->seenRequest())?->utm->source, 'google');
    }

    public function captureFallsThroughPastAnOrganicTouchpoint(): void
    {
        $organic = UtmTouchpoint::of(
            utm: new UtmParameters(),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
            referrer: Referrer::of('https://blog.example.org/x'),
        );
        $paid = $this->touchpoint('google', 'cpc', '2026-08-01 10:00:00');
        $middleware = new UtmCaptureMiddleware(
            sources: [new StaticUtmSource($organic), new StaticUtmSource($paid)],
            store: new NullUtmHistoryStore(),
            clock: $this->clock,
            captureOrganic: false,
        );

        $this->handle($middleware, $this->request('/'));

        Assert::same(UtmRequest::current($this->seenRequest())?->utm->source, 'google');
    }

    public function effectivePrefersCurrentOverHistoryWhenBothExist(): void
    {
        $stored = UtmHistory::of($this->touchpoint('google', 'cpc', '2026-07-01 10:00:00'));
        $current = $this->touchpoint('google', 'cpc', '2026-08-01 10:00:00');
        $middleware = new UtmCaptureMiddleware(
            sources: [new StaticUtmSource($current)],
            store: $this->store,
            clock: $this->clock,
            similarity: UtmSimilarity::Full,
        );

        $request = $this->request('/')->withCookieParams(['utm_history' => $this->codec->encode($stored)]);
        $this->handle($middleware, $request);

        Assert::same(UtmRequest::current($this->seenRequest())?->occurredAt->format('Y-m-d'), '2026-08-01');
        Assert::same(UtmRequest::effective($this->seenRequest())?->occurredAt->format('Y-m-d'), '2026-08-01');
    }

    public function doesNothingWhenDisabled(): void
    {
        $response = $this->handle($this->middleware(enabled: false), $this->request('/?utm_source=google'));

        Assert::null(UtmRequest::current($this->seenRequest()));
        Assert::same($response->getHeader('Set-Cookie'), []);
    }

    public function withoutConsentNothingIsReadOrWritten(): void
    {
        $request = $this->request('/?utm_source=google')->withCookieParams([
            'utm_history' => $this->codec->encode(UtmHistory::of($this->touchpoint('newsletter'))),
        ]);

        $response = $this->handle($this->middleware(consent: false), $request);

        Assert::null(UtmRequest::current($this->seenRequest()));
        Assert::true(UtmRequest::history($this->seenRequest())->isEmpty());
        Assert::same($response->getHeader('Set-Cookie'), []);
    }

    public function withoutConsentTheCookieIsExpiredWhenAsked(): void
    {
        $response = $this->handle(
            $this->middleware(consent: false, clearHistoryWithoutConsent: true),
            $this->request('/?utm_source=google'),
        );

        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('utm_history=');
        Assert::string($response->getHeaderLine('Set-Cookie'))->contains('Expires=');
    }

    public function organicVisitsAreIgnoredByDefault(): void
    {
        $request = $this->request('/')->withHeader('Referer', 'https://blog.example.org/post');

        $this->handle($this->middleware(), $request);

        Assert::null(UtmRequest::current($this->seenRequest()));
    }

    public function organicVisitsAreCapturedWhenEnabled(): void
    {
        $request = $this->request('/')->withHeader('Referer', 'https://blog.example.org/post');

        $this->handle($this->middleware(captureOrganic: true), $request);

        Assert::same(UtmRequest::current($this->seenRequest())?->referrer?->host, 'blog.example.org');
    }

    public function clampsAClaimedFutureMoment(): void
    {
        $middleware = new UtmCaptureMiddleware(
            sources: [new StaticUtmSource($this->touchpoint('google', 'cpc', '2030-01-01 00:00:00'))],
            store: new NullUtmHistoryStore(),
            clock: $this->clock,
        );

        $this->handle($middleware, $this->request('/'));

        Assert::same(
            UtmRequest::current($this->seenRequest())?->occurredAt->format('Y-m-d H:i:s'),
            '2026-08-01 12:00:00',
        );
    }

    public function clampsAClaimedAncientMoment(): void
    {
        $middleware = new UtmCaptureMiddleware(
            sources: [new StaticUtmSource($this->touchpoint('google', 'cpc', '2000-01-01 00:00:00'))],
            store: new NullUtmHistoryStore(),
            clock: $this->clock,
            maxTouchpointAge: 86400,
        );

        $this->handle($middleware, $this->request('/'));

        Assert::same(
            UtmRequest::current($this->seenRequest())?->occurredAt->format('Y-m-d H:i:s'),
            '2026-07-31 12:00:00',
        );
    }

    public function clampsAStoredTouchpointWithATamperedFutureMoment(): void
    {
        $stored = UtmHistory::of($this->touchpoint('google', 'cpc', '2030-01-01 00:00:00'));
        $request = $this->request('/')->withCookieParams(['utm_history' => $this->codec->encode($stored)]);

        $this->handle($this->middleware(), $request);

        Assert::same(
            UtmRequest::history($this->seenRequest())->latest()?->occurredAt->format('Y-m-d H:i:s'),
            '2026-08-01 12:00:00',
        );
    }

    public function clampsAStoredTouchpointWithATamperedAncientMoment(): void
    {
        $stored = UtmHistory::of($this->touchpoint('google', 'cpc', '2000-01-01 00:00:00'));
        $request = $this->request('/')->withCookieParams(['utm_history' => $this->codec->encode($stored)]);

        $this->handle($this->middleware(), $request);

        Assert::same(
            UtmRequest::history($this->seenRequest())->latest()?->occurredAt->format('Y-m-d H:i:s'),
            '2026-05-03 12:00:00',
        );
    }

    public function nullStoreKeepsTheResponseCacheable(): void
    {
        $middleware = new UtmCaptureMiddleware(
            sources: [new QueryUtmSource($this->clock)],
            store: new NullUtmHistoryStore(),
            clock: $this->clock,
        );

        $response = $this->handle($middleware, $this->request('/?utm_source=google'));

        Assert::same($response->getHeader('Set-Cookie'), []);
        Assert::same(UtmRequest::current($this->seenRequest())?->utm->source, 'google');
    }

    private function middleware(
        ?UtmHistoryStore $store = null,
        bool $enabled = true,
        bool $updateExisting = false,
        bool $captureOrganic = false,
        bool $consent = true,
        bool $clearHistoryWithoutConsent = false,
        int $maxTouchpoints = 5,
        array $ignoredPaths = [],
    ): UtmCaptureMiddleware {
        return new UtmCaptureMiddleware(
            sources: [new QueryUtmSource($this->clock)],
            store: $store ?? $this->store,
            clock: $this->clock,
            consentPolicy: new CallbackConsentPolicy(static fn(): bool => $consent),
            similarity: UtmSimilarity::Full,
            ignoredPaths: $ignoredPaths,
            maxTouchpoints: $maxTouchpoints,
            enabled: $enabled,
            updateExisting: $updateExisting,
            captureOrganic: $captureOrganic,
            clearHistoryWithoutConsent: $clearHistoryWithoutConsent,
        );
    }

    private function handle(UtmCaptureMiddleware $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class ($this->seen) implements RequestHandlerInterface {
            public function __construct(public ?ServerRequestInterface $captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response();
            }
        };

        $response = $middleware->process($request, $handler);
        $this->seen = $handler->captured;

        return $response;
    }

    private function seenRequest(): ServerRequestInterface
    {
        $seen = $this->seen;

        if (!$seen instanceof ServerRequestInterface) {
            throw new \RuntimeException('The handler was never reached');
        }

        return $seen;
    }

    private function request(string $target): ServerRequestInterface
    {
        $uri = 'https://shop.example.com' . $target;
        \parse_str((string) \parse_url($uri, PHP_URL_QUERY), $query);

        return (new ServerRequest('GET', $uri))->withQueryParams($query);
    }

    private function touchpoint(
        string $source,
        string $medium = 'cpc',
        string $time = '2026-07-01 10:00:00',
    ): UtmTouchpoint {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: $source, medium: $medium),
            occurredAt: new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
        );
    }
}
