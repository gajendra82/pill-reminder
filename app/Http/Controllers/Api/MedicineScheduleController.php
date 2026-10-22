<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicineSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicineScheduleController extends Controller
{
    /**
     * Update the clock time on an existing schedule. Weekday, date, grace,
     * ownership, and slot assignment are not accepted and are not rewritten.
     * This action does not write to Blynk.
     */
    public function update(Request $request, int $schedule): JsonResponse
    {
        $record = MedicineSchedule::query()
            ->whereKey($schedule)
            ->whereHas('medicine', fn ($query) => $query->where('user_id', $request->user()->id))
            ->first();

        if ($record === null) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        foreach ([
            'user_id',
            'medicine_id',
            'id',
            'slot',
            'schedule_id',
            'days_of_week',
            'starts_on',
            'ends_on',
            'grace_period_minutes',
            'is_active',
            'created_at',
            'updated_at',
            'assigned_at',
        ] as $field) {
            if ($request->exists($field)) {
                return response()->json([
                    'message' => 'Only the reminder time can be changed on this schedule.',
                    'errors' => [
                        $field => ['This field is not accepted.'],
                    ],
                ], 422);
            }
        }

        $validated = $request->validate([
            'scheduled_time' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/'],
        ], [
            'scheduled_time.required' => 'A reminder time is required.',
            'scheduled_time.regex' => 'The reminder time must be HH:mm or HH:mm:00.',
        ]);

        $parts = explode(':', $validated['scheduled_time']);
        $record->scheduled_time = sprintf(
            '%02d:%02d:%02d',
            (int) $parts[0],
            (int) $parts[1],
            (int) ($parts[2] ?? 0),
        );
        $record->save();

        return response()->json([
            'message' => 'Schedule timing updated.',
            'schedule' => $this->present($record->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MedicineSchedule $schedule): array
    {
        return [
            'id' => (int) $schedule->id,
            'medicine_id' => (int) $schedule->medicine_id,
            'scheduled_time' => (string) $schedule->getRawOriginal('scheduled_time'),
            'days_of_week' => $schedule->days_of_week,
            'starts_on' => $schedule->starts_on?->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
            'grace_period_minutes' => (int) $schedule->grace_period_minutes,
            'is_active' => (bool) $schedule->getRawOriginal('is_active'),
        ];
    }
}
