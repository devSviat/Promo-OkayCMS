<?php

namespace Okay\Modules\Sviat\Promo\Services;

/**
 * Where a campaign stands relative to its dates, as the landing page shows it.
 *
 * Dates are stored in shop time and parsed with the PHP default timezone, which
 * Config sets from php_timezone.
 */
enum CampaignPhase
{
    case Upcoming;
    case Active;
    case Ended;

    public static function fromPromo(object $promo, int $now): self
    {
        if (empty($promo->has_date_range)) {
            return self::Active;
        }

        if (!empty($promo->date_end) && strtotime((string) $promo->date_end) < $now) {
            return self::Ended;
        }

        if (!empty($promo->date_start) && strtotime((string) $promo->date_start) > $now) {
            return self::Upcoming;
        }

        return self::Active;
    }

    /**
     * An ended campaign stays reachable but lists no products, so search engines
     * get noindex for it.
     */
    public function isIndexable(): bool
    {
        return $this !== self::Ended;
    }
}
