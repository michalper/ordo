<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

/**
 * Pure hour-of-day window logic for campaign quiet hours - no config/DB dependencies, so it's
 * unit tested directly against fixed \DateTimeImmutable instants. Handles both a same-day window
 * (start_hour < end_hour, e.g. 1-5) and an overnight-wraparound window (start_hour >= end_hour,
 * e.g. 21-8, which spans midnight) with the same comparison, since "start >= end" is exactly the
 * signal a window wraps.
 */
class QuietHoursCalculator
{
    /**
     * True when $nowUtc, expressed in $timezone, falls within [$startHour, $endHour) - the end
     * hour itself is the first hour OUTSIDE quiet hours, matching how "quiet hours end at 8am"
     * is read in plain English (8am is not itself still quiet).
     */
    public function isWithinQuietHours(
        \DateTimeZone $timezone,
        int $startHour,
        int $endHour,
        \DateTimeImmutable $nowUtc
    ): bool {
        $hour = (int) $nowUtc->setTimezone($timezone)->format('G');

        if ($startHour === $endHour) {
            // A zero-width or full-day window is nonsensical as "quiet hours" - treat it as
            // never-quiet rather than always-quiet, the safer failure direction (a misconfigured
            // window shouldn't defer every single send indefinitely).
            return false;
        }

        if ($startHour < $endHour) {
            return $hour >= $startHour && $hour < $endHour;
        }

        // Overnight window (e.g. 21-8): quiet from start_hour through midnight, then from
        // midnight through (but not including) end_hour.
        return $hour >= $startHour || $hour < $endHour;
    }

    /**
     * The next UTC instant at which quiet hours end, given $nowUtc is currently within them -
     * callers are expected to only call this after isWithinQuietHours() returned true, but it's
     * still well-defined (returns today's or tomorrow's end-hour instant, whichever is next)
     * even if called otherwise.
     */
    public function nextQuietHoursEndUtc(\DateTimeZone $timezone, int $endHour, \DateTimeImmutable $nowUtc): \DateTimeImmutable
    {
        $localNow = $nowUtc->setTimezone($timezone);
        $candidate = $localNow->setTime($endHour, 0, 0);

        if ($candidate <= $localNow) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new \DateTimeZone('UTC'));
    }
}
