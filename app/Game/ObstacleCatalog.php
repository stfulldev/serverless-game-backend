<?php

namespace App\Game;

use LogicException;

final class ObstacleCatalog
{
    /**
     * @return array{id: string, type: string, x: int, y: int, clearCost: int}|null
     */
    public function find(string $mapVersion, string $obstacleId): ?array
    {
        $maps = config('game.maps');

        if (! is_array($maps) || ! is_array($maps[$mapVersion] ?? null)) {
            throw new LogicException("Game map version [{$mapVersion}] must be configured.");
        }

        $map = $maps[$mapVersion];
        $obstacles = $map['obstacles'] ?? null;

        if (! is_array($obstacles)) {
            throw new LogicException("Obstacles for game map version [{$mapVersion}] must be configured.");
        }

        $obstacle = $obstacles[$obstacleId] ?? null;

        if ($obstacle === null) {
            return null;
        }

        if (! is_array($obstacle)) {
            throw new LogicException("Obstacle [{$obstacleId}] must be configured as an array.");
        }

        $type = $obstacle['type'] ?? null;
        $x = $obstacle['x'] ?? null;
        $y = $obstacle['y'] ?? null;
        $clearCost = $obstacle['clear_cost'] ?? null;

        if (
            ! is_string($type) || $type === ''
            || ! is_int($x) || $x < 0
            || ! is_int($y) || $y < 0
            || ! is_int($clearCost) || $clearCost < 0
        ) {
            throw new LogicException("Obstacle [{$obstacleId}] has an invalid configuration.");
        }

        return [
            'id' => $obstacleId,
            'type' => $type,
            'x' => $x,
            'y' => $y,
            'clearCost' => $clearCost,
        ];
    }
}
