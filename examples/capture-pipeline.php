<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Utm\CallbackConsentPolicy;
use Rasuvaeff\Yii3Utm\CookieUtmHistoryStore;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\QueryUtmSource;
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmRequest;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;

require_once __DIR__ . '/../vendor/autoload.php';

$clock = new class implements ClockInterface {
    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceDays(int $days): void
    {
        $this->now = $this->now->modify(sprintf('+%d days', $days));
    }
};

$codec = new UtmCookieCodec();
$store = new CookieUtmHistoryStore(codec: $codec, clock: $clock);
$resolver = new DefaultChannelResolver();

// A handler that reports what the middleware handed downstream.
$handler = new class implements RequestHandlerInterface {
    public ?ServerRequestInterface $seen = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen = $request;

        return new Response();
    }
};

$middleware = new UtmCaptureMiddleware(
    sources: [new QueryUtmSource($clock)],
    store: $store,
    clock: $clock,
    consentPolicy: new CallbackConsentPolicy(
        // A real application asks its consent banner; here: a cookie.
        static fn(ServerRequestInterface $r): bool => ($r->getCookieParams()['consent'] ?? 'granted') === 'granted',
    ),
);

$request = static fn(string $target, array $cookies = []): ServerRequestInterface => (
    new ServerRequest('GET', 'https://shop.example.com' . $target)
)
    ->withQueryParams((static function (string $t): array {
        \parse_str((string) \parse_url($t, PHP_URL_QUERY), $q);

        return $q;
    })($target))
    ->withCookieParams($cookies);

// 1. An ad click: campaign in the query, nothing stored yet.
$response = $middleware->process($request('/summer?utm_source=google&utm_medium=cpc&gclid=abc'), $handler);
$cookie = $response->getHeaderLine('Set-Cookie');
$stored = \urldecode(\explode('=', \explode(';', $cookie)[0], 2)[1]);

printf("captured ....... %s / %s\n", (string) UtmRequest::current($handler->seen)?->utm->source, (string) UtmRequest::current($handler->seen)?->utm->medium);
printf("channel ........ %s\n", $resolver->resolve(UtmRequest::effective($handler->seen))->value);
printf("cookie flags ... %s\n", \implode(', ', \array_slice(\array_map('trim', \explode(';', $cookie)), 1)));

// 2. A week later the visitor comes back through a newsletter.
$clock->advanceDays(7);
$response = $middleware->process(
    $request('/?utm_source=newsletter&utm_medium=email', ['utm_history' => $stored]),
    $handler,
);
$stored = \urldecode(\explode('=', \explode(';', $response->getHeaderLine('Set-Cookie'))[0], 2)[1]);

printf("\nhistory ........ %d touchpoints\n", UtmRequest::history($handler->seen)->count());

foreach (UtmRequest::history($handler->seen) as $touchpoint) {
    printf("  %-10s %-6s %s\n", (string) $touchpoint->utm->source, (string) $touchpoint->utm->medium, $resolver->resolve($touchpoint)->value);
}

// 3. A plain page view: nothing new to capture, the cookie is left alone.
$clock->advanceDays(1);
$response = $middleware->process($request('/cart', ['utm_history' => $stored]), $handler);

printf(
    "\nplain view ..... current=%s, effective=%s, Set-Cookie=%d\n",
    UtmRequest::current($handler->seen) instanceof UtmTouchpoint ? 'yes' : 'none',
    (string) UtmRequest::effective($handler->seen)?->utm->source,
    \count($response->getHeader('Set-Cookie')),
);

// 4. Without consent nothing is read and nothing is written.
$response = $middleware->process(
    $request('/?utm_source=google', ['utm_history' => $stored, 'consent' => 'denied']),
    $handler,
);

printf(
    "\nno consent ..... history=%d, Set-Cookie=%d\n",
    UtmRequest::history($handler->seen)->count(),
    \count($response->getHeader('Set-Cookie')),
);
