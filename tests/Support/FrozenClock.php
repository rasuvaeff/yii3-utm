<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * Clock that stands still until a test moves it.
 *
 * `yiisoft/test-support` ships `StaticClock`, but it is final and cannot be
 * advanced — and ordering tests need the server clock to move between writes.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $now,
    ) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d seconds', $seconds));
    }
}
