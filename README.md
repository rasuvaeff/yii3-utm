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

**Status: feature-complete.** Use `rasuvaeff/yii3-utm-db` for portable
`yiisoft/db` persistence, provide an application repository, or use the
shipped in-memory implementation in tests.

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

### Capture

One middleware; the transports it understands are configuration, not separate
classes.

```php
use Rasuvaeff\Yii3Utm\UtmCaptureMiddleware;

// web pipeline
UtmCaptureMiddleware::class,
```

```php
use Rasuvaeff\Yii3Utm\UtmRequest;

UtmRequest::current($request);    // ?UtmTouchpoint — carried by this request
UtmRequest::history($request);    // UtmHistory — stored, may be empty
UtmRequest::effective($request);  // ?UtmTouchpoint — current ?? newest stored
```

The attributes are always set, so downstream code never distinguishes "the
middleware did not run" from "nothing was captured".

| Transport | Source | Use for |
|---|---|---|
| Query string | `QueryUtmSource` | Server-rendered pages; landing page and `Referer` are captured too |
| `X-Utm-*` headers | `HeaderUtmSource` | SPA and API clients; click ids use JSON in `X-Utm-Click-Ids` |
| Nested `utm` body key | `BodyUtmSource` | SPA and API; the recommended cross-domain transport |

All three sources drop a referrer that matches the current request's own host
(`Referrer::external()`, not `Referrer::of()`): navigating from one page of
your site to another is not a touchpoint to attribute the visit to.

History lives in a **single** cookie (`utm_history` by default) encoded by
`UtmCookieCodec`: `HttpOnly`, `Secure`, `SameSite=Lax`, 30 days. A client
profile (`httpOnly: false`) exists for same-domain SPA reads and is spoofable by
definition. `DefaultLandingPageSanitizer` — the shipped implementation — keeps scheme, host,
port and path, drops the fragment and every query parameter outside its
allow-list (`utm_*` and click ids by default), and truncates to 500 characters.

The cookie is treated as untrusted input on the way **in** as well: the codec
runs the referrer and the landing page of a decoded entry through the same
sanitizer, so a hand-edited cookie cannot inject a `javascript:` URL or an
unsanitised landing page into the history. `Referrer::of()` accepts `http` and
`https` only. Pass your own sanitizer to `UtmCookieCodec` when you configure a
custom allow-list — the shipped `config/di.php` already does.

`UtmCookieCodec::$maxLength` (3500 by default) is the size of the
**percent-encoded** value, which is what `Set-Cookie` carries: the codec drops
the oldest touchpoints until the encoded value fits, leaving room for the cookie
name and its attributes inside the 4096-byte browser limit.

`NullUtmHistoryStore` stores nothing — the right choice for
stateless APIs and cacheable routes, since capture otherwise adds a
`Set-Cookie` header and makes a response uncacheable.

| Option | Default | Effect |
|---|---|---|
| `enabled` | `true` | Master switch |
| `ignoredPaths` | `[]` | Path prefixes to skip |
| `similarity` | `Full` | What counts as "the same campaign" |
| `updateExisting` | `false` | Whether a touchpoint similar to the newest stored one is appended |
| `captureOrganic` | `false` | Whether a visit with neither campaign nor click id becomes a touchpoint |
| `maxTouchpoints` | `5` | History cap |
| `maxTouchpointAge` | 90 days | Retention window for a claimed `occurredAt`: a future claim is capped to now, an older one produces no touchpoint and drops a stored one |
| `clearHistoryWithoutConsent` | `false` | Whether a stored history is expired when consent is absent |

### Consent

`ConsentPolicy::allowsPersistence()` gates the whole thing: without consent
nothing is read and nothing is written. The default is `AllowAllConsentPolicy`
— for applications where consent is enforced earlier in the stack.

```php
use Rasuvaeff\Yii3Utm\CallbackConsentPolicy;

new CallbackConsentPolicy(
    static fn (ServerRequestInterface $r): bool => $consentBanner->accepted($r),
);
```

The method name matches `rasuvaeff/yii3-ab-testing-web`, so an application that
already has a policy reuses it in one line.

### Configuration

The package ships `config/di.php` and `config/params.php` for
`yiisoft/config`. It binds the capture stack, the codec, the sanitizer, the
channel resolver and the consent default — and deliberately **not**
`UtmAttributionRepository`, which must come from exactly one source.

The `rasuvaeff/yii3-utm` params group exposes:

- `capture.sources.query.utmKeys` and `clickIdKeys`;
- `capture.sources.header.prefix` and `clickIdKeys`;
- `capture.sources.body.key` and `clickIdKeys`;
- `sanitizer.allowedQueryKeys` and `maxLength`;
- `channel.paidMediums`, `emailMediums`, `socialMediums`, `socialHosts` and
  `searchHosts`.

### Attribution

A business event becomes one row per touchpoint. `UtmAttribution` derives its
own `fingerprint` and `dedupeKey` — they are never constructor arguments,
because a mismatched fingerprint would silently defeat the unique index of the
journal.

```php
use Rasuvaeff\Yii3Utm\{InteractionType, UtmAttributionEvent, UtmAttributionService};

$service = new UtmAttributionService($repository);   // repository comes from -db or the app

$service->record(new UtmAttributionEvent(
    entityId: (string) $user->getId(),
    eventId: $order->getUuid(),           // stable across retries, new for a new event
    interactionType: InteractionType::purchase(),
    history: $history,
));   // returns the number of rows actually created
```

| Guarantee | Detail |
|---|---|
| Retry of the same event | Writes nothing: deduplication is keyed by event id **and** touchpoint |
| A genuinely new event | Writes rows even for an identical campaign |
| Partial write | Self-healing — redelivery adds what is missing and duplicates nothing, which is why no transaction wraps the batch |
| Order | Oldest touchpoint first; server assigns the canonical order at write time |
| Empty touchpoints | Skipped — a row attributing nothing is noise |

`UtmAttributionEventHandler` is a ready listener (`__invoke`), but the package
does not subscribe it: wiring is the application's decision.

### Storage

`UtmAttributionRepository` is the storage contract — `append()`,
`findByEntity()`, `findFirst()`, `findLast()`, `countByEntity()`,
`deleteByEntity()`, `purgeOlderThan()` and `countOlderThan()` (what
`purgeOlderThan()` would remove, without removing it — for a dry run). The
core **does not bind it**: an
implementation comes from `rasuvaeff/yii3-utm-db` or from the application.
`InMemoryUtmAttributionRepository` is shipped for tests and returns
`InMemoryUtmAttributionRecord` instances; it is never bound.


Implementations must make `append()` race-safe — an upsert that does nothing on
conflict, or an insert whose duplicate-key error is handled. "Check, then
insert" is not enough.

## Security

| Aspect | Behaviour |
|---|---|
| Client input | Untrusted: normalised, truncated, invalid values become `null`. Values stay arbitrary text — escaping on output is the consumer's job |
| `occurredAt` | A claim by the source, never proof of when a visit happened |
| Ordering | Server-assigned; a late delivery cannot become the first touch |
| Deduplication | Fingerprint and dedupe key are derived, never accepted from callers |
| Referrer | `http`/`https` only; only its host takes part in the fingerprint |
| Landing page | Truncated to 500 characters; query sanitisation is applied before storage |
| Cookie | Sanitised on decoding exactly like a query string, and its size is budgeted after percent-encoding |

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
