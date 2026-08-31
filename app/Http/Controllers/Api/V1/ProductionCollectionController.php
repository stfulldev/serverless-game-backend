<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CollectProductionRequest;
use App\Services\ProductionService;
use Illuminate\Http\JsonResponse;

final class ProductionCollectionController extends Controller
{
    public function store(
        CollectProductionRequest $request,
        string $productionId,
        ProductionService $productions,
    ): JsonResponse {
        $validated = $request->validated();
        $idempotencyKey = $validated['idempotencyKey'];

        /** @var string $idempotencyKey */
        $collectedProduction = $productions->collect(
            playerId: $request->attributes->getString('playerId'),
            productionId: $productionId,
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(['data' => $collectedProduction]);
    }
}
