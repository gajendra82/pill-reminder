<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BlynkClient;
use App\Services\BlynkClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DeviceStatusController extends Controller
{
    public function __invoke(BlynkClient $blynk): JsonResponse
    {
        try {
            $datastreams = $blynk->readDatastreams();
        } catch (BlynkClientException $exception) {
            return $this->errorResponse($exception->errorCode);
        }

        return response()->json([
            'device_status' => 'available',
            'data_source' => 'blynk_live',
            'integration_scope' => 'temporary_single_device',
            'retrieved_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'datastreams' => $datastreams,
            'notice' => 'Temporary single-device integration. Datastream values are uninterpreted Blynk readings and are not a medication record.',
        ]);
    }

    private function errorResponse(string $errorCode): JsonResponse
    {
        $status = match ($errorCode) {
            BlynkClientException::NOT_CONFIGURED => 503,
            BlynkClientException::RATE_LIMITED => 429,
            BlynkClientException::CONNECTION_FAILED => 504,
            default => 502,
        };

        $message = match ($errorCode) {
            BlynkClientException::NOT_CONFIGURED => 'Device status is unavailable because Blynk is not configured.',
            BlynkClientException::RATE_LIMITED => 'Device status is temporarily rate limited.',
            default => 'Device status could not be retrieved.',
        };

        return response()->json([
            'message' => $message,
            'error' => $errorCode,
        ], $status);
    }
}
