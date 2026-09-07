<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

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

    /**
     * Normalise a HubSpot phone property to the international format
     * SmartSearch validates against.
     *
     * HubSpot stores whatever was typed, so the same number arrives as
     * "+44 7700 900123", "07700900123" or "0044 7700 900123". SmartSearch
     * documents an international format but rejects the separators, so
     * everything but the digits and a leading plus is stripped.
     *
     * @param  string|null  $country  the subject's country, since a number
     *                                written in national form only says which
     *                                country it belongs to in context
     */
    public static function phone(mixed $value, ?string $country = 'GBR'): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $value) ?? '';

        // A plus anywhere but the front is a typo, not a country code.
        $digits = str_starts_with($digits, '+')
            ? '+'.str_replace('+', '', $digits)
            : str_replace('+', '', $digits);

        // 00 is the same intent as +, written the way a handset dials it.
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        // A leading zero is a national number, which only means something once
        // the country is known. Only GB is mapped: guessing at the rest would
        // turn an unrecognised number into a wrong one.
        if (str_starts_with($digits, '0') && in_array(strtoupper((string) $country), ['GBR', 'GB', 'UK'], true)) {
            $digits = '+44'.substr($digits, 1);
        }

        // Long enough to be a number rather than an extension or a stray digit.
        return strlen(ltrim($digits, '+')) >= 7 ? $digits : null;
    }
}
