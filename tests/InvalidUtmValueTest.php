<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Rasuvaeff\Yii3Utm\Exception\InvalidUtmValue;
use Rasuvaeff\Yii3Utm\Exception\UtmException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(InvalidUtmValue::class)]
final class InvalidUtmValueTest
{
    public function describesTheInvalidIdentifier(): void
    {
        $exception = InvalidUtmValue::identifier('event id', 'must not be empty');

        Assert::instanceOf($exception, UtmException::class);
        Assert::same($exception->getMessage(), 'Invalid event id: must not be empty');
    }
}
