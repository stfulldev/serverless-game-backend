<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FarmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FarmController extends Controller
{
    public function show(Request $request, FarmService $farms): JsonResponse
    {
        $farm = $farms->getFarm($request->attributes->getString('playerId'));

        if ($farm === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Farm not found.',
                ],
            ], 404);
        }

        return response()->json(['data' => $farm]);
    }
}
