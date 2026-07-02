<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function respond($data = [], int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    protected function ingestionResponse(int $saved, array $errors): JsonResponse
    {
        return response()->json([
            'status' => empty($errors) ? 'success' : 'partial_success',
            'saved' => $saved,
            'errors' => $errors,
        ], 200);
    }

    protected function notImplemented(): JsonResponse
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
