<?php

namespace Ziming\LaravelMyinfoSg\Utils;

use Illuminate\Support\Carbon;

/*
 * @internal
 *
 * This class is for my own use for now, I will not care about making breaking changes.
 * You have been warned.
 *
 * Check the link below for changes to scheduled downtime.
 * https://api.singpass.gov.sg/library/myinfo/developers/implementation-downtimes
 */
final class MyinfoAvailabilityChecker
{
    public static function cpfbUnavailable(): bool
    {
        $now = Carbon::now('Asia/Singapore');

        Carbon::createFromTime(5, 30, 0, 'Asia/Singapore');
        $cpfbUnavailableTimeStart = Carbon::createFromTime(5, 0, 0, 'Asia/Singapore');
        $cpfbUnavailableTimeEnd = Carbon::createFromTime(5, 30, 0, 'Asia/Singapore');

        if ($now->between($cpfbUnavailableTimeStart, $cpfbUnavailableTimeEnd)) {
            return true;
        }

        return false;
    }

    public static function irasUnavailable(): bool
    {
        $now = Carbon::now('Asia/Singapore');

        $irasUnavailableTimeStartWed = Carbon::createFromTime(2, 0, 0, 'Asia/Singapore');;
        $irasUnavailableTimeEndWed = Carbon::createFromTime(6, 0, 0, 'Asia/Singapore');

        if ($now->isWednesday() && $now->between($irasUnavailableTimeStartWed, $irasUnavailableTimeEndWed)) {
            return true;
        }

        $irasUnavailableTimeStartSun = Carbon::createFromTime(2, 0, 0, 'Asia/Singapore');;
        $irasUnavailableTimeEndSun = Carbon::createFromTime(8, 30, 0, 'Asia/Singapore');

        if ($now->isSunday() && $now->between($irasUnavailableTimeStartSun, $irasUnavailableTimeEndSun)) {
            return true;
        }

        return false;
    }
}
