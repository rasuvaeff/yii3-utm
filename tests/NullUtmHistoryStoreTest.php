<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3Utm\NullUtmHistoryStore;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(NullUtmHistoryStore::class)]
final class NullUtmHistoryStoreTest
{
    public function readsNothingAndWritesNothing(): void
    {
        $store = new NullUtmHistoryStore();
        $response = new Response();
        $history = UtmHistory::of(UtmTouchpoint::of(
            utm: new UtmParameters(source: 'google'),
            occurredAt: new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC')),
        ));

        Assert::true($store->read(new ServerRequest('GET', '/'))->isEmpty());
        Assert::same($store->write($response, $history), $response);
        Assert::same($store->forget($response), $response);
    }
}
