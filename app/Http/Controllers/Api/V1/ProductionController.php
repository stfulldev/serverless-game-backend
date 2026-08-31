<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartProductionRequest;
use App\Services\ProductionService;
use Illuminate\Http\JsonResponse;

final class ProductionController extends Controller
{
    public function store(
        StartProductionRequest $request,
        string $buildingId,
        ProductionService $productions,
    ): JsonResponse {
        $validated = $request->validated();
        $recipe = $validated['recipe'];
        $idempotencyKey = $validated['idempotencyKey'];

        /** @var string $recipe */
        /** @var string $idempotencyKey */
        $startedProduction = $productions->start(
            playerId: $request->attributes->getString('playerId'),
            buildingId: $buildingId,
            recipeId: $recipe,
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(['data' => $startedProduction], 201);
    }
}
