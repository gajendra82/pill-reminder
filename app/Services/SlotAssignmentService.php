<?php

namespace App\Services;

use App\Models\DeviceScheduleSlot;
use App\Models\Medicine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit medicine-to-slot assignments. This service never calls Blynk.
 */
class SlotAssignmentService
{
    public const RESULT_ASSIGNED = 'assigned';

    public const RESULT_REPLACED = 'replaced';

    public const RESULT_RELEASED = 'released';

    public const RESULT_NO_FREE_SLOT = 'no_free_slot';

    public const RESULT_ALREADY_ASSIGNED = 'already_assigned';

    public const RESULT_MEDICINE_ALREADY_ASSIGNED = 'medicine_already_assigned';

    public const RESULT_MULTIPLE = 'unsupported_multiple_schedules_for_medicine';

    public const RESULT_MISSING = 'missing_schedules';

    public const RESULT_SCHEDULE_NOT_ELIGIBLE = 'schedule_not_eligible';

    public const RESULT_MEDICINE_INACTIVE = 'medicine_inactive';

    public const RESULT_NOT_FOUND = 'not_found';

    public const RESULT_SLOT_NOT_ASSIGNED = 'slot_not_assigned';

    public const RESULT_CONFIRMATION_REQUIRED = 'confirmation_required';

    public const RESULT_INVALID_SLOT = 'invalid_slot';

    public const RESULT_UNAVAILABLE = 'slot_assignment_persistence_unavailable';

    public const RESULT_CONFLICT = 'assignment_conflict';

    public function persistenceAvailable(): bool
    {
        return Schema::hasTable('device_schedule_slots');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user): array
    {
        $rows = DeviceScheduleSlot::query()
            ->where('user_id', $user->id)
            ->orderBy('slot')
            ->get()
            ->keyBy(fn (DeviceScheduleSlot $row) => (int) $row->slot);

        $slots = [];

        foreach (BlynkScheduleMapper::SLOT_PINS as $slot => $pins) {
            /** @var DeviceScheduleSlot|null $row */
            $row = $rows->get($slot);
            $slots[] = $row === null
                ? [
                    'slot' => $slot,
                    'status' => 'unused',
                    'medicine_id' => null,
                    'schedule_id' => null,
                    'assigned_at' => null,
                    'hour_pin' => $pins['hour'],
                    'minute_pin' => $pins['minute'],
                ]
                : [
                    'slot' => $slot,
                    'status' => 'assigned',
                    'medicine_id' => $row->medicine_id,
                    'schedule_id' => $row->schedule_id,
                    'assigned_at' => $row->assigned_at?->toIso8601String(),
                    'hour_pin' => $pins['hour'],
                    'minute_pin' => $pins['minute'],
                ];
        }

        return $slots;
    }

