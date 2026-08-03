<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Utm\UtmSource;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;

/**
 * Source that always yields the same touchpoint — lets a test drive the
 * middleware with a claimed timestamp no real transport would produce.
 */
final readonly class StaticUtmSource implements UtmSource
{
    public function __construct(
        private ?UtmTouchpoint $touchpoint,
    ) {}

    #[\Override]
    public function extract(ServerRequestInterface $request): ?UtmTouchpoint
    {
        return $this->touchpoint;
    }
}
