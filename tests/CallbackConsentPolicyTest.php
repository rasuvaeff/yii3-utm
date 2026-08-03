<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Utm\CallbackConsentPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CallbackConsentPolicy::class)]
final class CallbackConsentPolicyTest
{
    public function delegatesTheDecision(): void
    {
        $policy = new CallbackConsentPolicy(
            static fn(ServerRequestInterface $request): bool
                => ($request->getCookieParams()['consent'] ?? null) === 'granted',
        );

        $granted = (new ServerRequest('GET', '/'))->withCookieParams(['consent' => 'granted']);

        Assert::true($policy->allowsPersistence($granted));
        Assert::false($policy->allowsPersistence(new ServerRequest('GET', '/')));
    }

    public function receivesTheRequest(): void
    {
        $seen = null;
        $policy = new CallbackConsentPolicy(static function (ServerRequestInterface $request) use (&$seen): bool {
            $seen = $request->getUri()->getPath();

            return true;
        });

        $policy->allowsPersistence(new ServerRequest('GET', 'https://shop.example.com/checkout'));

        Assert::same($seen, '/checkout');
    }
}
