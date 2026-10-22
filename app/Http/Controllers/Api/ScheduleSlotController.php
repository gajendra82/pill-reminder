<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlotAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleSlotController extends Controller
{
    public function index(Request $request, SlotAssignmentService $assignments): JsonResponse
    {
        if ($denied = $this->rejectClientUserId($request)) {
            return $denied;
        }

        if (! $assignments->persistenceAvailable()) {
            return $this->unavailable();
        }

        return response()->json([
            'slot_assignment_persistent' => true,
            'slot_stability' => 'persistent',
            'slots' => $assignments->listFor($request->user()),
        ]);
    }

    public function store(Request $request, SlotAssignmentService $assignments): JsonResponse
    {
        if ($denied = $this->rejectClientUserId($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'medicine_id' => ['required', 'integer', 'min:1'],
            'schedule_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $scheduleId = array_key_exists('schedule_id', $validated) && $validated['schedule_id'] !== null
            ? (int) $validated['schedule_id']
            : null;

        return $this->respond($assignments->assign($request->user(), (int) $validated['medicine_id'], $scheduleId));
    }

    public function release(Request $request, SlotAssignmentService $assignments, int $slot): JsonResponse
    {
        if ($denied = $this->rejectClientUserId($request)) {
            return $denied;
        }

        return $this->respond($assignments->release($request->user(), $slot));
    }

    public function replace(Request $request, SlotAssignmentService $assignments, int $slot): JsonResponse
    {
        if ($denied = $this->rejectClientUserId($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'medicine_id' => ['required', 'integer', 'min:1'],
            'schedule_id' => ['required', 'integer', 'min:1'],
            'confirmed' => ['required', 'boolean'],
        ]);

        return $this->respond($assignments->replace(
            $request->user(),
            $slot,
            (int) $validated['medicine_id'],
            (int) $validated['schedule_id'],
            (bool) $validated['confirmed'],
        ));
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function respond(array $outcome): JsonResponse
    {
        $status = (int) $outcome['http_status'];
        unset($outcome['http_status']);

        return response()->json($outcome, $status);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'result' => SlotAssignmentService::RESULT_UNAVAILABLE,
            'message' => 'The device_schedule_slots table is not available.',
            'slot_assignment_persistent' => false,
            'slot_stability' => 'unavailable',
        ], 503);
    }

    private function rejectClientUserId(Request $request): ?JsonResponse
    {
        if (! $request->exists('user_id')) {
            return null;
        }

        return response()->json([
            'result' => 'user_id_not_accepted',
            'message' => 'Slot assignment uses the authenticated user.',
        ], 422);
    }
}
