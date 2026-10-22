<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Dry-run proposal for the three physical ESP8266 reminder slots.
 *
 * When device_schedule_slots is available, only stored assignments are proposed.
 * Medicines are not ranked by id. When the table is missing, no pin map is
 * proposed. This class never calls Blynk.
 */
class BlynkScheduleMapper
{
    public const DEFAULT_GRACE_PERIOD_MINUTES = 60;

    public const ERROR_MULTIPLE = 'unsupported_multiple_schedules_for_medicine';

    public const ERROR_TOO_MANY = 'too_many_medicines';

    public const ERROR_MISSING = 'missing_schedules';

    public const ERROR_INVALID_TIME = 'invalid_schedule_time';

    public const ERROR_DUPLICATE_TIME = 'duplicate_scheduled_time';

    public const ERROR_PERSISTENCE_UNAVAILABLE = 'slot_assignment_persistence_unavailable';

    public const ERROR_NO_ASSIGNMENTS = 'no_slot_assignments';

    public const ERROR_MEDICINE_INACTIVE = 'assigned_medicine_inactive';

    public const ERROR_SCHEDULE_REPLACEMENT = 'schedule_replacement_required';

    public const ERROR_MEDICINE_MISSING = 'assigned_medicine_missing';

    public const ERROR_SCHEDULE_MISSING = 'assigned_schedule_missing';

    /**
     * @var array<int, array{hour: string, minute: string}>
     */
    /**
     * Verified in sketch_oct21a.ino. This does not prove the physical device is running that sketch.
     */
    public const SLOT_PINS = [
        1 => ['hour' => 'V1', 'minute' => 'V2'],
        2 => ['hour' => 'V3', 'minute' => 'V4'],
        3 => ['hour' => 'V5', 'minute' => 'V6'],
    ];

    public const ASSIGNMENT_PERSISTENT = 'Stored device_schedule_slots row. The slot number is the physical reminder. It is not the medicine id and it is not the schedule id.';

    public const ASSIGNMENT_UNAVAILABLE = 'Persistent slot assignments are required before a physical slot can be proposed.';

    public const LIMITATION_PERSISTENT = 'Occupied slots stay in place. A medicine is not placed on a physical slot until an explicit assignment, which uses the lowest free slot. Deleting or deactivating a medicine does not move the others and does not clear its slot.';

    public const LIMITATION_UNAVAILABLE = 'The device_schedule_slots table is not available. This dry run does not rank medicines by id and does not propose pins that would move a physical reminder.';

    /**
     * @param  list<array<string, mixed>>  $medicines
     * @param  list<array{slot: int, medicine_id: int, schedule_id: int}>  $assignments
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $schedule
     * @return array{time: array{hour: int, minute: int}|null, unsupported: list<array<string, mixed>>, assumptions: list<array<string, mixed>>, laravel_only: list<array<string, mixed>>}
     */
    public function inspectDailySchedule(int|string $medicineId, array $schedule): array
    {
        $evaluation = $this->evaluateSchedule($medicineId, $schedule);
        $parsed = $this->parseTime($schedule['scheduled_time'] ?? null);
        $time = null;

        if ($parsed !== null && $parsed['hour'] <= 23 && $parsed['minute'] <= 59 && $parsed['seconds'] === 0) {
            $time = ['hour' => $parsed['hour'], 'minute' => $parsed['minute']];
        }

        return [
            'time' => $time,
            'unsupported' => $evaluation['unsupported'],
            'assumptions' => $evaluation['assumptions'],
            'laravel_only' => $evaluation['laravel_only'],
        ];
    }

