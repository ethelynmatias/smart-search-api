<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Read HubSpot property values into the shapes SmartSearch expects.
 *
 * HubSpot holds these loosely: a date property comes back as an ISO date or as
 * epoch milliseconds depending on how it was written, and a gender property is
 * whatever was typed into it. Normalising in one place keeps every caller
 * sending SmartSearch the same thing.
 */
class HubSpotProperty
{
    /**
     * Normalise a HubSpot date property to the Y-m-d SmartSearch expects.
     *
     * Date properties come back as either an ISO date or epoch milliseconds
     * depending on how the property was written.
     */
    public static function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::createFromTimestampMs((int) $value)->format('Y-m-d');
            }

            return static::slashedDate(trim((string) $value))
                ?? Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalise a HubSpot gender property to the SmartSearch sex values.
     *
     * Anything that is not recognisably male or female is dropped rather
     * than guessed at, so the search is skipped instead of sent wrong.
     */
    public static function sex(mixed $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => null,
        };
    }

    /**
     * Normalise a slash separated date, working out which part is the day.
     *
     * Carbon reads slashed dates as m/d/Y, so 17/11/2004 would throw and a
     * genuine 11/17/2004 would be read correctly by luck alone. A part above 12
     * can only be the day, which settles most dates; where both parts could be
     * either, d/m/Y wins, as HubSpot holds these in UK format.
     *
     * Returns null for anything that is not a slashed date, leaving the caller
     * to parse it as before.
     */
    protected static function slashedDate(string $value): ?string
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches)) {
            return null;
        }

        [, $first, $second, $year] = array_map('intval', $matches);

        // A day/month pair the other way round: 11/17/2004.
        [$day, $month] = $second > 12 ? [$second, $first] : [$first, $second];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day)->format('Y-m-d');
    }
}
