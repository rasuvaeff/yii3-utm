<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Utm\ClickIds;
use Rasuvaeff\Yii3Utm\DefaultChannelResolver;
use Rasuvaeff\Yii3Utm\Referrer;
use Rasuvaeff\Yii3Utm\UtmHistory;
use Rasuvaeff\Yii3Utm\UtmParameters;
use Rasuvaeff\Yii3Utm\UtmSimilarity;
use Rasuvaeff\Yii3Utm\UtmTouchpoint;

require_once __DIR__ . '/../vendor/autoload.php';

$utc = new DateTimeZone('UTC');

// A query string as it arrives from an ad click. Values are untrusted: the
// control character and the overlong term are cleaned up by the factory.
$query = [
    'utm_source' => 'google',
    'utm_medium' => 'cpc',
    'utm_campaign' => "summer-sale\x00",
    'utm_term' => str_repeat('shoes ', 100),
    'utm_content' => 'banner-a',
    'gclid' => 'EAIaIQobChMI-example',
    'evilclid' => '<script>',
];

$first = UtmTouchpoint::of(
    utm: UtmParameters::fromArray($query),
    occurredAt: new DateTimeImmutable('2026-07-01 09:00:00', $utc),
    clickIds: ClickIds::fromArray($query),
    referrer: Referrer::of('https://www.google.com/search'),
    landingPage: 'https://shop.example.com/summer',
);

printf("campaign ....... %s\n", (string) $first->utm->campaign);
printf("term length .... %d\n", mb_strlen((string) $first->utm->term));
printf("click ids ...... %s\n", $first->clickIds->toJson());
printf("referrer host .. %s\n", (string) $first->referrer?->host);

// The same visitor returns a week later through the same campaign, then once
// more from a different source.
$second = UtmTouchpoint::of(
    utm: UtmParameters::fromArray(['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'summer-sale']),
    occurredAt: new DateTimeImmutable('2026-07-08 12:30:00', $utc),
);

$third = UtmTouchpoint::of(
    utm: UtmParameters::fromArray(['utm_source' => 'newsletter', 'utm_medium' => 'email']),
    occurredAt: new DateTimeImmutable('2026-07-20 08:15:00', $utc),
);

$history = UtmHistory::of($second, $first, $third);

printf("\nhistory (newest first): %d touchpoints\n", $history->count());

foreach ($history as $touchpoint) {
    printf(
        "  %s  %-10s %-6s %s\n",
        $touchpoint->occurredAt->format('Y-m-d'),
        (string) $touchpoint->utm->source,
        (string) $touchpoint->utm->medium,
        (new DefaultChannelResolver())->resolve($touchpoint)->value,
    );
}

// Collapsing by campaign keeps the OLDEST member of each group: the first
// contact with a source is the one worth attributing.
$collapsed = $history->deduplicated(UtmSimilarity::Campaign);

printf("\nafter deduplication: %d touchpoints\n", $collapsed->count());
printf("first touch .... %s (%s)\n", (string) $collapsed->oldest()?->utm->source, (string) $collapsed->oldest()?->occurredAt->format('Y-m-d'));
printf("last touch ..... %s (%s)\n", (string) $collapsed->latest()?->utm->source, (string) $collapsed->latest()?->occurredAt->format('Y-m-d'));
