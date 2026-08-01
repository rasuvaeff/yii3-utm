<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Utm;

/**
 * How closely two touchpoints must match to count as the same campaign contact.
 *
 * Used both when deciding whether a new visit deserves its own history entry
 * and when collapsing history before attribution.
 *
 * @psalm-immutable
 *
 * @api
 */
enum UtmSimilarity: string
{
    /** Whole campaign tuple plus click identifiers. */
    case Full = 'full';

    /** Source, medium and campaign. */
    case Campaign = 'campaign';

    /** Source and medium only. */
    case SourceMedium = 'source_medium';

    public function isSimilar(UtmTouchpoint $a, UtmTouchpoint $b): bool
    {
        return match ($this) {
            self::Full => $a->utm->equals($b->utm) && $a->clickIds->equals($b->clickIds),
            self::Campaign => $a->utm->source === $b->utm->source
                && $a->utm->medium === $b->utm->medium
                && $a->utm->campaign === $b->utm->campaign,
            self::SourceMedium => $a->utm->source === $b->utm->source
                && $a->utm->medium === $b->utm->medium,
        };
    }
}
