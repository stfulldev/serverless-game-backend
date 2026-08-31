<?php

namespace App\Game;

use LogicException;

final class BuildingCatalog
{
    /** @return array{type: string, width: int, height: int, placementCost: int}|null */
    public function find(string $buildingType): ?array
    {
        $buildings = config('game.buildings');

        if (! is_array($buildings)) {
            throw new LogicException('Game buildings must be configured.');
        }

        $building = $buildings[$buildingType] ?? null;

        if ($building === null) {
            return null;
        }

        if (! is_array($building)) {
            throw new LogicException("Building [{$buildingType}] must be configured as an array.");
        }

        $width = $building['width'] ?? null;
        $height = $building['height'] ?? null;
        $placementCost = $building['placement_cost'] ?? null;
        $maxFootprintCells = config('game.max_building_footprint_cells');

        if (
            ! is_int($width) || $width < 1
            || ! is_int($height) || $height < 1
            || ! is_int($placementCost) || $placementCost < 0
            || ! is_int($maxFootprintCells) || $maxFootprintCells < 1
            || $width * $height > $maxFootprintCells
        ) {
            throw new LogicException("Building [{$buildingType}] has an invalid configuration.");
        }

        return [
            'type' => $buildingType,
            'width' => $width,
            'height' => $height,
            'placementCost' => $placementCost,
        ];
    }
}
