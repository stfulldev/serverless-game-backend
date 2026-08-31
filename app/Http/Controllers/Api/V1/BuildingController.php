<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlaceBuildingRequest;
use App\Services\BuildingService;
use Illuminate\Http\JsonResponse;

final class BuildingController extends Controller
{
    public function store(
        PlaceBuildingRequest $request,
        BuildingService $buildings,
    ): JsonResponse {
        $validated = $request->validated();
        $buildingType = $validated['building_type'];
        $idempotencyKey = $validated['idempotencyKey'];

        /** @var string $buildingType */
        /** @var string $idempotencyKey */
        $placedBuilding = $buildings->place(
            playerId: $request->attributes->getString('playerId'),
            buildingType: $buildingType,
            x: $request->integer('x'),
            y: $request->integer('y'),
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(['data' => $placedBuilding], 201);
    }
}
