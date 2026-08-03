<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Stores nothing.
 *
 * The right choice for stateless APIs where the client carries its own history
 * in the request body, and for routes that must stay cacheable — capture adds
 * a `Set-Cookie` header otherwise.
 *
 * @api
 */
final readonly class NullUtmHistoryStore implements UtmHistoryStore
{
    #[\Override]
    public function read(ServerRequestInterface $request): UtmHistory
    {
        return UtmHistory::empty();
    }

    #[\Override]
    public function write(ResponseInterface $response, UtmHistory $history): ResponseInterface
    {
        return $response;
    }

    #[\Override]
    public function forget(ResponseInterface $response): ResponseInterface
    {
        return $response;
    }
}
