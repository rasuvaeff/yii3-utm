<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Utm\CookieUtmHistoryStore;
use Rasuvaeff\Yii3Utm\Tests\Support\FrozenClock;
use Rasuvaeff\Yii3Utm\UtmCookieCodec;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(CookieUtmHistoryStore::class)]
final class CookieUtmHistoryStoreTest
{
    private UtmCookieCodec $codec;
    private FrozenClock $clock;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->codec = new UtmCookieCodec();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC')));
    }

    public function readsTheHistoryBackFromItsOwnCookie(): void
    {
        $store = $this->store();
        $history = UtmHistory::of($this->touchpoint('google'));

        $response = $store->write(new Response(), $history);
        $value = $this->cookieValue($response->getHeaderLine('Set-Cookie'));

        $read = $store->read((new ServerRequest('GET', '/'))->withCookieParams(['utm_history' => $value]));

        Assert::same($read->latest()?->utm->source, 'google');
    }

    public function readsAnEmptyHistoryWithoutACookie(): void
    {
        Assert::true($this->store()->read(new ServerRequest('GET', '/'))->isEmpty());
    }

    public function ignoresANonStringCookie(): void
    {
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['utm_history' => ['array']]);

        Assert::true($this->store()->read($request)->isEmpty());
    }

    public function serverProfileIsHttpOnlySecureAndLax(): void
    {
        $header = $this->store()->write(new Response(), UtmHistory::of($this->touchpoint('google')))
            ->getHeaderLine('Set-Cookie');

        Assert::string($header)->contains('HttpOnly');
        Assert::string($header)->contains('Secure');
        Assert::string($header)->contains('SameSite=Lax');
        Assert::string($header)->contains('Path=/');
        Assert::string($header)->contains('Max-Age=');
    }

    public function clientProfileDropsHttpOnly(): void
    {
        $store = new CookieUtmHistoryStore(
            codec: $this->codec,
            clock: $this->clock,
            httpOnly: false,
        );

        $header = $store->write(new Response(), UtmHistory::of($this->touchpoint('google')))->getHeaderLine('Set-Cookie');

        Assert::true(!\str_contains($header, 'HttpOnly'));
    }

    public function honoursNameTtlPathAndDomain(): void
    {
        $store = new CookieUtmHistoryStore(
            codec: $this->codec,
            clock: $this->clock,
            name: 'attribution',
            ttlDays: 7,
            path: '/shop',
            domain: 'example.com',
        );

        $header = $store->write(new Response(), UtmHistory::of($this->touchpoint('google')))->getHeaderLine('Set-Cookie');

        Assert::string($header)->contains('attribution=');
        Assert::string($header)->contains('Max-Age=604800');
        Assert::string($header)->contains('Path=/shop');
        Assert::string($header)->contains('Domain=example.com');
    }

    public function forgetExpiresTheCookie(): void
    {
        $header = $this->store()->forget(new Response())->getHeaderLine('Set-Cookie');

        Assert::string($header)->contains('utm_history=');
        Assert::string($header)->contains('Expires=');
    }

    private function store(): CookieUtmHistoryStore
    {
        return new CookieUtmHistoryStore(codec: $this->codec, clock: $this->clock);
    }

    private function cookieValue(string $header): string
    {
        $pair = \explode(';', $header)[0];

        return \urldecode(\explode('=', $pair, 2)[1] ?? '');
    }

    private function touchpoint(string $source): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: $source),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
        );
    }
}
