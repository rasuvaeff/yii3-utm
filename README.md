# rasuvaeff/yii3-utm

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-utm/v)](https://packagist.org/packages/rasuvaeff/yii3-utm)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-utm/downloads)](https://packagist.org/packages/rasuvaeff/yii3-utm)
[![Build](https://github.com/rasuvaeff/yii3-utm/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-utm/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-utm/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-utm)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Marketing attribution for Yii3 applications: capture UTM parameters, click
identifiers and referrers, keep a short touchpoint history, and record an
append-only attribution journal for registrations, purchases and any other
business event.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact API reference
> written for LLMs. The package also ships an agent skill through
> [llm/skills](https://github.com/roxblnfk/skills).

**Status: work in progress.** The domain layer described below is implemented
and covered; the capture middleware, the attribution service and the
`rasuvaeff/yii3-utm-db` storage adapter are being added next.

## Requirements

- PHP 8.3 – 8.5
- `ext-json`, `ext-mbstring`

## Installation

```bash
composer require rasuvaeff/yii3-utm
```

## Why a journal and not a column

Between an ad click and a purchase there are days and several visits. A single
"current UTM" column answers the wrong question. This package keeps the last
touchpoints and, when a business event happens, writes one row per touchpoint,
so first-touch, last-touch and multi-touch models are all answerable later.

Three invariants shape the whole API:

1. Everything the client sends — query, headers, body, cookies, `localStorage`
   — is untrusted. Values are normalised, truncated or dropped, never
   authenticated.
2. The journal is append-only and ordered by the **server**. A client cannot
   make a late delivery become the first touch.
3. Deduplication happens per touchpoint within one business event, so a retry
   of the same event writes nothing new while a genuinely new event does.

## Usage

### Campaign parameters

`UtmParameters` is the campaign tuple: the five standard `utm_*` fields plus
GA4 `utm_id`. Factories normalise untrusted input — control characters are
stripped, values trimmed and truncated to 255 characters, empty strings become
`null`.

```php
use Rasuvaeff\Yii3Utm\UtmParameters;

$utm = UtmParameters::fromArray($request->getQueryParams());

$utm->source;   // 'google'
$utm->content;  // 'banner-a' — mapped to content, not campaign
$utm->isEmpty(); // false
$utm->toArray(); // stable snake_case keys, round-trip safe
```

### Click identifiers

Auto-tagging platforms attach a click id and no `utm_*` at all — Google Ads
sends a bare `gclid`. `ClickIds` accepts only whitelisted keys, in whitelist
order, and caps its serialised length at the storage column width.

```php
use Rasuvaeff\Yii3Utm\ClickIds;

$ids = ClickIds::fromArray($request->getQueryParams());

$ids->get('gclid');  // 'EAIaIQobChMI...'
$ids->isEmpty();     // false
$ids->toJson();      // {"gclid":"..."} — deterministic key order
```

Supported keys: `gclid`, `gbraid`, `wbraid`, `fbclid`, `yclid`, `ttclid`,
`msclkid`, `li_fat_id`, `twclid`.

### Touchpoints and history

A `UtmTouchpoint` is one contact: campaign tuple, click ids, referrer, landing
page and the timestamp the source claims. `UtmHistory` keeps them newest first.

```php
use Rasuvaeff\Yii3Utm\{Referrer, UtmHistory, UtmSimilarity, UtmTouchpoint};

$touchpoint = UtmTouchpoint::of(
    utm: $utm,
    occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
    clickIds: $ids,
    referrer: Referrer::of('https://ads.example.com/'),
    landingPage: 'https://shop.example.com/summer',
);

$history = UtmHistory::of($touchpoint)
    ->deduplicated(UtmSimilarity::Campaign)  // keeps the oldest of each group
    ->limited(5);                            // keeps the newest five

$history->latest();
$history->oldest();
```

| Method | Behaviour |
|---|---|
| `UtmHistory::of(...$touchpoints)` | Sorts newest first; ties broken deterministically |
| `with(UtmTouchpoint)` | Returns a new history with the touchpoint added |
| `deduplicated(UtmSimilarity)` | Collapses similar touchpoints, keeping the **oldest** of each group |
| `limited(int)` | Keeps at most N newest touchpoints |
| `latest()` / `oldest()` / `all()` / `count()` / `isEmpty()` | Read accessors |

`UtmSimilarity` decides what "similar" means: `Full` (campaign tuple and click
ids), `Campaign` (source, medium, campaign) or `SourceMedium`.

### Interaction types

Which business events exist is the application's decision, so the type is a
validated string, not an enum:

```php
use Rasuvaeff\Yii3Utm\InteractionType;

InteractionType::registration();
InteractionType::purchase();
InteractionType::of('trial_started');   // /^[a-z][a-z0-9_]{0,31}\z/
```

### Channel classification

`Channel` is derived on read and deliberately not stored — classification rules
change more often than a major release allows.

```php
use Rasuvaeff\Yii3Utm\{Channel, DefaultChannelResolver};

$channel = (new DefaultChannelResolver())->resolve($touchpoint);
// Channel::Paid — a click id outranks everything else
```

Rule order: click id → `utm_medium` → referrer host. Vocabularies (paid, email
and social mediums, social and search hosts) are constructor arguments.

## Security

| Aspect | Behaviour |
|---|---|
| Client input | Untrusted: normalised, truncated, invalid values become `null` |
| `occurredAt` | A claim by the source, never proof of when a visit happened |
| Ordering | Server-assigned; a late delivery cannot become the first touch |
| Deduplication | Fingerprint and dedupe key are derived, never accepted from callers |
| Referrer | Only its host takes part in the fingerprint; sanitising URLs is the capture layer's job |
| Landing page | Truncated to 500 characters; query sanitisation is applied before storage |

## Examples

Runnable scripts live in [examples/](examples/).

## Development

```bash
make build          # full gate: validate, normalize, require-checker, cs, psalm, test
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

Without Make, run the same targets through Docker — see [AGENTS.md](AGENTS.md).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
