<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides whether this request may create or read a persistent marketing
 * identity.
 *
 * The contract mirrors `ConsentPolicyInterface` of `rasuvaeff/yii3-ab-testing-web`
 * down to the method name, so an application that already has a policy adapts
 * it in one line through {@see CallbackConsentPolicy}. The type is duplicated
 * rather than shared: an A/B testing web package has no business being a
 * runtime dependency of UTM capture.
 *
 * @api
 */
interface ConsentPolicy
{
    public function allowsPersistence(ServerRequestInterface $request): bool;
}
