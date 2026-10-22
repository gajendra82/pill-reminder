<?php

namespace App\Services;

use App\Models\DemoActivityEvent;
use App\Models\DoseEvent;
use App\Models\MedicineSchedule;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo activity is stored apart from dose_events. A verified pin read-back
 * records demo_schedule_synced only. It is not a buzzer event and not a dose.
 */
class DemoActivityService
{
    public function __construct(private readonly DoseOccurrenceCalculator $occurrences) {}

    /**
     * @return array<string, mixed>
     */
    public function recordVerifiedSync(MedicineSchedule $schedule, int $slot, int $hour, int $minute): array
    {
        if (! $this->available()) {
            return $this->unavailable();
        }

        $scheduledFor = $this->matchingOccurrence($schedule, $hour, $minute);

        if ($scheduledFor === null) {
            return $this->notRecorded();
        }

        $event = $this->firstOrCreate(
            $schedule,
            DemoActivityEvent::TYPE_SYNCED,
            $scheduledFor,
            Carbon::now()->utc(),
            DemoActivityEvent::SOURCE_READ_BACK,
            [
                'slot' => $slot,
                'verified_hour' => $hour,
                'verified_minute' => $minute,
            ],
        );

        return $this->recorded($event);
    }

    /**
     * Trusted device adapter only. There is no client route for this event.
     * A trigger still requires a verified demo sync for the same occurrence.
     *
     * @return array<string, mixed>
     */
    public function recordDeviceTrigger(MedicineSchedule $schedule, Carbon $occurredAt): array
    {
        if (! $this->available()) {
            return $this->unavailable();
        }

        $scheduledFor = $this->occurrences->forScheduleToday($schedule, $occurredAt);

        if ($scheduledFor === null || ! $this->hasType($schedule, DemoActivityEvent::TYPE_SYNCED, $scheduledFor)) {
            return $this->notRecorded();
        }

        $event = $this->firstOrCreate(
            $schedule,
            DemoActivityEvent::TYPE_TRIGGERED,
            $scheduledFor,
            $occurredAt->copy()->utc(),
            DemoActivityEvent::SOURCE_DEVICE,
            null,
        );

        return $this->recorded($event);
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function recordTaken(User $user, int $scheduleId): array
    {
        $schedule = $this->ownedSchedule($user, $scheduleId);

        if ($schedule === null) {
            return $this->notFound();
        }

        if (! $this->available()) {
            return [
                'http_status' => 503,
                'body' => [
                    'message' => 'Demo activity storage is not available.',
                    'result' => 'demo_activity_unavailable',
                ],
            ];
        }

        $scheduledFor = $this->occurrences->forScheduleToday($schedule);

        if ($scheduledFor === null) {
            return [
                'http_status' => 422,
                'body' => [
                    'message' => 'The stored schedule time is not a valid clock time.',
                    'errors' => [
                        'medicine_schedule_id' => ['The stored schedule time is not a valid clock time.'],
                    ],
                ],
            ];
        }

        if (! $this->hasType($schedule, DemoActivityEvent::TYPE_SYNCED, $scheduledFor)) {
            return [
                'http_status' => 422,
                'body' => [
                    'message' => 'Confirm a demo dose only after this schedule has a verified demo sync.',
                    'result' => 'demo_sync_required',
                ],
            ];
        }

        $event = $this->firstOrCreate(
            $schedule,
            DemoActivityEvent::TYPE_TAKEN,
            $scheduledFor,
            Carbon::now()->utc(),
            DemoActivityEvent::SOURCE_USER,
            null,
        );

        if (! $event->wasRecentlyCreated) {
            return [
                'http_status' => 409,
                'body' => [
                    'message' => 'A demo taken confirmation already exists for this schedule and time.',
                    'result' => 'duplicate_demo_event',
                    'demo_event' => $this->present($event),
                ],
            ];
        }

        return [
            'http_status' => 201,
            'body' => [
                'message' => 'Demo taken confirmation recorded.',
                'demo_event' => $this->present($event),
            ],
        ];
    }

    public function detectMissed(bool $dryRun): int
    {
        if (! $this->available()) {
            return 0;
        }

        $now = Carbon::now()->utc();
        $count = 0;

        DemoActivityEvent::query()
            ->where('event_type', DemoActivityEvent::TYPE_TRIGGERED)
            ->with('schedule')
            ->orderBy('id')
            ->chunkById(100, function ($triggers) use (&$count, $dryRun, $now): void {
                foreach ($triggers as $trigger) {
                    $schedule = $trigger->schedule;
                    $scheduledFor = $trigger->scheduled_for?->copy()->utc();

                    if ($schedule === null || $scheduledFor === null) {
                        continue;
                    }

                    if ($now->lessThan($scheduledFor->copy()->addMinutes((int) $schedule->grace_period_minutes))) {
                        continue;
                    }

                    if ($this->hasType($schedule, DemoActivityEvent::TYPE_TAKEN, $scheduledFor)
                        || $this->hasType($schedule, DemoActivityEvent::TYPE_MISSED, $scheduledFor)) {
                        continue;
                    }

                    $count++;

                    if ($dryRun) {
                        continue;
                    }

                    $this->firstOrCreate(
                        $schedule,
                        DemoActivityEvent::TYPE_MISSED,
                        $scheduledFor,
                        $now,
                        DemoActivityEvent::SOURCE_SCHEDULER,
                        ['trigger_event_id' => $trigger->id],
                    );
                }
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function history(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        if (! $this->available()) {
            return $this->emptyHistory($perPage);
        }

        return $this->filteredQuery($user, $filters)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (DemoActivityEvent $event) => $this->present($event));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function combinedHistory(User $user, array $filters, int $perPage, DoseEventService $doses): LengthAwarePaginator
    {
        $real = $this->realStatus($filters)
            ? $doses->filteredQuery($user, $filters)->get()->map(fn (DoseEvent $event) => $doses->present($event))
            : collect();
        $demo = $this->demoStatus($filters) && $this->available()
            ? $this->filteredQuery($user, $filters)->get()->map(fn (DemoActivityEvent $event) => $this->present($event))
            : collect();

        $rows = $real->concat($demo)->sort(function (array $left, array $right): int {
            $time = strcmp((string) ($right['scheduled_for'] ?? ''), (string) ($left['scheduled_for'] ?? ''));

            return $time !== 0 ? $time : (($right['id'] ?? 0) <=> ($left['id'] ?? 0));
        })->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return (new LengthAwarePaginator(
            $slice,
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url()],
        ))->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function analytics(User $user, array $filters): array
    {
        $real = $this->realCounts($user, $filters);
        $taken = $real[DoseEvent::STATUS_TAKEN] ?? 0;
        $missed = $real[DoseEvent::STATUS_MISSED] ?? 0;
        $denominator = $taken + $missed;

        return [
            'adherence' => [
                'taken' => $taken,
                'missed' => $missed,
                'skipped' => $real[DoseEvent::STATUS_SKIPPED] ?? 0,
                'pending' => $real[DoseEvent::STATUS_PENDING] ?? 0,
                'reminded' => $real[DoseEvent::STATUS_REMINDED] ?? 0,
                'sensor_detected' => $real[DoseEvent::STATUS_SENSOR_DETECTED] ?? 0,
                'adherence_percent' => $denominator === 0 ? null : round(($taken / $denominator) * 100, 1),
                'includes_demo_activity' => false,
            ],
            'demo_activity' => [
                'schedules_synced' => $this->demoCount($user, $filters, DemoActivityEvent::TYPE_SYNCED),
                'reminder_triggers' => $this->demoCount($user, $filters, DemoActivityEvent::TYPE_TRIGGERED),
                'taken' => $this->demoCount($user, $filters, DemoActivityEvent::TYPE_TAKEN),
                'missed' => $this->demoCount($user, $filters, DemoActivityEvent::TYPE_MISSED),
                'included_in_adherence' => false,
            ],
        ];
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function show(User $user, DemoActivityEvent $event): array
    {
        $event->loadMissing('schedule.medicine');

        if ($event->schedule?->medicine?->user_id !== $user->id) {
            return $this->notFound();
        }

        return [
            'http_status' => 200,
            'body' => [
                'demo_event' => $this->present($event),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(DemoActivityEvent $event): array
    {
        $event->loadMissing('schedule.medicine');
        $scheduledFor = $event->scheduled_for?->copy()->utc();

        return [
            'id' => $event->id,
            'source' => 'demo_activity',
            'demo' => true,
            'demo_label' => 'DEMO',
            'event_type' => $event->event_type,
            'status' => $event->event_type,
            'description' => DemoActivityEvent::DESCRIPTIONS[$event->event_type] ?? 'DEMO.',
            'buzzer_confirmed' => $event->event_type === DemoActivityEvent::TYPE_TRIGGERED,
            'medicine_schedule_id' => $event->medicine_schedule_id,
            'scheduled_for' => $scheduledFor?->toIso8601String(),
            'scheduled_date' => $scheduledFor?->copy()->timezone(DoseOccurrenceCalculator::TIMEZONE)->toDateString(),
            'occurred_at' => $event->occurred_at?->copy()->utc()->toIso8601String(),
            'confirmation_method' => $event->confirmation_method,
            'medicine' => [
                'id' => $event->schedule?->medicine?->id,
                'name' => $event->schedule?->medicine?->name,
            ],
            'schedule' => [
                'id' => $event->schedule?->id,
                'scheduled_time' => $event->schedule?->getRawOriginal('scheduled_time'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(User $user, array $filters)
    {
        $query = DemoActivityEvent::query()
            ->whereHas('schedule.medicine', function ($medicine) use ($user) {
                $medicine->where('user_id', $user->id);
            })
            ->with('schedule.medicine');

        if (isset($filters['from'])) {
            $query->where('scheduled_for', '>=', $filters['from'].' 00:00:00');
        }

        if (isset($filters['to'])) {
            $query->where('scheduled_for', '<=', $filters['to'].' 23:59:59');
        }

        if (isset($filters['status']) && in_array($filters['status'], DemoActivityEvent::TYPES, true)) {
            $query->where('event_type', $filters['status']);
        }

        if (isset($filters['status']) && ! in_array($filters['status'], DemoActivityEvent::TYPES, true)) {
            $query->whereRaw('1 = 0');
        }

        if (isset($filters['medicine_schedule_id'])) {
            $query->where('medicine_schedule_id', $filters['medicine_schedule_id']);
        }

        return $query;
    }

    public function storageAvailable(): bool
    {
        return $this->available();
    }

    private function available(): bool
    {
        return Schema::hasTable('demo_activity_events');
    }

    private function emptyHistory(int $perPage): LengthAwarePaginator
    {
        return (new LengthAwarePaginator(
            [],
            0,
            $perPage,
            LengthAwarePaginator::resolveCurrentPage(),
            ['path' => request()->url()],
        ))->withQueryString();
    }

    private function matchingOccurrence(MedicineSchedule $schedule, int $hour, int $minute): ?Carbon
    {
        $clock = $this->occurrences->parseClock($schedule->getRawOriginal('scheduled_time'));

        if ($clock === null || $clock[0] !== $hour || $clock[1] !== $minute || $clock[2] !== 0) {
            return null;
        }

        return $this->occurrences->forScheduleToday($schedule);
    }

    private function hasType(MedicineSchedule $schedule, string $type, Carbon $scheduledFor): bool
    {
        return DemoActivityEvent::query()
            ->where('medicine_schedule_id', $schedule->id)
            ->where('event_type', $type)
            ->where('scheduled_for', $scheduledFor->copy()->utc()->format('Y-m-d H:i:s'))
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function firstOrCreate(
        MedicineSchedule $schedule,
        string $type,
        Carbon $scheduledFor,
        Carbon $occurredAt,
        string $confirmationMethod,
        ?array $metadata,
    ): DemoActivityEvent {
        $stamp = $scheduledFor->copy()->utc()->format('Y-m-d H:i:s');

        try {
            return DB::transaction(function () use ($schedule, $type, $stamp, $occurredAt, $confirmationMethod, $metadata) {
                $existing = DemoActivityEvent::query()
                    ->where('medicine_schedule_id', $schedule->id)
                    ->where('event_type', $type)
                    ->where('scheduled_for', $stamp)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                return DemoActivityEvent::query()->create([
                    'medicine_schedule_id' => $schedule->id,
                    'event_type' => $type,
                    'scheduled_for' => $stamp,
                    'occurred_at' => $occurredAt->copy()->utc()->format('Y-m-d H:i:s'),
                    'confirmation_method' => $confirmationMethod,
                    'metadata' => $metadata,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return DemoActivityEvent::query()
                ->where('medicine_schedule_id', $schedule->id)
                ->where('event_type', $type)
                ->where('scheduled_for', $stamp)
                ->firstOrFail();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function recorded(DemoActivityEvent $event): array
    {
        return [
            'demo_activity_available' => true,
            'demo_event_recorded' => $event->wasRecentlyCreated,
            'demo_event_duplicate' => ! $event->wasRecentlyCreated,
            'demo_event' => $this->present($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(): array
    {
        return [
            'demo_activity_available' => false,
            'demo_event_recorded' => false,
            'demo_event' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notRecorded(): array
    {
        return [
            'demo_activity_available' => true,
            'demo_event_recorded' => false,
            'demo_event' => null,
        ];
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function notFound(): array
    {
        return [
            'http_status' => 404,
            'body' => [
                'message' => 'Not found.',
            ],
        ];
    }

    private function ownedSchedule(User $user, int $scheduleId): ?MedicineSchedule
    {
        return MedicineSchedule::query()
            ->whereKey($scheduleId)
            ->whereHas('medicine', function ($medicine) use ($user) {
                $medicine->where('user_id', $user->id);
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function realStatus(array $filters): bool
    {
        return ! isset($filters['status']) || in_array($filters['status'], DoseEvent::STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function demoStatus(array $filters): bool
    {
        return ! isset($filters['status']) || in_array($filters['status'], DemoActivityEvent::TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function realCounts(User $user, array $filters): array
    {
        return DoseEvent::query()
            ->whereHas('schedule.medicine', function ($medicine) use ($user) {
                $medicine->where('user_id', $user->id);
            })
            ->when(isset($filters['from']), fn ($query) => $query->where('scheduled_for', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn ($query) => $query->where('scheduled_for', '<=', $filters['to'].' 23:59:59'))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function demoCount(User $user, array $filters, string $type): int
    {
        if (! $this->available()) {
            return 0;
        }

        $filters['status'] = $type;

        return $this->filteredQuery($user, $filters)->count();
    }
}
