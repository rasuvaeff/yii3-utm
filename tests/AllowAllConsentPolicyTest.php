<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Utm\AllowAllConsentPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AllowAllConsentPolicy::class)]
final class AllowAllConsentPolicyTest
{
    public function alwaysGrants(): void
    {
        Assert::true((new AllowAllConsentPolicy())->allowsPersistence(new ServerRequest('GET', '/')));
    }
}