    public function propose(array $medicines, bool $persistenceAvailable, array $assignments = []): array
    {
        if (! $persistenceAvailable) {
            return $this->report(
                [[
                    'code' => self::ERROR_PERSISTENCE_UNAVAILABLE,
                    'blocking' => true,
                    'message' => 'Slot assignments are not stored, so no physical schedule was proposed.',
                ]],
                [],
                [],
                [],
                [],
                [],
                false,
            );
        }

        $validationErrors = [];
        $unsupported = [];
        $assumptions = [];
        $laravelOnly = [];
        $unmapped = [];
        $plans = [];

        $byMedicine = [];

        foreach ($medicines as $medicine) {
            $byMedicine[(int) $medicine['id']] = $medicine;
        }

        if (count($assignments) > 3) {
            $validationErrors[] = [
                'code' => self::ERROR_TOO_MANY,
                'blocking' => true,
                'active_medicine_count' => count($assignments),
                'medicines' => array_map(fn (array $assignment) => [
                    'medicine_id' => $assignment['medicine_id'],
                    'schedule_id' => $assignment['schedule_id'],
                    'slot' => $assignment['slot'],
                ], $assignments),
            ];

            return $this->report($validationErrors, $unsupported, $assumptions, $laravelOnly, $unmapped, [], true);
        }

        $assignedMedicineIds = [];

        foreach ($assignments as $assignment) {
            $slot = (int) $assignment['slot'];
            $medicineId = (int) $assignment['medicine_id'];
            $scheduleId = (int) $assignment['schedule_id'];
            $assignedMedicineIds[$medicineId] = true;

            if (! isset(self::SLOT_PINS[$slot]) || isset($plans[$slot])) {
                $validationErrors[] = [
                    'code' => 'invalid_slot',
                    'blocking' => true,
                    'slot' => $slot,
                    'medicine_id' => $medicineId,
                    'schedule_id' => $scheduleId,
                ];

                continue;
            }

            $medicine = $byMedicine[$medicineId] ?? null;

            if ($medicine === null) {
                $validationErrors[] = [
                    'code' => self::ERROR_MEDICINE_MISSING,
                    'blocking' => true,
                    'slot' => $slot,
                    'medicine_id' => $medicineId,
                    'schedule_id' => $scheduleId,
                ];
                $plans[$slot] = $this->heldPlan($medicineId, null, $scheduleId, null);

                continue;
            }

            $stored = null;

            foreach ($medicine['schedules'] ?? [] as $schedule) {
                if ((int) ($schedule['id'] ?? 0) === $scheduleId) {
                    $stored = $schedule;
                    break;
                }
            }

            if ($stored === null) {
                $validationErrors[] = [
                    'code' => self::ERROR_SCHEDULE_MISSING,
                    'blocking' => true,
                    'slot' => $slot,
                    'medicine_id' => $medicineId,
                    'medicine_name' => $medicine['name'] ?? null,
                    'schedule_id' => $scheduleId,
                ];
                $plans[$slot] = $this->heldPlan($medicineId, $medicine['name'] ?? null, $scheduleId, null);

                continue;
            }

            $activeSchedules = [];

            foreach ($medicine['schedules'] ?? [] as $schedule) {
                if (! $this->isActive($medicine)) {
                    $unmapped[] = $this->unmapped($medicineId, $schedule, 'medicine_inactive');

                    continue;
                }

                if (! $this->isActive($schedule)) {
                    $unmapped[] = $this->unmapped($medicineId, $schedule, 'inactive');

                    continue;
                }

                $activeSchedules[] = $schedule;
            }

            $time = $this->parseTime($stored['scheduled_time'] ?? null);
            $timeValid = $time !== null && $time['hour'] <= 23 && $time['minute'] <= 59;
            $clock = $timeValid ? sprintf('%02d:%02d', $time['hour'], $time['minute']) : null;

            if (! $this->isActive($medicine)) {
                $validationErrors[] = [
                    'code' => self::ERROR_MEDICINE_INACTIVE,
                    'blocking' => true,
                    'slot' => $slot,
                    'medicine_id' => $medicineId,
                    'medicine_name' => $medicine['name'] ?? null,
                    'schedule_id' => $scheduleId,
                ];
                $plans[$slot] = $this->heldPlan($medicineId, $medicine['name'] ?? null, $scheduleId, $clock);

                continue;
            }

            $evaluation = $this->evaluateSchedule($medicineId, $stored);
            $unsupported = array_merge($unsupported, $evaluation['unsupported']);
            $assumptions = array_merge($assumptions, $evaluation['assumptions']);
            $laravelOnly = array_merge($laravelOnly, $evaluation['laravel_only']);

            if (count($activeSchedules) > 1) {
                $validationErrors[] = [
                    'code' => self::ERROR_MULTIPLE,
                    'blocking' => true,
                    'medicine_id' => $medicineId,
                    'medicine_name' => $medicine['name'] ?? null,
                    'schedule_ids' => array_map(fn (array $schedule) => $schedule['id'] ?? null, $activeSchedules),
                ];
                $plans[$slot] = $this->heldPlan($medicineId, $medicine['name'] ?? null, $scheduleId, $clock);

                continue;
            }

            $storedIsOnlyActive = count($activeSchedules) === 1
                && (int) ($activeSchedules[0]['id'] ?? 0) === $scheduleId;

            if (! $storedIsOnlyActive) {
                $validationErrors[] = [
                    'code' => self::ERROR_SCHEDULE_REPLACEMENT,
                    'blocking' => true,
                    'slot' => $slot,
                    'medicine_id' => $medicineId,
                    'medicine_name' => $medicine['name'] ?? null,
                    'schedule_id' => $scheduleId,
                    'message' => 'The stored schedule is no longer the single active schedule. A different schedule was not substituted.',
                ];
                $plans[$slot] = $this->heldPlan($medicineId, $medicine['name'] ?? null, $scheduleId, $clock);

                continue;
            }

            if (! $timeValid) {
                $validationErrors[] = [
                    'code' => self::ERROR_INVALID_TIME,
                    'blocking' => true,
                    'medicine_id' => $medicineId,
                    'medicine_name' => $medicine['name'] ?? null,
                    'schedule_id' => $scheduleId,
                    'scheduled_time' => $this->scalarString($stored['scheduled_time'] ?? null),
                ];
                $plans[$slot] = $this->heldPlan($medicineId, $medicine['name'] ?? null, $scheduleId, null);

                continue;
            }

            $plans[$slot] = [
                'status' => 'proposed',
                'medicine_id' => $medicineId,
                'medicine_name' => $medicine['name'] ?? null,
                'schedule_id' => $scheduleId,
                'scheduled_time' => $clock,
                'hour' => $time['hour'],
                'minute' => $time['minute'],
            ];
        }

        foreach ($medicines as $medicine) {
            $medicineId = (int) $medicine['id'];

            if (isset($assignedMedicineIds[$medicineId])) {
                continue;
            }

            $activeSchedules = [];

            foreach ($medicine['schedules'] ?? [] as $schedule) {
                if (! $this->isActive($medicine)) {
                    $unmapped[] = $this->unmapped($medicineId, $schedule, 'medicine_inactive');

                    continue;
                }

                if (! $this->isActive($schedule)) {
                    $unmapped[] = $this->unmapped($medicineId, $schedule, 'inactive');

                    continue;
                }

                $activeSchedules[] = $schedule;
            }

            if ($activeSchedules === []) {
                continue;
            }

            foreach ($activeSchedules as $schedule) {
                $unmapped[] = $this->unmapped($medicineId, $schedule, 'not_assigned');
            }

            if (count($activeSchedules) > 1) {
                $validationErrors[] = [
                    'code' => self::ERROR_MULTIPLE,
                    'blocking' => true,
                    'medicine_id' => $medicineId,
                    'medicine_name' => $medicine['name'] ?? null,
                    'schedule_ids' => array_map(fn (array $schedule) => $schedule['id'] ?? null, $activeSchedules),
                ];
            }
        }

        $proposed = array_filter($plans, fn (array $plan) => $plan['status'] === 'proposed');
        $byTime = [];

        foreach ($proposed as $plan) {
            $byTime[$plan['scheduled_time']][] = $plan;
        }

        foreach ($byTime as $clock => $items) {
            if (count($items) < 2) {
                continue;
            }

            $validationErrors[] = [
                'code' => self::ERROR_DUPLICATE_TIME,
                'blocking' => true,
                'scheduled_time' => $clock,
                'medicine_ids' => array_map(fn (array $item) => $item['medicine_id'], $items),
                'schedule_ids' => array_map(fn (array $item) => $item['schedule_id'], $items),
            ];
        }

        $hasActiveSchedule = false;

        foreach ($medicines as $medicine) {
            if (! $this->isActive($medicine)) {
                continue;
            }

            foreach ($medicine['schedules'] ?? [] as $schedule) {
                if ($this->isActive($schedule)) {
                    $hasActiveSchedule = true;
                    break 2;
                }
            }
        }

        if ($plans === [] && ! $hasActiveSchedule) {
            $validationErrors[] = [
                'code' => self::ERROR_MISSING,
                'blocking' => true,
            ];
        } elseif ($proposed === [] && $plans === []) {
            $validationErrors[] = [
                'code' => self::ERROR_NO_ASSIGNMENTS,
                'blocking' => true,
                'message' => 'No medicine is assigned to a physical slot.',
            ];
        }

        return $this->report($validationErrors, $unsupported, $assumptions, $laravelOnly, $unmapped, $plans, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function heldPlan(int $medicineId, mixed $name, int $scheduleId, ?string $clock): array
    {
        return [
            'status' => 'held',
            'medicine_id' => $medicineId,
            'medicine_name' => $name,
            'schedule_id' => $scheduleId,
            'scheduled_time' => $clock,
            'hour' => null,
            'minute' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $validationErrors
     * @param  list<array<string, mixed>>  $unsupported
     * @param  list<array<string, mixed>>  $assumptions
     * @param  list<array<string, mixed>>  $laravelOnly
     * @param  list<array<string, mixed>>  $unmapped
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    private function report(
        array $validationErrors,
        array $unsupported,
        array $assumptions,
        array $laravelOnly,
        array $unmapped,
        array $plans,
        bool $persistent,
    ): array {
        $blocking = array_values(array_filter(
            $validationErrors,
            fn (array $error) => ($error['blocking'] ?? true) === true
        ));

        $datastreams = $this->emptyDatastreams();
        $slots = [];

        foreach (self::SLOT_PINS as $slotNumber => $pins) {
            $plan = $plans[$slotNumber] ?? null;

            if ($plan === null) {
                $slots[] = [
                    'slot' => $slotNumber,
                    'status' => 'unused',
                    'medicine_id' => null,
                    'medicine_name' => null,
                    'schedule_id' => null,
                    'scheduled_time' => null,
                    'hour_pin' => $pins['hour'],
                    'minute_pin' => $pins['minute'],
                ];

                continue;
            }

            if ($plan['status'] === 'proposed') {
                $datastreams[$pins['hour']] = $plan['hour'];
                $datastreams[$pins['minute']] = $plan['minute'];
            }

            $slots[] = [
                'slot' => $slotNumber,
                'status' => $plan['status'],
                'medicine_id' => $plan['medicine_id'],
                'medicine_name' => $plan['medicine_name'],
                'schedule_id' => $plan['schedule_id'],
                'scheduled_time' => $plan['scheduled_time'],
                'hour_pin' => $pins['hour'],
                'minute_pin' => $pins['minute'],
            ];
        }

        $canApply = $persistent
            && $blocking === []
            && $unsupported === []
            && array_filter($slots, fn (array $slot) => $slot['status'] === 'proposed') !== [];

        return [
            'sync_mode' => 'dry_run',
            'writes_performed' => false,
            'can_apply' => $canApply,
            'slot_assignment' => $persistent ? self::ASSIGNMENT_PERSISTENT : self::ASSIGNMENT_UNAVAILABLE,
            'slot_assignment_persistent' => $persistent,
            'slot_stability' => $persistent ? 'persistent' : 'unavailable',
            'slot_assignment_status' => $persistent ? 'persistent' : self::ERROR_PERSISTENCE_UNAVAILABLE,
            'slot_limitation' => $persistent ? self::LIMITATION_PERSISTENT : self::LIMITATION_UNAVAILABLE,
            'evaluation' => [
                'date' => Carbon::now($this->timezone())->toDateString(),
                'timezone' => $this->timezone(),
                'device_timezone_verified' => false,
            ],
            'slots' => $slots,
            'proposed_datastreams' => $datastreams,
            'validation_errors' => $validationErrors,
            'unsupported_rules' => $unsupported,
            'assumptions' => $assumptions,
            'laravel_only_rules' => $laravelOnly,
            'unmapped_schedules' => $unmapped,
            'notice' => 'Dry run only. No Blynk datastream was written. V7, V8, and V9 are status pins and are not schedule slots. Unused slots are null because 0 is a valid hour or minute. A grace period is Laravel-only and is not applied by the firmware. Physical slots come only from stored assignments.',
        ];
    }

    /**
     * @return array<string, null>
     */
    private function emptyDatastreams(): array
    {
        return [
            'V1' => null,
            'V2' => null,
            'V3' => null,
            'V4' => null,
            'V5' => null,
            'V6' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{medicine_id: int, schedule_id: mixed, reason: string}
     */
    private function unmapped(int $medicineId, array $schedule, string $reason): array
    {
        return [
            'medicine_id' => $medicineId,
            'schedule_id' => $schedule['id'] ?? null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{unsupported: list<array<string, mixed>>, assumptions: list<array<string, mixed>>, laravel_only: list<array<string, mixed>>}
     */
    private function evaluateSchedule(int|string $medicineId, array $schedule): array
    {
        $unsupported = [];
        $assumptions = [];
        $laravelOnly = [];
        $evaluatedOn = Carbon::now($this->timezone())->toDateString();

        $addUnsupported = function (string $rule, mixed $value, string $message) use (&$unsupported, $medicineId, $schedule): void {
            $unsupported[] = [
                'medicine_id' => $medicineId,
                'schedule_id' => $schedule['id'] ?? null,
                'rule' => $rule,
                'value' => $value,
                'message' => $message,
            ];
        };

        if ($this->present($schedule, 'days_of_week') && ! $this->coversEveryIsoWeekday($schedule['days_of_week'])) {
            $addUnsupported(
                'days_of_week',
                $schedule['days_of_week'],
                'The firmware rings every day. A weekday list would change which days the reminder runs.',
            );
        }

        if ($this->present($schedule, 'ends_on')) {
            $addUnsupported(
                'ends_on',
                $this->scalarString($schedule['ends_on']),
                'The firmware would keep ringing after this end date.',
            );
        }

        if ($this->present($schedule, 'starts_on')) {
            $startsOn = $this->dateString($schedule['starts_on']);

            if ($startsOn === null) {
                $addUnsupported('starts_on', $this->scalarString($schedule['starts_on']), 'The start date could not be read.');
            } elseif ($startsOn > $evaluatedOn) {
                $addUnsupported(
                    'starts_on',
                    $startsOn,
                    'The start date is after the evaluation date. The firmware would ring before this medicine should start.',
                );
                $assumptions[] = $this->startAssumption($medicineId, $schedule, $startsOn, $evaluatedOn, false);
            } else {
                $assumptions[] = $this->startAssumption($medicineId, $schedule, $startsOn, $evaluatedOn, true);
            }
        }

        if ($this->present($schedule, 'grace_period_minutes')) {
            $minutes = (int) $schedule['grace_period_minutes'];
            $laravelOnly[] = [
                'medicine_id' => $medicineId,
                'schedule_id' => $schedule['id'] ?? null,
                'rule' => 'grace_period_minutes',
                'value' => $minutes,
                'is_api_default' => $minutes === self::DEFAULT_GRACE_PERIOD_MINUTES,
                'applied_by_firmware' => false,
                'message' => 'The grace period stays in Laravel. The ESP8266 stores only the hour and minute and does not apply a grace window.',
            ];
        }

        $time = $this->parseTime($schedule['scheduled_time'] ?? null);

        if ($time !== null && $time['seconds'] !== 0) {
            $addUnsupported(
                'seconds',
                $time['seconds'],
                'The firmware stores hour and minute only.',
            );
        }

        return [
            'unsupported' => $unsupported,
            'assumptions' => $assumptions,
            'laravel_only' => $laravelOnly,
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>
     */
    private function startAssumption(
        int|string $medicineId,
        array $schedule,
        string $startsOn,
        string $evaluatedOn,
        bool $accepted,
    ): array {
        return [
            'code' => 'start_date_daily_reminder',
            'accepted' => $accepted,
            'medicine_id' => $medicineId,
            'schedule_id' => $schedule['id'] ?? null,
            'starts_on' => $startsOn,
            'evaluated_on' => $evaluatedOn,
            'timezone' => $this->timezone(),
            'device_timezone_verified' => false,
            'message' => $accepted
                ? 'The start date is on or before the evaluation date and the medicine is active, so a daily reminder matches a schedule that has already started. This is valid only when the DS1307 local date is the same as the evaluation date.'
                : 'The start date is after the evaluation date, so a daily reminder would start too early.',
        ];
    }

    /**
     * Null stays the stored form of “every day”. The complete ISO set is the same
     * daily schedule for the device and is not rewritten in the database.
     */
    private function coversEveryIsoWeekday(mixed $days): bool
    {
        if (! is_array($days)) {
            return false;
        }

        $selected = [];

        foreach ($days as $day) {
            if (is_int($day) || (is_string($day) && preg_match('/^[1-7]$/', $day) === 1)) {
                $selected[(int) $day] = true;

                continue;
            }

            return false;
        }

        $week = array_keys($selected);
        sort($week);

        return $week === [1, 2, 3, 4, 5, 6, 7];
    }

    /**
     * Schedule calendar dates are Asia/Kolkata dates. The application timezone stays UTC.
     */
    private function timezone(): string
    {
        return DoseOccurrenceCalculator::TIMEZONE;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))
                ->timezone($this->timezone())
                ->toDateString();
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return null;
        }

        $value = trim($value);
        $parsed = Carbon::createFromFormat('Y-m-d', $value, $this->timezone());

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function present(array $schedule, string $key): bool
    {
        if (! array_key_exists($key, $schedule) || $schedule[$key] === null) {
            return false;
        }

        if (is_string($schedule[$key]) && trim($schedule[$key]) === '') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isActive(array $row): bool
    {
        if (! array_key_exists('is_active', $row) || $row['is_active'] === null || $row['is_active'] === '') {
            return true;
        }

        $value = $row['is_active'];

        if (is_string($value)) {
            return ! in_array(strtolower($value), ['0', 'false', 'off'], true);
        }

        return (bool) $value;
    }

    /**
     * @return array{hour: int, minute: int, seconds: int}|null
     */
    private function parseTime(mixed $value): ?array
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return [
            'hour' => (int) $matches[1],
            'minute' => (int) $matches[2],
            'seconds' => isset($matches[3]) ? (int) $matches[3] : 0,
        ];
    }

    private function scalarString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
