<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where touchpoint history lives between requests.
 *
 * @api
 */
interface UtmHistoryStore
{
    public function read(ServerRequestInterface $request): UtmHistory;

    public function write(ResponseInterface $response, UtmHistory $history): ResponseInterface;

    /**
     * Instructs the client to drop whatever is stored — used when consent is
     * withdrawn.
     */
    public function forget(ResponseInterface $response): ResponseInterface;
}
