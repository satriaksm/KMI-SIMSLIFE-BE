<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Success response
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        ?array $meta = null
    ): JsonResponse {
        $response = [
            'message' => $message,
            'data' => $data,
        ];
        if (!is_null($meta)) {
            $response['meta'] = $meta;
        }
        return response()->json($response, $status);
    }

    /**
     * Error response
     */
    public static function error(
        string $message,
        int $status = 400,
        mixed $errors = null
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
