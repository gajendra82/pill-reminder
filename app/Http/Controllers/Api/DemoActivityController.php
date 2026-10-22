<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoActivityEvent;
use App\Services\DemoActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoActivityController extends Controller
{
    public function storeTaken(Request $request, DemoActivityService $demo): JsonResponse
    {
        foreach ([
            'user_id',
            'medicine_id',
            'scheduled_for',
            'occurred_at',
            'confirmed_taken_at',
            'missed_at',
            'reminder_triggered_at',
            'sensor_detected_at',
            'confirmation_method',
            'event_type',
            'status',
            'metadata',
        ] as $field) {
            if ($request->exists($field)) {
                return response()->json([
                    'message' => 'A demo taken confirmation uses the authenticated user and the server clock.',
                    'errors' => [
                        $field => ['This field is not accepted.'],
                    ],
                ], 422);
            }
        }

        $validated = $request->validate([
            'medicine_schedule_id' => ['required', 'integer', 'min:1'],
            'demo_mode' => ['required', 'boolean', 'accepted'],
        ], [
            'demo_mode.required' => 'Demo mode must be turned on explicitly.',
            'demo_mode.accepted' => 'Demo mode must be turned on explicitly.',
        ]);

        $result = $demo->recordTaken($request->user(), (int) $validated['medicine_schedule_id']);

        return response()->json($result['body'], $result['http_status']);
    }

    public function show(Request $request, DemoActivityEvent $demoActivityEvent, DemoActivityService $demo): JsonResponse
    {
        $result = $demo->show($request->user(), $demoActivityEvent);

        return response()->json($result['body'], $result['http_status']);
    }
}
