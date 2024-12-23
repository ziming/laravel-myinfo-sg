<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Utils;

use Carbon\Carbon;

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
        };

        $cpfbUnavailableTimeStart = Carbon::createFromTime(0, 0, 0, 'Asia/Singapore');
        $cpfbUnavailableTimeEnd = Carbon::createFromTime(8, 0, 0, 'Asia/Singapore');

        return in_array($now->weekNumberInMonth, [1, 4]) &&
            $now->isSunday() &&
            $now->between($cpfbUnavailableTimeStart, $cpfbUnavailableTimeEnd);

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

        return $now->isSunday() && $now->between($irasUnavailableTimeStartSun, $irasUnavailableTimeEndSun);
    }

    public static function momUnavailable(): bool
    {
        $now = Carbon::now('Asia/Singapore');

        $momsUnavailableTimeStart = Carbon::createFromTime(0, 0, 0, 'Asia/Singapore');;
        $momUnavailableTimeEnd = Carbon::createFromTime(6, 0, 0, 'Asia/Singapore');

        return $now->weekNumberInMonth === 4 &&
            $now->isSunday() &&
            $now->between($momsUnavailableTimeStart, $momUnavailableTimeEnd);
    }

}
