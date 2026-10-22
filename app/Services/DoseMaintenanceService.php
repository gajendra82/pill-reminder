<?php

namespace App\Services;

use App\Models\DoseEvent;
use App\Models\MedicineSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DoseMaintenanceService
{
    public function __construct(
        private readonly DoseOccurrenceCalculator $occurrences,
        private readonly DemoActivityService $demoActivity,
    ) {}

    /**
     * @return array{eligible: int, created: int, invalid: int, missed: int, demo_missed: int}
     */
    public function maintain(bool $dryRun): array
    {
        $nowUtc = Carbon::now()->copy()->utc();
        $localDate = $this->occurrences->localDate($nowUtc);
        $eligible = 0;
        $created = 0;
        $invalid = 0;
        $plannedMisses = 0;

        $schedules = MedicineSchedule::query()
            ->select('medicine_schedules.*')
            ->join('medicines', 'medicines.id', '=', 'medicine_schedules.medicine_id')
            ->where('medicine_schedules.is_active', true)
            ->where('medicines.is_active', true)
            ->orderBy('medicine_schedules.id')
            ->get();

        foreach ($schedules as $schedule) {
            if (! $this->appliesOn($schedule, $localDate)) {
                continue;
            }

            $occurrence = $this->occurrences->scheduledInstant(
                $localDate,
                $schedule->getRawOriginal('scheduled_time'),
            );

            if ($occurrence === null) {
                $invalid++;
                Log::warning('dose_maintenance_invalid_schedule_time', [
                    'schedule_id' => $schedule->id,
                    'reason' => 'invalid_schedule_time',
                ]);

                continue;
            }

            $eligible++;
            $scheduledFor = $occurrence->format('Y-m-d H:i:s');
            $exists = DoseEvent::query()
                ->where('medicine_schedule_id', $schedule->id)
                ->where('scheduled_for', $scheduledFor)
                ->exists();

            if ($exists) {
                continue;
            }

            $grace = (int) $schedule->grace_period_minutes;

            if ($this->graceElapsed($occurrence, $grace, $nowUtc)) {
                $plannedMisses++;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            $created += DoseEvent::query()->insertOrIgnore([[
                'medicine_schedule_id' => $schedule->id,
                'scheduled_for' => $scheduledFor,
                'status' => DoseEvent::STATUS_PENDING,
                'confirmation_method' => null,
                'created_at' => $nowUtc->format('Y-m-d H:i:s'),
                'updated_at' => $nowUtc->format('Y-m-d H:i:s'),
            ]]);
        }

        $missed = $dryRun
            ? $this->countOpenMisses($nowUtc) + $plannedMisses
            : $this->markOpenMisses($nowUtc);

        return [
            'eligible' => $eligible,
            'created' => $created,
            'invalid' => $invalid,
            'missed' => $missed,
            'demo_missed' => Schema::hasTable('demo_activity_events')
                ? $this->demoActivity->detectMissed($dryRun)
                : 0,
        ];
    }

    private function appliesOn(MedicineSchedule $schedule, string $localDate): bool
    {
        $startsOn = $this->calendarDate($schedule->getRawOriginal('starts_on'));

        if ($startsOn === null || $startsOn > $localDate) {
            return false;
        }

        $endsOn = $this->calendarDate($schedule->getRawOriginal('ends_on'));

        if ($endsOn !== null && $endsOn < $localDate) {
            return false;
        }

        $days = $schedule->days_of_week;

        if ($days === null) {
            return true;
        }

        if (! is_array($days) || $days === []) {
            return false;
        }

        $iso = $this->occurrences->isoWeekday($localDate);

        foreach ($days as $day) {
            if ((int) $day === $iso) {
                return true;
            }
        }

        return false;
    }

    private function calendarDate(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($raw), $parts) !== 1) {
            return null;
        }

        return $parts[1];
    }

    private function graceElapsed(Carbon $scheduledForUtc, int $graceMinutes, Carbon $nowUtc): bool
    {
        return $nowUtc->greaterThanOrEqualTo($scheduledForUtc->copy()->addMinutes($graceMinutes));
    }

    private function countOpenMisses(Carbon $nowUtc): int
    {
        $count = 0;

        $this->eachOpenEvent(function (DoseEvent $event) use (&$count, $nowUtc): void {
            if ($this->openEventIsDue($event, $nowUtc)) {
                $count++;
            }
        });

        return $count;
    }

    private function markOpenMisses(Carbon $nowUtc): int
    {
        $count = 0;
        $stamp = $nowUtc->format('Y-m-d H:i:s');

        $this->eachOpenEvent(function (DoseEvent $event) use (&$count, $nowUtc, $stamp): void {
            if (! $this->openEventIsDue($event, $nowUtc)) {
                return;
            }

            $count += DoseEvent::query()
                ->whereKey($event->id)
                ->whereIn('status', [DoseEvent::STATUS_PENDING, DoseEvent::STATUS_REMINDED])
                ->update([
                    'status' => DoseEvent::STATUS_MISSED,
                    'confirmation_method' => DoseEvent::CONFIRMATION_SCHEDULER,
                    'missed_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
        });

        return $count;
    }

    private function eachOpenEvent(callable $callback): void
    {
        DoseEvent::query()
            ->whereIn('status', [DoseEvent::STATUS_PENDING, DoseEvent::STATUS_REMINDED])
            ->with('schedule')
            ->chunkById(100, function ($events) use ($callback): void {
                foreach ($events as $event) {
                    $callback($event);
                }
            });
    }

    private function openEventIsDue(DoseEvent $event, Carbon $nowUtc): bool
    {
        $scheduledFor = $event->scheduled_for?->copy()->utc();
        $schedule = $event->schedule;

        if ($scheduledFor === null || $schedule === null) {
            return false;
        }

        return $this->graceElapsed($scheduledFor, (int) $schedule->grace_period_minutes, $nowUtc);
    }
}