    /**
     * @return list<array{slot: int, medicine_id: int, schedule_id: int}>
     */
    public function assignmentsFor(User $user): array
    {
        return DeviceScheduleSlot::query()
            ->where('user_id', $user->id)
            ->orderBy('slot')
            ->get(['slot', 'medicine_id', 'schedule_id'])
            ->map(fn (DeviceScheduleSlot $row) => [
                'slot' => (int) $row->slot,
                'medicine_id' => (int) $row->medicine_id,
                'schedule_id' => (int) $row->schedule_id,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function assign(User $user, int $medicineId, ?int $scheduleId = null): array
    {
        if (! $this->persistenceAvailable()) {
            return $this->outcome(self::RESULT_UNAVAILABLE, 503, 'The device_schedule_slots table is not available.');
        }

        try {
            return DB::transaction(function () use ($user, $medicineId, $scheduleId) {
                $occupied = $this->lockSlots($user);
                $medicine = $this->lockOwnedMedicine($user, $medicineId);

                if ($medicine === null) {
                    return $this->outcome(self::RESULT_NOT_FOUND, 404, 'Medicine not found.');
                }

                $existing = $occupied->first(fn (DeviceScheduleSlot $row) => (int) $row->medicine_id === (int) $medicine->id);

                if ($existing !== null) {
                    return $this->outcome(
                        self::RESULT_ALREADY_ASSIGNED,
                        409,
                        'This medicine already occupies a slot. It was not moved, and its schedule was not replaced.',
                        $this->slotPayload($existing),
                        ['schedule_replacement_required' => $scheduleId !== null && (int) $existing->schedule_id !== $scheduleId],
                    );
                }

                $eligible = $this->eligibleSchedule($medicine, $scheduleId);

                if ($eligible['result'] !== null) {
                    return $eligible['result'];
                }

                $used = $occupied->map(fn (DeviceScheduleSlot $row) => (int) $row->slot)->all();
                $free = null;

                foreach (array_keys(BlynkScheduleMapper::SLOT_PINS) as $slot) {
                    if (! in_array($slot, $used, true)) {
                        $free = $slot;
                        break;
                    }
                }

                if ($free === null) {
                    return $this->outcome(
                        self::RESULT_NO_FREE_SLOT,
                        409,
                        'All three reminder slots are occupied. Release a slot before assigning another medicine.',
                    );
                }

                $row = DeviceScheduleSlot::query()->create([
                    'user_id' => $user->id,
                    'slot' => $free,
                    'medicine_id' => $medicine->id,
                    'schedule_id' => $eligible['schedule']->id,
                    'assigned_at' => Carbon::now(),
                ]);

                return $this->outcome(self::RESULT_ASSIGNED, 201, 'Medicine assigned to the lowest free slot.', $this->slotPayload($row));
            });
        } catch (UniqueConstraintViolationException) {
            return $this->outcome(
                self::RESULT_CONFLICT,
                409,
                'Another assignment took this slot at the same time. The occupied slot was not overwritten.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function release(User $user, int $slot): array
    {
        if (! $this->persistenceAvailable()) {
            return $this->outcome(self::RESULT_UNAVAILABLE, 503, 'The device_schedule_slots table is not available.');
        }

        if (! $this->validSlot($slot)) {
            return $this->outcome(self::RESULT_INVALID_SLOT, 422, 'Slot must be 1, 2, or 3.');
        }

        return DB::transaction(function () use ($user, $slot) {
            $occupied = $this->lockSlots($user);
            $row = $occupied->first(fn (DeviceScheduleSlot $item) => (int) $item->slot === $slot);

            if ($row === null) {
                return $this->outcome(self::RESULT_SLOT_NOT_ASSIGNED, 404, 'That slot is not assigned.');
            }

            $payload = $this->slotPayload($row);
            $row->delete();

            return $this->outcome(
                self::RESULT_RELEASED,
                200,
                'The slot was released. Other occupied slots were left in place.',
                $payload,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function replace(User $user, int $slot, int $medicineId, int $scheduleId, bool $confirmed): array
    {
        if (! $this->persistenceAvailable()) {
            return $this->outcome(self::RESULT_UNAVAILABLE, 503, 'The device_schedule_slots table is not available.');
        }

        if (! $confirmed) {
            return $this->outcome(
                self::RESULT_CONFIRMATION_REQUIRED,
                422,
                'Replacing a slot assignment requires confirmed=true. Nothing was changed.',
            );
        }

        if (! $this->validSlot($slot)) {
            return $this->outcome(self::RESULT_INVALID_SLOT, 422, 'Slot must be 1, 2, or 3.');
        }

        try {
            return DB::transaction(function () use ($user, $slot, $medicineId, $scheduleId) {
                $occupied = $this->lockSlots($user);
                $current = $occupied->first(fn (DeviceScheduleSlot $item) => (int) $item->slot === $slot);

                if ($current === null) {
                    return $this->outcome(self::RESULT_SLOT_NOT_ASSIGNED, 404, 'That slot is not assigned. Use assignment to fill a free slot.');
                }

                $medicine = $this->lockOwnedMedicine($user, $medicineId);

                if ($medicine === null) {
                    return $this->outcome(self::RESULT_NOT_FOUND, 404, 'Medicine not found.');
                }

                $other = $occupied->first(function (DeviceScheduleSlot $item) use ($medicine, $slot) {
                    return (int) $item->medicine_id === (int) $medicine->id && (int) $item->slot !== $slot;
                });

                if ($other !== null) {
                    return $this->outcome(
                        self::RESULT_MEDICINE_ALREADY_ASSIGNED,
                        409,
                        'That medicine already occupies another slot and was not moved.',
                        $this->slotPayload($other),
                    );
                }

                $eligible = $this->eligibleSchedule($medicine, $scheduleId);

                if ($eligible['result'] !== null) {
                    return $eligible['result'];
                }

                $current->medicine_id = $medicine->id;
                $current->schedule_id = $eligible['schedule']->id;
                $current->assigned_at = Carbon::now();
                $current->save();

                return $this->outcome(
                    self::RESULT_REPLACED,
                    200,
                    'The slot assignment was replaced explicitly. Other slots were left in place.',
                    $this->slotPayload($current->refresh()),
                );
            });
        } catch (UniqueConstraintViolationException) {
            return $this->outcome(
                self::RESULT_CONFLICT,
                409,
                'The replacement conflicted with an existing assignment. The occupied slot was not overwritten.',
            );
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, DeviceScheduleSlot>
     */
    private function lockSlots(User $user)
    {
        return DeviceScheduleSlot::query()
            ->where('user_id', $user->id)
            ->orderBy('slot')
            ->lockForUpdate()
            ->get();
    }

    private function lockOwnedMedicine(User $user, int $medicineId): ?Medicine
    {
        return Medicine::query()
            ->whereKey($medicineId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{result: array<string, mixed>|null, schedule: \App\Models\MedicineSchedule|null}
     */
    private function eligibleSchedule(Medicine $medicine, ?int $scheduleId): array
    {
        if (! $medicine->is_active) {
            return [
                'result' => $this->outcome(self::RESULT_MEDICINE_INACTIVE, 422, 'An inactive medicine cannot be assigned.'),
                'schedule' => null,
            ];
        }

        $active = $medicine->schedules()
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($active->count() > 1) {
            return [
                'result' => $this->outcome(
                    self::RESULT_MULTIPLE,
                    422,
                    'This medicine has more than one active schedule. None was chosen.',
                    null,
                    ['schedule_ids' => $active->pluck('id')->map(fn ($id) => (int) $id)->all()],
                ),
                'schedule' => null,
            ];
        }

        if ($active->isEmpty()) {
            return [
                'result' => $this->outcome(self::RESULT_MISSING, 422, 'This medicine has no active schedule.'),
                'schedule' => null,
            ];
        }

        $schedule = $active->first();

        if ($scheduleId !== null && (int) $schedule->id !== $scheduleId) {
            return [
                'result' => $this->outcome(
                    self::RESULT_SCHEDULE_NOT_ELIGIBLE,
                    422,
                    'The requested schedule is not the single active schedule for this medicine. A different schedule was not substituted.',
                ),
                'schedule' => null,
            ];
        }

        return ['result' => null, 'schedule' => $schedule];
    }

    private function validSlot(int $slot): bool
    {
        return array_key_exists($slot, BlynkScheduleMapper::SLOT_PINS);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function outcome(string $result, int $status, string $message, ?array $slot = null, array $extra = []): array
    {
        return array_merge([
            'result' => $result,
            'message' => $message,
            'slot' => $slot,
        ], $extra, [
            'http_status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function slotPayload(DeviceScheduleSlot $row): array
    {
        $pins = BlynkScheduleMapper::SLOT_PINS[(int) $row->slot];

        return [
            'slot' => (int) $row->slot,
            'medicine_id' => (int) $row->medicine_id,
            'schedule_id' => (int) $row->schedule_id,
            'assigned_at' => $row->assigned_at?->toIso8601String(),
            'hour_pin' => $pins['hour'],
            'minute_pin' => $pins['minute'],
        ];
    }
}
