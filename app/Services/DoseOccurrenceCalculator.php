<?php

namespace App\Services;

use App\Models\MedicineSchedule;
use Illuminate\Support\Carbon;

/**
 * Schedule clocks are Asia/Kolkata wall times. Dose instants are stored in UTC.
 */
class DoseOccurrenceCalculator
{
    public const TIMEZONE = 'Asia/Kolkata';

    public function localDate(?Carbon $instant = null): string
    {
        return ($instant ?? Carbon::now())->copy()->timezone(self::TIMEZONE)->toDateString();
    }

    public function isoWeekday(string $localDate): int
    {
        $date = Carbon::createFromFormat('Y-m-d', $localDate, self::TIMEZONE);

        return $date instanceof Carbon ? $date->dayOfWeekIso : 0;
    }

    public function forScheduleToday(MedicineSchedule $schedule, ?Carbon $now = null): ?Carbon
    {
        return $this->scheduledInstant(
            $this->localDate($now),
            $schedule->getRawOriginal('scheduled_time'),
        );
    }

    public function scheduledInstant(string $localDate, mixed $rawTime): ?Carbon
    {
        $clock = $this->parseClock($rawTime);

        if ($clock === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
            return null;
        }

        try {
            $local = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                sprintf('%s %02d:%02d:%02d', $localDate, $clock[0], $clock[1], $clock[2]),
                self::TIMEZONE,
            );
        } catch (\Throwable) {
            return null;
        }

        if (! $local instanceof Carbon) {
            return null;
        }

        return $local->copy()->utc();
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    public function parseClock(mixed $rawTime): ?array
    {
        if (! is_string($rawTime) || preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', trim($rawTime), $parts) !== 1) {
            return null;
        }

        $hour = (int) $parts[1];
        $minute = (int) $parts[2];
        $second = (int) ($parts[3] ?? 0);

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return [$hour, $minute, $second];
    }
}
