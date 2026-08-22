---
name: rasuvaeff-yii3-utm
description: >-
  Capture UTM parameters, ad click ids (gclid/fbclid/yclid) and referrers with
  rasuvaeff/yii3-utm, and record marketing attribution — UtmCaptureMiddleware,
  UtmSource, UtmHistory, UtmAttributionService, UtmAttributionRepository. Use
  when writing, reviewing or debugging campaign tracking, first/last-touch
  attribution, "who brought this user", attribution rows that duplicate or go
  missing, or a `Duplicate key while building "di"` error in a project that has
  this package installed.
---

# rasuvaeff/yii3-utm

Captures the campaign a visitor arrived through, keeps a short touchpoint
history in one cookie, and writes an append-only attribution journal when a
business event happens. Namespace `Rasuvaeff\Yii3Utm\`.

Full API: `vendor/rasuvaeff/yii3-utm/llms.txt`.

## Rules — check these on every change

1. **The core binds no repository.** `UtmAttributionRepository` must be bound by
   exactly one source: `rasuvaeff/yii3-utm-db`, or the application in
   `config/common/di/*.php`. Binding it in two places is what produces
   `Duplicate key "…UtmAttributionRepository" while building "di"`. The core
   also subscribes no events — wiring `UtmAttributionEvent` to
   `UtmAttributionEventHandler` is the application's job.

2. **Everything the client sends is untrusted, including time.** Query,
   headers, body, cookies and `localStorage` are normalised, truncated or
   dropped — the cookie included: its referrer and landing page go through the
   same sanitizer a query string does, and `Referrer::of()` accepts `http` and
   `https` only. `occurredAt` is a claim: a future one is capped to now, one
   older than `maxTouchpointAge` drops the touchpoint. It authenticates
   nothing, and it never orders anything.

3. **First touch is server order, not a client flag.** `findFirst()` returns the
   row the server recorded first. There is no `is_first_interaction` column and
   no boolean to pass in — an application that sends one is telling you it has
   the wrong model.

4. **`eventId` is the idempotency key, and one event writes N rows.** Keep it
   stable across retries of the same business event and new for a new one.
   Deduplication is per touchpoint within an event, so redelivering a partially
   written event heals it instead of duplicating it.

5. **A bare click id is a valid touchpoint.** Google Ads auto-tagging sends
   `gclid` with no `utm_*` at all. Code that checks only `utm_source` drops the
   most expensive traffic there is.

## Capture

```php
// Web pipeline — one middleware, sources chosen by configuration.
UtmCaptureMiddleware::class,
```

```php
use Rasuvaeff\Yii3Utm\UtmRequest;

UtmRequest::current($request);    // ?UtmTouchpoint — this request
UtmRequest::history($request);    // UtmHistory — stored, may be empty
UtmRequest::effective($request);  // ?UtmTouchpoint — current ?? newest stored
```

The attributes are always present, so never test for their absence. Capture
writes a `Set-Cookie` header and makes the response uncacheable: use
`ignoredPaths` or `NullUtmHistoryStore` on cacheable routes.

| Transport | Source | When |
|---|---|---|
| Query string | `QueryUtmSource` | Server-rendered pages |
| `X-Utm-*` headers | `HeaderUtmSource` | SPA/API; `X-Utm-Click-Ids` is a JSON object |
| Nested `utm` body key | `BodyUtmSource` | SPA/API, recommended cross-domain |

Consent: `ConsentPolicy::allowsPersistence()`. Without consent nothing is read
or written. A project that already has `rasuvaeff/yii3-ab-testing-web` reuses
its policy through `CallbackConsentPolicy` — the method name is identical.

`config/params.php` exposes source query keys, click-id subsets, header prefix,
body key, sanitizer allow-list/limit and channel vocabularies.

## Attribution

```php
use Rasuvaeff\Yii3Utm\{InteractionType, UtmAttributionEvent};

$dispatcher->dispatch(new UtmAttributionEvent(
    entityId: (string) $user->getId(),
    eventId: $order->getUuid(),
    interactionType: InteractionType::purchase(),   // any /^[a-z][a-z0-9_]{0,31}\z/
    history: UtmRequest::history($request),
));
```

Reads go through `UtmAttributionRepository`: `findFirst()`, `findLast()`,
`findByEntity()`, `countByEntity()`. Retention and personal-data deletion are
part of the same contract — `purgeOlderThan()` and `deleteByEntity()`.

## Things that look like bugs but are not

| Symptom | Cause |
|---|---|
| Redelivering an event creates no rows | Correct: same `eventId` and same touchpoints |
| One event created three rows | Correct: three touchpoints in the history |
| The visit did not extend the history | A touchpoint similar to the newest stored one is skipped unless `updateExisting` is on |
| An organic visit produced no touchpoint | `captureOrganic` is off by default |
| `Channel` is not stored anywhere | By design — it is derived on read by `ChannelResolver` |
