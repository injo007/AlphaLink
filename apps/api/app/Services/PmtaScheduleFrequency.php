<?php

namespace App\Services;

use InvalidArgumentException;

class PmtaScheduleFrequency
{
    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'everyMinute' => 'Every Minute', 'everyTwoMinutes' => 'Every Two Minutes', 'everyThreeMinutes' => 'Every Three Minutes',
            'everyFourMinutes' => 'Every Four Minutes', 'everyFiveMinutes' => 'Every Five Minutes', 'everyTenMinutes' => 'Every Ten Minutes',
            'everyFifteenMinutes' => 'Every Fifteen Minutes', 'everyThirtyMinutes' => 'Every Thirty Minutes', 'hourly' => 'Hourly',
            'everyOddHour' => 'Every Odd Hour', 'everyTwoHours' => 'Every Two Hours', 'everyThreeHours' => 'Every Three Hours',
            'everyFourHours' => 'Every Four Hours', 'everySixHours' => 'Every Six Hours', 'daily' => 'Daily', 'twiceDaily' => 'Twice Daily',
            'weekdays' => 'Weekdays', 'weekends' => 'Weekends', 'mondays' => 'Mondays', 'tuesdays' => 'Tuesdays',
            'wednesdays' => 'Wednesdays', 'thursdays' => 'Thursdays', 'fridays' => 'Fridays', 'saturdays' => 'Saturdays',
            'sundays' => 'Sundays', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'twiceMonthly' => 'Twice Monthly',
            'lastDayOfMonth' => 'Last Day Of Month',
        ];
    }

    public function expression(string $frequency): string
    {
        $minuteSteps = ['everyTwoMinutes' => 2, 'everyThreeMinutes' => 3, 'everyFourMinutes' => 4, 'everyFiveMinutes' => 5, 'everyTenMinutes' => 10, 'everyFifteenMinutes' => 15, 'everyThirtyMinutes' => 30];
        if (isset($minuteSteps[$frequency])) {
            return '*/'.$minuteSteps[$frequency].' * * * *';
        }

        $hourSteps = ['everyTwoHours' => 2, 'everyThreeHours' => 3, 'everyFourHours' => 4, 'everySixHours' => 6];
        if (isset($hourSteps[$frequency])) {
            return '0 */'.$hourSteps[$frequency].' * * *';
        }

        $days = ['mondays' => 1, 'tuesdays' => 2, 'wednesdays' => 3, 'thursdays' => 4, 'fridays' => 5, 'saturdays' => 6, 'sundays' => 0];
        if (isset($days[$frequency])) {
            return '0 0 * * '.$days[$frequency];
        }

        $expressions = [
            'everyMinute' => '* * * * *', 'hourly' => '0 * * * *', 'everyOddHour' => '0 1-23/2 * * *',
            'daily' => '0 0 * * *', 'twiceDaily' => '0 1,13 * * *', 'weekdays' => '0 0 * * 1-5',
            'weekends' => '0 0 * * 0,6', 'weekly' => '0 0 * * 0', 'monthly' => '0 0 1 * *',
            'twiceMonthly' => '0 0 1,16 * *', 'lastDayOfMonth' => '0 0 28-31 * *',
        ];
        if (! isset($expressions[$frequency])) {
            throw new InvalidArgumentException('Choose a supported PMTA schedule frequency.');
        }

        return $expressions[$frequency];
    }

    public function isLastDay(string $frequency): bool
    {
        return $frequency === 'lastDayOfMonth';
    }
}
