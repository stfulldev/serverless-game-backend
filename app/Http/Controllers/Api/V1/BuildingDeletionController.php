<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteBuildingRequest;
use App\Services\BuildingService;
use Illuminate\Http\JsonResponse;

final class BuildingDeletionController extends Controller
{
    public function destroy(
        DeleteBuildingRequest $request,
        string $buildingId,
        BuildingService $buildings,
    ): JsonResponse {
        $validated = $request->validated();
        $idempotencyKey = $validated['idempotencyKey'];

        /** @var string $idempotencyKey */
        $deletedBuilding = $buildings->delete(
            playerId: $request->attributes->getString('playerId'),
            buildingId: $buildingId,
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(['data' => $deletedBuilding]);
    }
}
