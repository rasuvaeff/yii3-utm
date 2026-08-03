<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Cookies\Cookie;

/**
 * Keeps the whole history in **one** cookie.
 *
 * One cookie instead of one per touchpoint removes generated cookie names,
 * eviction of the oldest entries and a separate removal collection: the value
 * is simply rewritten, and the codec drops what does not fit.
 *
 * The default profile is a server cookie — `HttpOnly`, `Secure`,
 * `SameSite=Lax`. A client profile (`httpOnly: false`) is possible for
 * same-domain SPA reads, and is spoofable by definition.
 *
 * @api
 */
final readonly class CookieUtmHistoryStore implements UtmHistoryStore
{
    public const string DEFAULT_NAME = 'utm_history';
    public const int DEFAULT_TTL_DAYS = 30;

    /**
     * @param non-empty-string $name
     * @param int<1, max> $ttlDays
     */
    public function __construct(
        private UtmCookieCodec $codec,
        private ClockInterface $clock,
        private string $name = self::DEFAULT_NAME,
        private int $ttlDays = self::DEFAULT_TTL_DAYS,
        private bool $secure = true,
        private bool $httpOnly = true,
        private string $sameSite = Cookie::SAME_SITE_LAX,
        private string $path = '/',
        private ?string $domain = null,
    ) {}

    #[\Override]
    public function read(ServerRequestInterface $request): UtmHistory
    {
        /** @var mixed $value */
        $value = $request->getCookieParams()[$this->name] ?? null;

        return $this->codec->decode(\is_string($value) ? $value : null);
    }

    #[\Override]
    public function write(ResponseInterface $response, UtmHistory $history): ResponseInterface
    {
        return $this->cookie($this->codec->encode($history))
            ->withMaxAge(new \DateInterval('P' . $this->ttlDays . 'D'))
            ->addToResponse($response);
    }

    #[\Override]
    public function forget(ResponseInterface $response): ResponseInterface
    {
        return $this->cookie('')->expire()->addToResponse($response);
    }

    private function cookie(string $value): Cookie
    {
        return new Cookie(
            name: $this->name,
            value: $value,
            domain: $this->domain,
            path: $this->path,
            secure: $this->secure,
            httpOnly: $this->httpOnly,
            sameSite: $this->sameSite,
            clock: $this->clock,
        );
    }
}
