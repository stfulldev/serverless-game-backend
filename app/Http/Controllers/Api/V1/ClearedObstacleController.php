<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClearObstacleRequest;
use App\Services\ObstacleService;
use Illuminate\Http\JsonResponse;

final class ClearedObstacleController extends Controller
{
    public function store(
        ClearObstacleRequest $request,
        string $obstacleId,
        ObstacleService $obstacles,
    ): JsonResponse {
        $validated = $request->validated();
        $idempotencyKey = $validated['idempotencyKey'];

        /** @var string $idempotencyKey */
        $clearedObstacle = $obstacles->clear(
            playerId: $request->attributes->getString('playerId'),
            obstacleId: $obstacleId,
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(['data' => $clearedObstacle]);
    }
}
