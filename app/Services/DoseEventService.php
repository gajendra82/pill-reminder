<?php

namespace App\Services;

use App\Models\DoseEvent;
use App\Models\MedicineSchedule;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DoseEventService
{
    public function __construct(private readonly DoseOccurrenceCalculator $occurrences) {}

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function record(User $user, int $scheduleId, string $status): array
    {
        $schedule = $this->ownedSchedule($user, $scheduleId);

        if ($schedule === null) {
            return $this->notFound();
        }

        $scheduledFor = $this->occurrence($schedule);

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

        try {
            $event = DB::transaction(function () use ($schedule, $scheduledFor, $status) {
                $existing = DoseEvent::query()
                    ->where('medicine_schedule_id', $schedule->id)
                    ->where('scheduled_for', $scheduledFor->format('Y-m-d H:i:s'))
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    return $this->create($schedule, $scheduledFor, $status);
                }

                return $this->transition($existing, $status);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = DoseEvent::query()
                ->where('medicine_schedule_id', $schedule->id)
                ->where('scheduled_for', $scheduledFor->format('Y-m-d H:i:s'))
                ->first();

            return $this->duplicate($existing);
        }

        if ($event instanceof DoseEvent) {
            $event->load('schedule.medicine');

            return [
                'http_status' => $event->wasRecentlyCreated ? 201 : 200,
                'body' => [
                    'message' => $event->wasRecentlyCreated ? 'Dose event recorded.' : 'Dose event updated.',
                    'dose_event' => $this->present($event),
                ],
            ];
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function history(User $user, array $filters, int $perPage)
    {
        return $this->filteredQuery($user, $filters)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (DoseEvent $event) => $this->present($event));
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}|array<string, mixed>
     */
    public function show(User $user, DoseEvent $event): array
    {
        $event->loadMissing('schedule.medicine');

        if ($event->schedule?->medicine?->user_id !== $user->id) {
            return $this->notFound();
        }

        return [
            'http_status' => 200,
            'body' => [
                'dose_event' => $this->present($event),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(User $user, array $filters)
    {
        $query = DoseEvent::query()
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

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['medicine_schedule_id'])) {
            $query->where('medicine_schedule_id', $filters['medicine_schedule_id']);
        }

        return $query;
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

    private function occurrence(MedicineSchedule $schedule): ?Carbon
    {
        return $this->occurrences->forScheduleToday($schedule);
    }

    private function create(MedicineSchedule $schedule, Carbon $scheduledFor, string $status): DoseEvent
    {
        $event = new DoseEvent([
            'medicine_schedule_id' => $schedule->id,
            'scheduled_for' => $scheduledFor,
            'status' => DoseEvent::STATUS_PENDING,
        ]);
        $this->applyManualStatus($event, $status);
        $event->save();

        return $event;
    }

    /**
     * @return DoseEvent|array{http_status: int, body: array<string, mixed>}
     */
    private function transition(DoseEvent $event, string $status): DoseEvent|array
    {
        if ($event->status === $status) {
            return $this->duplicate($event);
        }

        $allowed = DoseEvent::TRANSITIONS[$event->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            return [
                'http_status' => 409,
                'body' => [
                    'message' => 'The recorded dose status was not changed.',
                    'result' => 'status_transition_not_allowed',
                    'dose_event' => $this->present($event->load('schedule.medicine')),
                ],
            ];
        }

        $this->applyManualStatus($event, $status);
        $event->save();

        return $event;
    }

    private function applyManualStatus(DoseEvent $event, string $status): void
    {
        $now = Carbon::now();
        $event->status = $status;
        $event->confirmation_method = DoseEvent::CONFIRMATION_USER;

        if ($status === DoseEvent::STATUS_TAKEN) {
            $event->confirmed_taken_at = $now;
        }

        if ($status === DoseEvent::STATUS_MISSED) {
            $event->missed_at = $now;
        }
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function duplicate(?DoseEvent $event): array
    {
        return [
            'http_status' => 409,
            'body' => [
                'message' => 'A dose event already exists for this schedule and time.',
                'result' => 'duplicate_dose_event',
                'dose_event' => $event === null ? null : $this->present($event->load('schedule.medicine')),
            ],
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

    /**
     * @return array<string, mixed>
     */
    public function present(DoseEvent $event): array
    {
        $event->loadMissing('schedule.medicine');

        return [
            'id' => $event->id,
            'source' => 'dose_event',
            'demo' => false,
            'demo_label' => null,
            'event_type' => null,
            'buzzer_confirmed' => false,
            'medicine_schedule_id' => $event->medicine_schedule_id,
            'scheduled_for' => $event->scheduled_for?->toIso8601String(),
            'status' => $event->status,
            'confirmation_method' => $event->confirmation_method,
            'confirmed_taken_at' => $event->confirmed_taken_at?->toIso8601String(),
            'missed_at' => $event->missed_at?->toIso8601String(),
            'sensor_detected_at' => $event->sensor_detected_at?->toIso8601String(),
            'reminder_triggered_at' => $event->reminder_triggered_at?->toIso8601String(),
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
}
