# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 — 2026-08-22

Nothing in this release changes a signature, so a BC check reports it clean —
but three behaviours visibly differ from 1.0.0 and are called out here rather
than discovered in production:

- a cookie carrying a referrer with a scheme other than `http`/`https` no
  longer yields a `Referrer` — that entry loses its referrer;
- an `ignoredPaths` entry such as `/api` no longer silences `/api-docs`, so
  routes that used to be skipped by accident are captured again;
- the cookie budget is measured after percent-encoding, so a history of
  non-ASCII campaigns evicts one touchpoint sooner than it used to;
- a landing page longer than `maxLength` is now cut on a boundary that keeps
  it a URL, so a stored value can be a few characters shorter than the one
  1.0.0 produced for the same input.

### Security

- `Referrer::of()` now accepts `http` and `https` URLs only. `javascript:`,
  `data:`, `file:` and protocol-relative values used to pass, because the
  factory only asked `parse_url()` for a host — `javascript://evil.example.com/x`
  has one. Behaviour change: a stored row or a cookie carrying such a URL no
  longer yields a `Referrer`.
- `UtmCookieCodec::decode()` runs the referrer and the landing page of an entry
  through a `LandingPageSanitizer` (new optional constructor argument, wired
  from the container in `config/di.php`). The cookie path used to skip the
  sanitisation every capture source applies, so a hand-edited cookie could put
  a dangerous URL or an unsanitised landing page into the history and, through
  `UtmAttributionService`, into storage.

### Fixed

- `UtmCookieCodec::encode()` now measures its budget on the percent-encoded
  value. `Yiisoft\Cookies\Cookie` percent-encodes the cookie value, so a
  history of Cyrillic campaigns that satisfied the raw-length check produced a
  `Set-Cookie` well past the 4096-byte browser limit and was dropped silently.
  `maxLength` is now documented as the encoded length; histories evict one
  touchpoint sooner than before.
- A touchpoint whose claimed moment is older than `maxTouchpointAge` is now
  dropped instead of being moved to the window boundary, and the cookie is
  rewritten when that happens. Clamping made `occurredAt` slide to a new
  fabricated value every day and kept the touchpoint alive forever.

- `UtmCaptureMiddleware` now matches `ignoredPaths` on a segment boundary. A
  bare prefix comparison made `/api` silence `/api-docs` and `/health` silence
  `/healthz-external`, so capture stopped on routes nobody excluded. An entry
  matches the path itself and anything below it.
- `DefaultLandingPageSanitizer` truncates on a boundary it can read back: a
  whole query pair, never inside a percent-triplet, never leaving a dangling
  `?`. `mb_substr()` over the joined value could leave `%D` (re-encoded to
  `%25D` on the next pass) or a partial `gcl` (dropped as an unknown key on the
  next pass), so `sanitize()` was not idempotent on its own output.

### Changed

- `TouchpointTime::clamp()` (internal) is replaced by
  `TouchpointTime::withinWindow()`, which returns `null` for a stale claim.

### Documentation

- The Security section of both READMEs now states that normalisation is not
  escaping: a UTM value stays arbitrary text and escaping at the point of
  output is the consumer's job.
- Both READMEs document that the cookie is written only when the history
  changes, so `ttlDays` counts from the last touchpoint, not from the last
  visit. A time-based refresh is deliberately not implemented: it would need a
  "written at" stamp in the cookie payload and would add `Set-Cookie` to
  responses that are cacheable today.

## 1.0.0 — 2026-08-03

- Initial release.
