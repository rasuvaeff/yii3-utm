# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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

### Changed

- `TouchpointTime::clamp()` (internal) is replaced by
  `TouchpointTime::withinWindow()`, which returns `null` for a stale claim.

## 1.0.0 — 2026-08-03

- Initial release.
