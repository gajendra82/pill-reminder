<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use Illuminate\Http\Request;

class MedicineController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()
                ->medicines()
                ->with('schedules')
                ->latest()
                ->paginate(20)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'dosage_label' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'schedules' => ['required', 'array', 'min:1', 'max:10'],
            'schedules.*.scheduled_time' => ['required', 'date_format:H:i'],
            'schedules.*.days_of_week' => ['nullable', 'array'],
            'schedules.*.days_of_week.*' => ['integer', 'between:1,7'],
            'schedules.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'schedules.*.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'schedules.*.grace_period_minutes' => ['sometimes', 'integer', 'between:5,360'],
        ]);

        $medicine = $request->user()->medicines()->create([
            'name' => $validated['name'],
            'dosage_label' => $validated['dosage_label'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
        ]);

        foreach ($validated['schedules'] as $schedule) {
            $medicine->schedules()->create([
                'scheduled_time' => $schedule['scheduled_time'],
                'days_of_week' => $schedule['days_of_week'] ?? null,
                'starts_on' => $schedule['starts_on'],
                'ends_on' => $schedule['ends_on'] ?? null,
                'grace_period_minutes' => $schedule['grace_period_minutes'] ?? 60,
            ]);
        }

        return response()->json([
            'message' => 'Medicine created successfully.',
            'medicine' => $medicine->load('schedules'),
        ], 201);
    }

    public function show(Request $request, Medicine $medicine)
    {
        abort_unless($medicine->user_id === $request->user()->id, 404);

        return response()->json($medicine->load('schedules'));
    }

    public function destroy(Request $request, Medicine $medicine)
    {
        abort_unless($medicine->user_id === $request->user()->id, 404);

        $medicine->delete();

        return response()->json([
            'message' => 'Medicine deleted successfully.',
        ]);
    }
}
