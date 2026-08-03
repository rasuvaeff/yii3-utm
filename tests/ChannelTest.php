<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\Channel;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Channel::class)]
final class ChannelTest
{
    public function exposesStableValues(): void
    {
        Assert::same(
            \array_map(static fn(Channel $channel): string => $channel->value, Channel::cases()),
            ['paid', 'organic', 'social', 'email', 'referral', 'direct', 'other'],
        );
    }
}
