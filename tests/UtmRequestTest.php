<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Utm\UtmAttributes;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmRequest;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(UtmRequest::class)]
final class UtmRequestTest
{
    public function returnsTypedAttributes(): void
    {
        $touchpoint = $this->touchpoint();
        $history = UtmHistory::of($touchpoint);
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute(UtmAttributes::CURRENT, $touchpoint)
            ->withAttribute(UtmAttributes::HISTORY, $history)
            ->withAttribute(UtmAttributes::EFFECTIVE, $touchpoint);

        Assert::same(UtmRequest::current($request), $touchpoint);
        Assert::same(UtmRequest::history($request), $history);
        Assert::same(UtmRequest::effective($request), $touchpoint);
    }

    public function rejectsAttributesOfTheWrongType(): void
    {
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute(UtmAttributes::CURRENT, 'invalid')
            ->withAttribute(UtmAttributes::HISTORY, 'invalid')
            ->withAttribute(UtmAttributes::EFFECTIVE, 'invalid');

        Assert::null(UtmRequest::current($request));
        Assert::true(UtmRequest::history($request)->isEmpty());
        Assert::null(UtmRequest::effective($request));
    }

    private function touchpoint(): UtmTouchpoint
    {
        return UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
        );
    }
}
