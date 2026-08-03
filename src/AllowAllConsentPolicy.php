<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Default policy for applications where consent is not required or has already
 * been enforced before this middleware runs.
 *
 * @api
 */
final readonly class AllowAllConsentPolicy implements ConsentPolicy
{
    #[\Override]
    public function allowsPersistence(ServerRequestInterface $request): bool
    {
        return true;
    }
}
