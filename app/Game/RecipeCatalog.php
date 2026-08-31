<?php

namespace App\Game;

use LogicException;

final class RecipeCatalog
{
    /**
     * @return array{
     *     id: string,
     *     buildingTypes: list<string>,
     *     durationSeconds: int,
     *     output: array{resource: string, quantity: int}
     * }|null
     */
    public function find(string $recipeId): ?array
    {
        $recipes = config('game.recipes');

        if (! is_array($recipes)) {
            throw new LogicException('Game recipes must be configured.');
        }

        $recipe = $recipes[$recipeId] ?? null;

        if ($recipe === null) {
            return null;
        }

        if (! is_array($recipe)) {
            throw new LogicException("Recipe [{$recipeId}] must be configured as an array.");
        }

        $buildingTypes = $recipe['building_types'] ?? null;
        $durationSeconds = $recipe['duration_seconds'] ?? null;
        $output = $recipe['output'] ?? null;
        $outputResource = is_array($output) ? ($output['resource'] ?? null) : null;
        $outputQuantity = is_array($output) ? ($output['quantity'] ?? null) : null;

        if (
            ! is_array($buildingTypes) || $buildingTypes === []
            || ! is_int($durationSeconds) || $durationSeconds < 1
            || ! is_string($outputResource) || $outputResource === ''
            || ! is_int($outputQuantity) || $outputQuantity < 1
        ) {
            throw new LogicException("Recipe [{$recipeId}] has an invalid configuration.");
        }

        $normalizedBuildingTypes = [];

        foreach ($buildingTypes as $buildingType) {
            if (! is_string($buildingType) || $buildingType === '') {
                throw new LogicException("Recipe [{$recipeId}] has an invalid building type.");
            }

            $normalizedBuildingTypes[] = $buildingType;
        }

        return [
            'id' => $recipeId,
            'buildingTypes' => $normalizedBuildingTypes,
            'durationSeconds' => $durationSeconds,
            'output' => [
                'resource' => $outputResource,
                'quantity' => $outputQuantity,
            ],
        ];
    }
}
