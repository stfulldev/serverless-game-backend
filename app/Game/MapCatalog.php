<?php

namespace App\Game;

use LogicException;

final class MapCatalog
{
    /** @return array{width: int, height: int} */
    public function dimensions(string $mapVersion): array
    {
        $map = $this->map($mapVersion);
        $width = $map['width'] ?? null;
        $height = $map['height'] ?? null;

        if (! is_int($width) || $width < 1 || ! is_int($height) || $height < 1) {
            throw new LogicException("Game map version [{$mapVersion}] has invalid dimensions.");
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @return array{id: string, type: string, x: int, y: int, clearCost: int}|null */
    public function findObstacle(string $mapVersion, string $obstacleId): ?array
    {
        $obstacles = $this->obstacles($mapVersion);
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

    /** @return array{id: string, type: string, x: int, y: int, clearCost: int}|null */
    public function findObstacleAt(string $mapVersion, int $x, int $y): ?array
    {
        foreach (array_keys($this->obstacles($mapVersion)) as $obstacleId) {
            if (! is_string($obstacleId)) {
                throw new LogicException("Obstacles for game map version [{$mapVersion}] must use string IDs.");
            }

            $obstacle = $this->findObstacle($mapVersion, $obstacleId);

            if ($obstacle !== null && $obstacle['x'] === $x && $obstacle['y'] === $y) {
                return $obstacle;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function map(string $mapVersion): array
    {
        $maps = config('game.maps');
        $map = is_array($maps) ? ($maps[$mapVersion] ?? null) : null;

        if (! is_array($map)) {
            throw new LogicException("Game map version [{$mapVersion}] must be configured.");
        }

        return $map;
    }

    /** @return array<array-key, mixed> */
    private function obstacles(string $mapVersion): array
    {
        $obstacles = $this->map($mapVersion)['obstacles'] ?? null;

        if (! is_array($obstacles)) {
            throw new LogicException("Obstacles for game map version [{$mapVersion}] must be configured.");
        }

        return $obstacles;
    }
}
