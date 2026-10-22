<?php

namespace App\Services;

use App\Models\DeviceScheduleSlot;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit synchronization of one stored slot to Blynk V1–V6.
 * This service never writes V7–V9 and never retries a write.
 */
class DeviceScheduleSyncService
{
    public const WINDOW_MINUTES = 2;

    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * Set for the current synchronize() call. Demo mode skips only the proximity window.
     */
    private bool $demoMode = false;

    public function __construct(
        private readonly BlynkClient $blynk,
        private readonly BlynkScheduleMapper $mapper,
        private readonly DemoActivityService $demoActivity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function synchronize(User $user, int $slot, int $scheduleId, bool $demoMode = false): array
    {
        $this->demoMode = $demoMode;

        if (! Schema::hasTable('device_schedule_slots')) {
            return $this->result('failed', 503, 'slot_assignment_persistence_unavailable', 'The device_schedule_slots table is not available.', $slot, false);
        }

        if (! isset(BlynkScheduleMapper::SLOT_PINS[$slot])) {
            return $this->result('failed', 422, 'invalid_slot', 'Slot must be 1, 2, or 3.', $slot, false);
        }

        $assignment = DeviceScheduleSlot::query()
            ->where('user_id', $user->id)
            ->where('slot', $slot)
            ->first();

        if ($assignment === null) {
            return $this->result('failed', 404, 'slot_not_assigned', 'That slot is not assigned. A released slot is not written as midnight.', $slot, false);
        }

        if ((int) $assignment->schedule_id !== $scheduleId) {
            return $this->result(
                'failed',
                422,
                'schedule_selection_mismatch',
                'The selected schedule is not the schedule stored on this slot. No schedule was substituted.',
                $slot,
                false,
                ['schedule_id' => $scheduleId],
            );
        }

        $selected = ['schedule_id' => $scheduleId];

        $medicine = Medicine::query()
            ->whereKey($assignment->medicine_id)
            ->where('user_id', $user->id)
            ->first();

        if ($medicine === null || ! $medicine->is_active) {
            return $this->result('failed', 422, 'assigned_medicine_inactive', 'The assigned medicine is not active for this user. No pin was written.', $slot, false, $selected);
        }

        $schedule = MedicineSchedule::query()
            ->whereKey($assignment->schedule_id)
            ->where('medicine_id', $medicine->id)
            ->first();

        if ($schedule === null || ! $schedule->is_active) {
            return $this->result('failed', 422, 'schedule_replacement_required', 'The stored schedule is not the active schedule. A different schedule was not substituted.', $slot, false, $selected);
        }

        $activeIds = $medicine->schedules()->where('is_active', true)->orderBy('id')->pluck('id');

        if ($activeIds->count() !== 1 || (int) $activeIds->first() !== (int) $schedule->id) {
            return $this->result('failed', 422, 'unsupported_multiple_schedules_for_medicine', 'This medicine has more than one active schedule. None was sent to the device.', $slot, false, $selected);
        }

        $inspection = $this->mapper->inspectDailySchedule($medicine->id, [
            'id' => $schedule->id,
            'scheduled_time' => $schedule->getRawOriginal('scheduled_time'),
            'days_of_week' => $schedule->days_of_week,
            'starts_on' => $schedule->starts_on?->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
            'grace_period_minutes' => $schedule->getRawOriginal('grace_period_minutes'),
            'is_active' => $schedule->getRawOriginal('is_active'),
        ]);

        if ($inspection['unsupported'] !== []) {
            return $this->result(
                'failed',
                422,
                'unsupported_schedule',
                $inspection['unsupported'][0]['message'] ?? 'This schedule cannot be represented as one daily time.',
                $slot,
                false,
                array_merge($selected, ['unsupported_rules' => $inspection['unsupported']]),
            );
        }

        if ($inspection['time'] === null) {
            return $this->result('failed', 422, 'invalid_schedule_time', 'The schedule time is missing or invalid. An empty slot was not sent as midnight.', $slot, false, $selected);
        }

        $pins = BlynkScheduleMapper::SLOT_PINS[$slot];
        $hourPin = strtolower($pins['hour']);
        $minutePin = strtolower($pins['minute']);
        $newHour = $inspection['time']['hour'];
        $newMinute = $inspection['time']['minute'];
        $selected['hour'] = $newHour;
        $selected['minute'] = $newMinute;

        try {
            $current = $this->blynk->readDatastreams();
        } catch (BlynkClientException $exception) {
            return $this->blynkFailure($exception->errorCode, $slot, 'The current device time could not be read. No pin was written.', $selected);
        }

        $oldHour = $this->integerPin($this->pinValue($current, $hourPin), 23);
        $oldMinute = $this->integerPin($this->pinValue($current, $minutePin), 59);

        if ($oldHour === null || $oldMinute === null) {
            return $this->result('failed', 409, 'current_device_time_unreadable', 'Blynk did not return an hour and minute for this slot. No pin was written.', $slot, false, $selected);
        }

        $now = Carbon::now(self::TIMEZONE);
        $order = $this->writeOrder($oldHour, $oldMinute, $newHour, $newMinute, $now, $this->demoMode);

        if ($order === null) {
            return $this->result(
                'sync_deferred',
                409,
                'sync_deferred',
                'This time is too close to the current India Standard Time minute, or the intermediate pin pair would be. Retry later. The device clock is not known, so this is not a guarantee about the ESP8266 RTC.',
                $slot,
                false,
                array_merge($selected, ['safety' => $this->safety($now, null)]),
            );
        }

        $writes = [
            'hour' => [$hourPin, $newHour, $pins['hour']],
            'minute' => [$minutePin, $newMinute, $pins['minute']],
        ];
        $accepted = [];

        foreach ($order as $part) {
            [$pin, $value, $label] = $writes[$part];
            $write = $this->blynk->updateDatastream($pin, $value);

            if (! $write['accepted']) {
                $observed = $accepted === [] ? null : $this->observe($hourPin, $minutePin);

                return $this->result(
                    $accepted === [] ? 'failed' : 'partial_write',
                    $this->httpStatus($write['error_code'] ?? BlynkClientException::UNAVAILABLE),
                    $accepted === [] ? ($write['error_code'] ?? 'blynk_unavailable') : 'partial_write',
                    $accepted === []
                        ? 'Blynk did not accept the schedule write. No change was confirmed.'
                        : 'Blynk accepted '.$accepted[0].' and then rejected '.$label.'. The device may already have the first value. Retry only after checking the current pin values. No automatic retry or rollback was performed.',
                    $slot,
                    $accepted !== [],
                    array_merge($selected, [
                        'pins_written' => $accepted,
                        'partial' => $accepted !== [],
                        'reconciliation_required' => $accepted !== [],
                        'observed' => $observed,
                        'safety' => $this->safety($now, array_map(fn (string $name) => $writes[$name][2], $order)),
                        'assumptions' => $inspection['assumptions'],
                        'laravel_only_rules' => $inspection['laravel_only'],
                    ]),
                );
            }

            $accepted[] = $label;
        }

        try {
            $stored = $this->blynk->readDatastreams();
        } catch (BlynkClientException) {
            return $this->result(
                'unconfirmed',
                502,
                'read_back_unavailable',
                'Both writes returned HTTP 200, but the stored hour and minute could not be read back. Do not treat this slot as synchronized.',
                $slot,
                true,
                array_merge($selected, [
                    'pins_written' => $accepted,
                    'reconciliation_required' => true,
                    'safety' => $this->safety($now, array_map(fn (string $name) => $writes[$name][2], $order)),
                ]),
            );
        }

        $readHour = $this->integerPin($this->pinValue($stored, $hourPin), 23);
        $readMinute = $this->integerPin($this->pinValue($stored, $minutePin), 59);

        if ($readHour !== $newHour || $readMinute !== $newMinute) {
            return $this->result(
                'unconfirmed',
                409,
                'read_back_mismatch',
                'Both writes returned HTTP 200, but Blynk did not return the intended hour and minute. Do not treat this slot as synchronized.',
                $slot,
                true,
                array_merge($selected, [
                    'pins_written' => $accepted,
                    'read_back' => ['hour' => $readHour, 'minute' => $readMinute],
                    'reconciliation_required' => true,
                    'safety' => $this->safety($now, array_map(fn (string $name) => $writes[$name][2], $order)),
                ]),
            );
        }

        $verified = [
            'hour_pin' => $pins['hour'],
            'minute_pin' => $pins['minute'],
            'hour' => $newHour,
            'minute' => $newMinute,
            'verified_hour' => $readHour,
            'verified_minute' => $readMinute,
            'pins_written' => $accepted,
            'read_back' => [
                'hour' => $readHour,
                'minute' => $readMinute,
                'verified_hour' => $readHour,
                'verified_minute' => $readMinute,
            ],
            'assumptions' => $inspection['assumptions'],
            'laravel_only_rules' => $inspection['laravel_only'],
            'safety' => $this->safety($now, array_map(fn (string $name) => $writes[$name][2], $order)),
        ];

        if ($this->demoMode) {
            $verified = array_merge($verified, $this->demoActivity->recordVerifiedSync($schedule, $slot, $newHour, $newMinute));
        }

        return $this->result(
            'synced',
            200,
            'synced',
            'Slot '.$slot.' was synchronized. Blynk returned hour '.$newHour.' and minute '.$newMinute.'.',
            $slot,
            true,
            array_merge($selected, $verified),
        );
    }

    /**
     * @return list<string>|null
     */
    private function writeOrder(int $oldHour, int $oldMinute, int $newHour, int $newMinute, Carbon $now, bool $demoMode): ?array
    {
        if (! $demoMode && ($this->tooClose($oldHour, $oldMinute, $now) || $this->tooClose($newHour, $newMinute, $now))) {
            return null;
        }

        $candidates = [
            ['minute', 'hour', $oldHour, $newMinute],
            ['hour', 'minute', $newHour, $oldMinute],
        ];
        $best = null;
        $bestDistance = -1;

        foreach ($candidates as [$first, $second, $intermediateHour, $intermediateMinute]) {
            if (! $demoMode && $this->tooClose($intermediateHour, $intermediateMinute, $now)) {
                continue;
            }

            $distance = $this->minutesFromNow($intermediateHour, $intermediateMinute, $now);

            if ($distance > $bestDistance) {
                $bestDistance = $distance;
                $best = [$first, $second];
            }
        }

        return $best;
    }

    private function tooClose(int $hour, int $minute, Carbon $now): bool
    {
        return $this->minutesFromNow($hour, $minute, $now) <= self::WINDOW_MINUTES;
    }

    private function minutesFromNow(int $hour, int $minute, Carbon $now): int
    {
        $diff = abs(($now->hour * 60 + $now->minute) - ($hour * 60 + $minute));

        return min($diff, 1440 - $diff);
    }

    /**
     * @return array{hour: int|null, minute: int|null}|null
     */
    private function observe(string $hourPin, string $minutePin): ?array
    {
        try {
            $stored = $this->blynk->readDatastreams();
        } catch (BlynkClientException) {
            return null;
        }

        return [
            'hour' => $this->integerPin($this->pinValue($stored, $hourPin), 23),
            'minute' => $this->integerPin($this->pinValue($stored, $minutePin), 59),
        ];
    }

    /**
     * @param  array<string, mixed>  $datastreams
     */
    private function pinValue(array $datastreams, string $pin): mixed
    {
        foreach ($datastreams as $key => $value) {
            if (strcasecmp((string) $key, $pin) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function integerPin(mixed $value, int $max): ?int
    {
        if (is_int($value) && $value >= 0 && $value <= $max) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', trim($value)) === 1) {
            $parsed = (int) $value;

            return $parsed <= $max ? $parsed : null;
        }

        return null;
    }

    /**
     * @param  list<string>|null  $order
     * @return array<string, mixed>
     */
    private function safety(Carbon $now, ?array $order): array
    {
        return [
            'timezone' => self::TIMEZONE,
            'evaluated_at' => $now->toIso8601String(),
            'window_minutes' => self::WINDOW_MINUTES,
            'demo_mode' => $this->demoMode,
            'proximity_window' => $this->demoMode ? 'bypassed' : 'enforced',
            'device_clock_known' => false,
            'write_order' => $order,
            'limitation' => 'The safety window uses India Standard Time, not the DS1307 clock. Pin order does not make an intermediate time collision-free.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function blynkFailure(string $errorCode, int $slot, string $message, array $extra = []): array
    {
        return $this->result('failed', $this->httpStatus($errorCode), $errorCode, $message, $slot, false, $extra);
    }

    private function httpStatus(string $errorCode): int
    {
        return match ($errorCode) {
            BlynkClientException::NOT_CONFIGURED => 503,
            BlynkClientException::RATE_LIMITED => 429,
            BlynkClientException::CONNECTION_FAILED => 504,
            default => 502,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(
        string $result,
        int $status,
        string $code,
        string $message,
        int $slot,
        bool $writesPerformed,
        array $extra = [],
    ): array {
        Log::info('device_schedule_sync', [
            'result' => $result,
            'code' => $code,
            'slot' => $slot,
            'writes_performed' => $writesPerformed,
            'partial' => $extra['partial'] ?? false,
            'demo_mode' => $this->demoMode,
        ]);

        return array_merge([
            'result' => $result,
            'code' => $code,
            'message' => $message,
            'slot' => $slot,
            'writes_performed' => $writesPerformed,
            'partial' => false,
            'reconciliation_required' => false,
        ], $extra, [
            'demo_mode' => $this->demoMode,
            'timestamp' => Carbon::now('UTC')->toIso8601String(),
            'http_status' => $status,
        ]);
    }
}
