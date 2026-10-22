<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoActivityEvent;
use App\Models\DoseEvent;
use App\Services\DemoActivityService;
use App\Services\DoseEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DoseEventController extends Controller
{
    public function index(Request $request, DoseEventService $events, DemoActivityService $demo): JsonResponse
    {
        $validated = $request->validate([
            'activity' => ['sometimes', 'in:real,demo,all'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'string', Rule::in(array_merge(DoseEvent::STATUSES, DemoActivityEvent::TYPES))],
            'medicine_schedule_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if (isset($validated['from'], $validated['to']) && $validated['from'] > $validated['to']) {
            return response()->json([
                'message' => 'The from date must be on or before the to date.',
                'errors' => [
                    'from' => ['The from date must be on or before the to date.'],
                ],
            ], 422);
        }

        $activity = $validated['activity'] ?? 'real';

        if ($activity === 'real' && isset($validated['status']) && ! in_array($validated['status'], DoseEvent::STATUSES, true)) {
            return response()->json([
                'message' => 'The selected status is not a real dose status.',
                'errors' => [
                    'status' => ['The selected status is not a real dose status.'],
                ],
            ], 422);
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = match ($activity) {
            'demo' => $demo->history($request->user(), $validated, $perPage),
            'all' => $demo->combinedHistory($request->user(), $validated, $perPage, $events),
            default => $events->history($request->user(), $validated, $perPage),
        };

        if ($activity === 'real') {
            return response()->json($page);
        }

        $payload = $page->toArray();
        $payload['demo_activity_available'] = $demo->storageAvailable();

        return response()->json($payload);
    }

    public function store(Request $request, DoseEventService $events): JsonResponse
    {
        foreach ([
            'user_id',
            'medicine_id',
            'scheduled_for',
            'confirmed_taken_at',
            'missed_at',
            'sensor_detected_at',
            'reminder_triggered_at',
            'confirmation_method',
            'metadata',
        ] as $field) {
            if ($request->exists($field)) {
                return response()->json([
                    'message' => 'Dose events use the authenticated user and the server clock.',
                    'errors' => [
                        $field => ['This field is not accepted.'],
                    ],
                ], 422);
            }
        }

        $validated = $request->validate([
            'medicine_schedule_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(DoseEvent::STATUSES)],
        ]);

        if (! in_array($validated['status'], DoseEvent::USER_RECORDABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This endpoint records a manual user action. Reminder and sensor statuses are not accepted.',
                'errors' => [
                    'status' => ['The selected status is not a manual dose action.'],
                ],
            ], 422);
        }

        $result = $events->record(
            $request->user(),
            (int) $validated['medicine_schedule_id'],
            $validated['status'],
        );

        return response()->json($result['body'], $result['http_status']);
    }

    public function show(Request $request, DoseEvent $doseEvent, DoseEventService $events): JsonResponse
    {
        $result = $events->show($request->user(), $doseEvent);

        return response()->json($result['body'], $result['http_status']);
    }
}
