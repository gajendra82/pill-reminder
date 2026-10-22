<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DemoActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request, DemoActivityService $demo): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if (isset($validated['from'], $validated['to']) && $validated['from'] > $validated['to']) {
            return response()->json([
                'message' => 'The from date must be on or before the to date.',
                'errors' => [
                    'from' => ['The from date must be on or before the to date.'],
                ],
            ], 422);
        }

        return response()->json($demo->analytics($request->user(), $validated));
    }
}
