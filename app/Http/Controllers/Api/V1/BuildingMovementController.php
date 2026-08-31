<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MoveBuildingRequest;
use App\Services\BuildingService;
use Illuminate\Http\JsonResponse;

final class BuildingMovementController extends Controller
{
    public function update(
        MoveBuildingRequest $request,
        string $buildingId,
        BuildingService $buildings,
    ): JsonResponse {
        $validated = $request->validated();
        $idempotencyKey = $validated['idempotencyKey'];

        /** @var string $idempotencyKey */
        $movedBuilding = $buildings->move(
            playerId: $request->attributes->getString('playerId'),
            buildingId: $buildingId,
            x: $request->integer('x'),
            y: $request->integer('y'),
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(['data' => $movedBuilding]);
    }
}
