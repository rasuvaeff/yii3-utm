# AGENTS.md — yii3-utm

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-utm` captures UTM parameters, advertising click identifiers and
referrers from an HTTP request, keeps a short touchpoint history, and turns a
business event into rows of an append-only attribution journal. Namespace
`Rasuvaeff\Yii3Utm`. The package is built from the plan in
`../yii3-package-plans/yii3-utm-plan.md`; read it before extending the API.

The core does **not** provide persistence: `UtmAttributionRepository` is
implemented by `rasuvaeff/yii3-utm-db` or by the application.

Build state: domain, attribution and capture layers are implemented. Portable
persistence is available in the sibling `rasuvaeff/yii3-utm-db` package.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
   If psalm demands a `@psalm-pure` cascade for a `@psalm-immutable` class,
   drop the annotation (see `UtmHistory`) — never silence it.
3. **Nothing from the client is trusted, and nothing derived is passed in.**
   Client values are normalised, truncated or dropped; `occurredAt` is a claim,
   not proof. Fingerprints and dedupe keys are computed from the touchpoint —
   never accepted as constructor arguments — because a mismatched fingerprint
   silently defeats the unique index of the journal.
4. **Preserve the public contract.** Update README + README.ru + tests with any
   API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **Determinism is a storage requirement, not a style preference.** `ClickIds`
  keeps whitelist order and caps its serialised length at the storage column
  width; `UtmHistory::deduplicated()` keeps the **oldest** member of a
  similarity group; `UtmHistory` sorting breaks ties by canonical signature.
  Any input-order dependence in these three places produces duplicate rows
  instead of a no-op on redelivery.
- `UtmFingerprint` uses the referrer **host**, never the full URL: a URL with a
  query string differs on every visit and would defeat deduplication.
- `InteractionType` is a validated string, not an enum — its pattern matches
  the width of the storage column.
- `Referrer` accepts an already sanitised URL; sanitising is the capture
  layer's job, so the value object stays free of service dependencies.
- Psalm annotations are written up front: `non-empty-string`, `list<T>`,
  `array{...}` shapes, `@psalm-type`. See rule 11 of the root `AGENTS.md`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments.
- **PHP 8.3 is supported**: `new X()->method()` without parentheses is 8.4+
  syntax and must not appear, even though the local image runs 8.5.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags. Updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.
- `ext-mbstring` must stay in `extensions:` of **all** CI jobs, including
  static analysis: the code and the property tests both need it, and the local
  image hides its absence.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
