<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Services\BlynkScheduleMapper;
use App\Services\SlotAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleSyncDryRunController extends Controller
{
    public function __invoke(Request $request, BlynkScheduleMapper $mapper, SlotAssignmentService $assignments): JsonResponse
    {
        $medicines = $request->user()
            ->medicines()
            ->with('schedules')
            ->orderBy('id')
            ->get()
            ->map(fn (Medicine $medicine) => [
                'id' => $medicine->id,
                'name' => $medicine->name,
                'is_active' => $medicine->getRawOriginal('is_active'),
                'schedules' => $medicine->schedules
                    ->sortBy('id')
                    ->values()
                    ->map(fn (MedicineSchedule $schedule) => [
                        'id' => $schedule->id,
                        'medicine_id' => $schedule->medicine_id,
                        'scheduled_time' => $schedule->getRawOriginal('scheduled_time'),
                        'days_of_week' => $schedule->days_of_week,
                        'starts_on' => $schedule->starts_on?->toDateString(),
                        'ends_on' => $schedule->ends_on?->toDateString(),
                        'grace_period_minutes' => $schedule->getRawOriginal('grace_period_minutes'),
                        'is_active' => $schedule->getRawOriginal('is_active'),
                    ])
                    ->all(),
            ])
            ->all();

        $available = $assignments->persistenceAvailable();

        return response()->json($mapper->propose(
            $medicines,
            $available,
            $available ? $assignments->assignmentsFor($request->user()) : [],
        ));
    }
}
