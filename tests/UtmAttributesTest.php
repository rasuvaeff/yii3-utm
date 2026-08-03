<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\UtmAttributes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmAttributes::class)]
final class UtmAttributesTest
{
    public function exposesStableAttributeNames(): void
    {
        Assert::same(UtmAttributes::CURRENT, 'utm_current');
        Assert::same(UtmAttributes::HISTORY, 'utm_history');
        Assert::same(UtmAttributes::EFFECTIVE, 'utm');
    }
}
