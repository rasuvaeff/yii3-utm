<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * Append-only journal of attribution rows.
 *
 * The core does **not** bind this interface: an implementation comes from
 * `rasuvaeff/yii3-utm-db` or from the application. Binding it in two places at
 * once is what makes `yiisoft/config` report a duplicate key.
 *
 * Ordering is server-assigned — `recordedAt` then `id` — so a late delivery can
 * never become the first touch of an entity, whatever timestamp the client
 * claims.
 *
 * @api
 */
interface UtmAttributionRepository
{
    /**
     * Upper bound implementations must clamp `$limit` to in
     * {@see self::findByEntity()} — an application that forwards an
     * HTTP-supplied page size unchecked must not be able to force an
     * unbounded read.
     */
    public const int MAX_LIMIT = 1000;

    /**
     * Stores the attribution unless an identical one is already present.
     *
     * Implementations must be race-safe: an upsert that does nothing on
     * conflict, or an insert whose duplicate-key error is handled. A separate
     * "check, then insert" is not sufficient.
     *
     * @return bool `true` when a row was created, `false` when the dedupe key already existed
     */
    public function append(UtmAttribution $attribution): bool;

    /**
     * @param non-empty-string $entityId
     * @param int<1, max> $limit clamped to {@see self::MAX_LIMIT} by the implementation
     * @param int<0, max> $offset
     *
     * @return list<UtmAttributionRecord> in canonical order, oldest first
     */
    public function findByEntity(
        string $entityId,
        ?InteractionType $interactionType = null,
        int $limit = 100,
        int $offset = 0,
    ): array;

    /**
     * @param non-empty-string $entityId
     */
    public function findFirst(string $entityId, ?InteractionType $interactionType = null): ?UtmAttributionRecord;

    /**
     * @param non-empty-string $entityId
     */
    public function findLast(string $entityId, ?InteractionType $interactionType = null): ?UtmAttributionRecord;

    /**
     * @param non-empty-string $entityId
     *
     * @return int<0, max>
     */
    public function countByEntity(string $entityId, ?InteractionType $interactionType = null): int;

    /**
     * Erases everything stored for one entity — the operation behind a
     * personal-data deletion request.
     *
     * @param non-empty-string $entityId
     *
     * @return int<0, max> number of removed rows
     */
    public function deleteByEntity(string $entityId): int;

    /**
     * Drops rows recorded before the given moment — retention, not archival.
     *
     * @return int<0, max> number of removed rows
     */
    public function purgeOlderThan(\DateTimeImmutable $before): int;

    /**
     * Counts rows recorded before the given moment without deleting them —
     * what {@see self::purgeOlderThan()} would remove, for a dry run.
     *
     * @return int<0, max>
     */
    public function countOlderThan(\DateTimeImmutable $before): int;
}
