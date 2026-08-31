<?php

namespace App\Services;

use App\Exceptions\BuildingHasActiveProductionException;
use App\Exceptions\BuildingNotFoundException;
use App\Exceptions\CellsOccupiedException;
use App\Exceptions\GameStateConflictException;
use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidPlacementException;
use App\Exceptions\PlayerNotFoundException;
use App\Game\BuildingCatalog;
use App\Game\MapCatalog;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class BuildingService
{
    private const string DeleteCommandType = 'DeleteBuilding';

    private const string MoveCommandType = 'MoveBuilding';

    private const string PlaceCommandType = 'PlaceBuilding';

    private const int SchemaVersion = 1;

    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly PlayerService $players,
        private readonly BuildingCatalog $buildings,
        private readonly MapCatalog $maps,
        private readonly DynamoDbClient $dynamoDb,
    ) {
        $this->marshaler = new Marshaler;
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
     */
    public function place(
        string $playerId,
        string $buildingType,
        int $x,
        int $y,
        string $idempotencyKey,
    ): array {
        $this->validateInput($playerId, $buildingType, $x, $y, $idempotencyKey);
        $requestHash = $this->placeRequestHash($buildingType, $x, $y);
        $existingCommand = $this->getCommand($playerId, $idempotencyKey);

        if ($existingCommand !== null) {
            return $this->replayCommand($existingCommand, $requestHash);
        }

        $profile = $this->players->getProfile($playerId)
            ?? throw new PlayerNotFoundException;
        $building = $this->buildings->find($buildingType)
            ?? throw new InvalidPlacementException('Building type is not available.');
        $cells = $this->footprint(
            mapVersion: $profile['map']['version'],
            x: $x,
            y: $y,
            width: $building['width'],
            height: $building['height'],
        );
        $overlappingObstacles = $this->overlappingObstacles($profile['map']['version'], $cells);

        if (! $this->allObstaclesAreCleared($playerId, $overlappingObstacles)) {
            throw new CellsOccupiedException;
        }

        $wallet = $profile['wallet'];

        if ($wallet['coins'] < $building['placementCost']) {
            throw new InsufficientFundsException;
        }

        $timestamp = now()->utc()->toISOString();
        $buildingId = (string) Str::uuid();
        $response = [
            'building' => [
                'id' => $buildingId,
                'type' => $building['type'],
                'x' => $x,
                'y' => $y,
                'width' => $building['width'],
                'height' => $building['height'],
                'level' => 1,
                'version' => 1,
                'placedAt' => $timestamp,
            ],
            'wallet' => [
                'coins' => $wallet['coins'] - $building['placementCost'],
                'resources' => $wallet['resources'],
                'version' => $wallet['version'] + 1,
            ],
        ];

        try {
            $this->writePlaceTransaction(
                playerId: $playerId,
                building: $building,
                cells: $cells,
                overlappingObstacles: $overlappingObstacles,
                currentWallet: $wallet,
                response: $response,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                timestamp: $timestamp,
            );
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            return $this->recoverCanceledPlaceTransaction(
                playerId: $playerId,
                cells: $cells,
                overlappingObstacles: $overlappingObstacles,
                placementCost: $building['placementCost'],
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
            );
        }

        return $response;
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, movedAt: string}
     * }
     */
    public function move(
        string $playerId,
        string $buildingId,
        int $x,
        int $y,
        string $idempotencyKey,
    ): array {
        $this->validateMoveInput($playerId, $buildingId, $x, $y, $idempotencyKey);
        $requestHash = $this->moveRequestHash($buildingId, $x, $y);
        $existingCommand = $this->getCommand($playerId, $idempotencyKey);

        if ($existingCommand !== null) {
            return $this->replayCommand($existingCommand, $requestHash);
        }

        $building = $this->getBuilding($playerId, $buildingId)
            ?? throw new BuildingNotFoundException;

        if ($building['x'] === $x && $building['y'] === $y) {
            throw new InvalidPlacementException('Building is already at the requested coordinates.');
        }

        $profile = $this->players->getProfile($playerId)
            ?? throw new PlayerNotFoundException;
        $currentCells = $this->footprint(
            mapVersion: $profile['map']['version'],
            x: $building['x'],
            y: $building['y'],
            width: $building['width'],
            height: $building['height'],
        );
        $newCells = $this->footprint(
            mapVersion: $profile['map']['version'],
            x: $x,
            y: $y,
            width: $building['width'],
            height: $building['height'],
        );
        $overlappingObstacles = $this->overlappingObstacles($profile['map']['version'], $newCells);

        if (! $this->allObstaclesAreCleared($playerId, $overlappingObstacles)) {
            throw new CellsOccupiedException;
        }

        $currentCellsById = [];
        $newCellsById = [];

        foreach ($currentCells as $cell) {
            $currentCellsById[$cell['id']] = $cell;
        }

        foreach ($newCells as $cell) {
            $newCellsById[$cell['id']] = $cell;
        }

        $cellsToRelease = array_values(array_diff_key($currentCellsById, $newCellsById));
        $retainedCells = array_values(array_intersect_key($currentCellsById, $newCellsById));
        $cellsToReserve = array_values(array_diff_key($newCellsById, $currentCellsById));
        $timestamp = now()->utc()->toISOString();
        $response = [
            'building' => [
                'id' => $building['id'],
                'type' => $building['type'],
                'x' => $x,
                'y' => $y,
                'width' => $building['width'],
                'height' => $building['height'],
                'level' => $building['level'],
                'version' => $building['version'] + 1,
                'placedAt' => $building['placedAt'],
                'movedAt' => $timestamp,
            ],
        ];

        try {
            $this->writeMoveTransaction(
                playerId: $playerId,
                building: $building,
                cellsToRelease: $cellsToRelease,
                retainedCells: $retainedCells,
                cellsToReserve: $cellsToReserve,
                overlappingObstacles: $overlappingObstacles,
                response: $response,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                timestamp: $timestamp,
            );
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            return $this->recoverCanceledMoveTransaction(
                playerId: $playerId,
                building: $building,
                newCells: $newCells,
                overlappingObstacles: $overlappingObstacles,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
            );
        }

        return $response;
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, deletedAt: string}
     * }
     */
    public function delete(
        string $playerId,
        string $buildingId,
        string $idempotencyKey,
    ): array {
        $this->validateDeleteInput($playerId, $buildingId, $idempotencyKey);
        $requestHash = $this->deleteRequestHash($buildingId);
        $existingCommand = $this->getCommand($playerId, $idempotencyKey);

        if ($existingCommand !== null) {
            return $this->replayCommand($existingCommand, $requestHash);
        }

        $building = $this->getBuilding($playerId, $buildingId)
            ?? throw new BuildingNotFoundException;

        if ($building['activeProductionId'] !== null) {
            throw new BuildingHasActiveProductionException;
        }

        $cells = $this->footprintCells(
            x: $building['x'],
            y: $building['y'],
            width: $building['width'],
            height: $building['height'],
        );
        $timestamp = now()->utc()->toISOString();
        $response = [
            'building' => [
                'id' => $building['id'],
                'type' => $building['type'],
                'x' => $building['x'],
                'y' => $building['y'],
                'width' => $building['width'],
                'height' => $building['height'],
                'level' => $building['level'],
                'version' => $building['version'],
                'placedAt' => $building['placedAt'],
                'deletedAt' => $timestamp,
            ],
        ];

        try {
            $this->writeDeleteTransaction(
                playerId: $playerId,
                building: $building,
                cells: $cells,
                response: $response,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                timestamp: $timestamp,
            );
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            return $this->recoverCanceledDeleteTransaction(
                playerId: $playerId,
                buildingId: $buildingId,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
            );
        }

        return $response;
    }

    /**
     * @param  array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string}  $building
     * @param  list<array{id: string, x: int, y: int}>  $cells
     * @param  array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, deletedAt: string}
     * }  $response
     */
    private function writeDeleteTransaction(
        string $playerId,
        array $building,
        array $cells,
        array $response,
        string $idempotencyKey,
        string $requestHash,
        string $timestamp,
    ): void {
        $eventId = (string) Str::uuid();
        $transactItems = [
            [
                'Delete' => [
                    'TableName' => $this->tableName('buildings'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'building_id' => $building['id'],
                    ]),
                    'ConditionExpression' => 'x = :expected_x AND y = :expected_y AND (#building_version = :expected_version OR attribute_not_exists(#building_version)) AND attribute_not_exists(active_production_id)',
                    'ExpressionAttributeNames' => [
                        '#building_version' => 'version',
                    ],
                    'ExpressionAttributeValues' => [
                        ':expected_x' => $this->marshaler->marshalValue($building['x']),
                        ':expected_y' => $this->marshaler->marshalValue($building['y']),
                        ':expected_version' => $this->marshaler->marshalValue($building['version']),
                    ],
                ],
            ],
        ];

        foreach ($cells as $cell) {
            $transactItems[] = [
                'Delete' => [
                    'TableName' => $this->tableName('occupied_cells'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'cell_id' => $cell['id'],
                    ]),
                    'ConditionExpression' => 'building_id = :building_id',
                    'ExpressionAttributeValues' => [
                        ':building_id' => $this->marshaler->marshalValue($building['id']),
                    ],
                ],
            ];
        }

        $transactItems[] = [
            'Put' => [
                'TableName' => $this->tableName('commands'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'idempotency_key' => $idempotencyKey,
                    'schema_version' => self::SchemaVersion,
                    'command_type' => self::DeleteCommandType,
                    'request_hash' => $requestHash,
                    'response' => $response,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'expires_at' => now()->addSeconds($this->idempotencyTtlSeconds())->getTimestamp(),
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(idempotency_key)',
            ],
        ];
        $transactItems[] = [
            'Put' => [
                'TableName' => $this->tableName('outbox_events'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'event_id' => $eventId,
                    'schema_version' => self::SchemaVersion,
                    'event_type' => 'BuildingDeleted.v1',
                    'occurred_at' => $timestamp,
                    'correlation_id' => $idempotencyKey,
                    'payload' => [
                        'building_id' => $building['id'],
                        'building_type' => $building['type'],
                        'x' => $building['x'],
                        'y' => $building['y'],
                        'width' => $building['width'],
                        'height' => $building['height'],
                    ],
                    'published_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(event_id)',
            ],
        ];

        $this->dynamoDb->transactWriteItems([
            'TransactItems' => $transactItems,
        ]);
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, deletedAt: string}
     * }
     */
    private function recoverCanceledDeleteTransaction(
        string $playerId,
        string $buildingId,
        string $idempotencyKey,
        string $requestHash,
    ): array {
        $command = $this->getCommand($playerId, $idempotencyKey);

        if ($command !== null) {
            return $this->replayCommand($command, $requestHash);
        }

        $building = $this->getBuilding($playerId, $buildingId);

        if ($building === null) {
            throw new BuildingNotFoundException;
        }

        if ($building['activeProductionId'] !== null) {
            throw new BuildingHasActiveProductionException;
        }

        throw new GameStateConflictException;
    }

    /**
     * @param  array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string}  $building
     * @param  list<array{id: string, x: int, y: int}>  $cellsToRelease
     * @param  list<array{id: string, x: int, y: int}>  $retainedCells
     * @param  list<array{id: string, x: int, y: int}>  $cellsToReserve
     * @param  list<array{id: string, type: string, x: int, y: int, clearCost: int}>  $overlappingObstacles
     * @param  array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, movedAt: string}
     * }  $response
     */
    private function writeMoveTransaction(
        string $playerId,
        array $building,
        array $cellsToRelease,
        array $retainedCells,
        array $cellsToReserve,
        array $overlappingObstacles,
        array $response,
        string $idempotencyKey,
        string $requestHash,
        string $timestamp,
    ): void {
        $eventId = (string) Str::uuid();
        $transactItems = [
            [
                'Update' => [
                    'TableName' => $this->tableName('buildings'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'building_id' => $building['id'],
                    ]),
                    'UpdateExpression' => 'SET x = :x, y = :y, #building_version = :building_version, moved_at = :moved_at, updated_at = :updated_at',
                    'ConditionExpression' => 'x = :expected_x AND y = :expected_y AND (#building_version = :expected_version OR attribute_not_exists(#building_version))',
                    'ExpressionAttributeNames' => [
                        '#building_version' => 'version',
                    ],
                    'ExpressionAttributeValues' => [
                        ':x' => $this->marshaler->marshalValue($response['building']['x']),
                        ':y' => $this->marshaler->marshalValue($response['building']['y']),
                        ':building_version' => $this->marshaler->marshalValue($response['building']['version']),
                        ':moved_at' => $this->marshaler->marshalValue($timestamp),
                        ':updated_at' => $this->marshaler->marshalValue($timestamp),
                        ':expected_x' => $this->marshaler->marshalValue($building['x']),
                        ':expected_y' => $this->marshaler->marshalValue($building['y']),
                        ':expected_version' => $this->marshaler->marshalValue($building['version']),
                    ],
                ],
            ],
        ];

        foreach ($cellsToRelease as $cell) {
            $transactItems[] = [
                'Delete' => [
                    'TableName' => $this->tableName('occupied_cells'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'cell_id' => $cell['id'],
                    ]),
                    'ConditionExpression' => 'building_id = :building_id',
                    'ExpressionAttributeValues' => [
                        ':building_id' => $this->marshaler->marshalValue($building['id']),
                    ],
                ],
            ];
        }

        foreach ($retainedCells as $cell) {
            $transactItems[] = [
                'ConditionCheck' => [
                    'TableName' => $this->tableName('occupied_cells'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'cell_id' => $cell['id'],
                    ]),
                    'ConditionExpression' => 'building_id = :building_id',
                    'ExpressionAttributeValues' => [
                        ':building_id' => $this->marshaler->marshalValue($building['id']),
                    ],
                ],
            ];
        }

        foreach ($cellsToReserve as $cell) {
            $transactItems[] = [
                'Put' => [
                    'TableName' => $this->tableName('occupied_cells'),
                    'Item' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'cell_id' => $cell['id'],
                        'schema_version' => self::SchemaVersion,
                        'building_id' => $building['id'],
                        'x' => $cell['x'],
                        'y' => $cell['y'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]),
                    'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(cell_id)',
                ],
            ];
        }

        foreach ($overlappingObstacles as $obstacle) {
            $transactItems[] = [
                'ConditionCheck' => [
                    'TableName' => $this->tableName('cleared_obstacles'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'obstacle_id' => $obstacle['id'],
                    ]),
                    'ConditionExpression' => 'attribute_exists(player_id) AND attribute_exists(obstacle_id)',
                ],
            ];
        }

        $transactItems[] = [
            'Put' => [
                'TableName' => $this->tableName('commands'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'idempotency_key' => $idempotencyKey,
                    'schema_version' => self::SchemaVersion,
                    'command_type' => self::MoveCommandType,
                    'request_hash' => $requestHash,
                    'response' => $response,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'expires_at' => now()->addSeconds($this->idempotencyTtlSeconds())->getTimestamp(),
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(idempotency_key)',
            ],
        ];
        $transactItems[] = [
            'Put' => [
                'TableName' => $this->tableName('outbox_events'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'event_id' => $eventId,
                    'schema_version' => self::SchemaVersion,
                    'event_type' => 'BuildingMoved.v1',
                    'occurred_at' => $timestamp,
                    'correlation_id' => $idempotencyKey,
                    'payload' => [
                        'building_id' => $building['id'],
                        'building_type' => $building['type'],
                        'from' => [
                            'x' => $building['x'],
                            'y' => $building['y'],
                        ],
                        'to' => [
                            'x' => $response['building']['x'],
                            'y' => $response['building']['y'],
                        ],
                        'width' => $building['width'],
                        'height' => $building['height'],
                    ],
                    'published_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(event_id)',
            ],
        ];

        $this->dynamoDb->transactWriteItems([
            'TransactItems' => $transactItems,
        ]);
    }

    /**
     * @param  array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string}  $building
     * @param  list<array{id: string, x: int, y: int}>  $newCells
     * @param  list<array{id: string, type: string, x: int, y: int, clearCost: int}>  $overlappingObstacles
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, movedAt: string}
     * }
     */
    private function recoverCanceledMoveTransaction(
        string $playerId,
        array $building,
        array $newCells,
        array $overlappingObstacles,
        string $idempotencyKey,
        string $requestHash,
    ): array {
        $command = $this->getCommand($playerId, $idempotencyKey);

        if ($command !== null) {
            return $this->replayCommand($command, $requestHash);
        }

        if ($this->getBuilding($playerId, $building['id']) === null) {
            throw new BuildingNotFoundException;
        }

        if ($this->anyCellIsOccupied($playerId, $newCells, $building['id'])
            || ! $this->allObstaclesAreCleared($playerId, $overlappingObstacles)) {
            throw new CellsOccupiedException;
        }

        throw new GameStateConflictException;
    }

    /**
     * @param  array{type: string, width: int, height: int, placementCost: int}  $building
     * @param  list<array{id: string, x: int, y: int}>  $cells
     * @param  list<array{id: string, type: string, x: int, y: int, clearCost: int}>  $overlappingObstacles
     * @param  array{coins: int, resources: array<string, int>, version: int}  $currentWallet
     * @param  array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }  $response
     */
    private function writePlaceTransaction(
        string $playerId,
        array $building,
        array $cells,
        array $overlappingObstacles,
        array $currentWallet,
        array $response,
        string $idempotencyKey,
        string $requestHash,
        string $timestamp,
    ): void {
        $buildingId = $response['building']['id'];
        $eventId = (string) Str::uuid();
        $transactItems = [
            [
                'Update' => [
                    'TableName' => $this->tableName('wallets'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                    ]),
                    'UpdateExpression' => 'SET coins = :coins, #wallet_version = :wallet_version, updated_at = :updated_at',
                    'ConditionExpression' => 'coins = :expected_coins AND #wallet_version = :expected_wallet_version',
                    'ExpressionAttributeNames' => [
                        '#wallet_version' => 'version',
                    ],
                    'ExpressionAttributeValues' => [
                        ':coins' => $this->marshaler->marshalValue($response['wallet']['coins']),
                        ':wallet_version' => $this->marshaler->marshalValue($response['wallet']['version']),
                        ':updated_at' => $this->marshaler->marshalValue($timestamp),
                        ':expected_coins' => $this->marshaler->marshalValue($currentWallet['coins']),
                        ':expected_wallet_version' => $this->marshaler->marshalValue($currentWallet['version']),
                    ],
                ],
            ],
            [
                'Put' => [
                    'TableName' => $this->tableName('buildings'),
                    'Item' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'building_id' => $buildingId,
                        'schema_version' => self::SchemaVersion,
                        'type' => $building['type'],
                        'x' => $response['building']['x'],
                        'y' => $response['building']['y'],
                        'width' => $building['width'],
                        'height' => $building['height'],
                        'level' => 1,
                        'version' => 1,
                        'placement_cost' => $building['placementCost'],
                        'placed_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]),
                    'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(building_id)',
                ],
            ],
        ];

        foreach ($cells as $cell) {
            $transactItems[] = [
                'Put' => [
                    'TableName' => $this->tableName('occupied_cells'),
                    'Item' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'cell_id' => $cell['id'],
                        'schema_version' => self::SchemaVersion,
                        'building_id' => $buildingId,
                        'x' => $cell['x'],
                        'y' => $cell['y'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]),
                    'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(cell_id)',
                ],
            ];
        }

        foreach ($overlappingObstacles as $obstacle) {
            $transactItems[] = [
                'ConditionCheck' => [
                    'TableName' => $this->tableName('cleared_obstacles'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'obstacle_id' => $obstacle['id'],
                    ]),
                    'ConditionExpression' => 'attribute_exists(player_id) AND attribute_exists(obstacle_id)',
                ],
            ];
        }

        $transactItems[] = [
            'Put' => [
                'TableName' => $this->tableName('commands'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'idempotency_key' => $idempotencyKey,
                    'schema_version' => self::SchemaVersion,
                    'command_type' => self::PlaceCommandType,
                    'request_hash' => $requestHash,
                    'response' => $response,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'expires_at' => now()->addSeconds($this->idempotencyTtlSeconds())->getTimestamp(),
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(idempotency_key)',
            ],
        ];
        $transactItems[] = [
            'Put' => [
                'TableName' => $this->tableName('outbox_events'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'event_id' => $eventId,
                    'schema_version' => self::SchemaVersion,
                    'event_type' => 'BuildingPlaced.v1',
                    'occurred_at' => $timestamp,
                    'correlation_id' => $idempotencyKey,
                    'payload' => [
                        'building_id' => $buildingId,
                        'building_type' => $building['type'],
                        'x' => $response['building']['x'],
                        'y' => $response['building']['y'],
                        'width' => $building['width'],
                        'height' => $building['height'],
                        'placement_cost' => $building['placementCost'],
                    ],
                    'published_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(event_id)',
            ],
        ];

        $this->dynamoDb->transactWriteItems([
            'TransactItems' => $transactItems,
        ]);
    }

    /**
     * @param  list<array{id: string, x: int, y: int}>  $cells
     * @param  list<array{id: string, type: string, x: int, y: int, clearCost: int}>  $overlappingObstacles
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
     */
    private function recoverCanceledPlaceTransaction(
        string $playerId,
        array $cells,
        array $overlappingObstacles,
        int $placementCost,
        string $idempotencyKey,
        string $requestHash,
    ): array {
        $command = $this->getCommand($playerId, $idempotencyKey);

        if ($command !== null) {
            return $this->replayCommand($command, $requestHash);
        }

        if ($this->anyCellIsOccupied($playerId, $cells)
            || ! $this->allObstaclesAreCleared($playerId, $overlappingObstacles)) {
            throw new CellsOccupiedException;
        }

        $profile = $this->players->getProfile($playerId)
            ?? throw new PlayerNotFoundException;

        if ($profile['wallet']['coins'] < $placementCost) {
            throw new InsufficientFundsException;
        }

        throw new GameStateConflictException;
    }

    /** @return list<array{id: string, x: int, y: int}> */
    private function footprint(string $mapVersion, int $x, int $y, int $width, int $height): array
    {
        $dimensions = $this->maps->dimensions($mapVersion);

        if ($x + $width > $dimensions['width'] || $y + $height > $dimensions['height']) {
            throw new InvalidPlacementException;
        }

        return $this->footprintCells($x, $y, $width, $height);
    }

    /** @return list<array{id: string, x: int, y: int}> */
    private function footprintCells(int $x, int $y, int $width, int $height): array
    {
        $cells = [];

        for ($cellY = $y; $cellY < $y + $height; $cellY++) {
            for ($cellX = $x; $cellX < $x + $width; $cellX++) {
                $cells[] = [
                    'id' => sprintf('%03d#%03d', $cellX, $cellY),
                    'x' => $cellX,
                    'y' => $cellY,
                ];
            }
        }

        return $cells;
    }

    /**
     * @param  list<array{id: string, x: int, y: int}>  $cells
     * @return list<array{id: string, type: string, x: int, y: int, clearCost: int}>
     */
    private function overlappingObstacles(string $mapVersion, array $cells): array
    {
        $overlappingObstacles = [];

        foreach ($cells as $cell) {
            $obstacle = $this->maps->findObstacleAt($mapVersion, $cell['x'], $cell['y']);

            if ($obstacle !== null) {
                $overlappingObstacles[] = $obstacle;
            }
        }

        return $overlappingObstacles;
    }

    /**
     * @param  list<array{id: string, type: string, x: int, y: int, clearCost: int}>  $obstacles
     */
    private function allObstaclesAreCleared(string $playerId, array $obstacles): bool
    {
        foreach ($obstacles as $obstacle) {
            $result = $this->dynamoDb->getItem([
                'TableName' => $this->tableName('cleared_obstacles'),
                'Key' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'obstacle_id' => $obstacle['id'],
                ]),
                'ConsistentRead' => true,
            ]);

            if (! is_array($result->get('Item'))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{id: string, x: int, y: int}> $cells */
    private function anyCellIsOccupied(
        string $playerId,
        array $cells,
        ?string $allowedBuildingId = null,
    ): bool {
        foreach ($cells as $cell) {
            $result = $this->dynamoDb->getItem([
                'TableName' => $this->tableName('occupied_cells'),
                'Key' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'cell_id' => $cell['id'],
                ]),
                'ConsistentRead' => true,
            ]);
            $item = $result->get('Item');

            if (! is_array($item)) {
                continue;
            }

            if ($allowedBuildingId === null) {
                return true;
            }

            /** @var array<string, mixed> $occupiedCell */
            $occupiedCell = $this->marshaler->unmarshalItem($item);

            if (($occupiedCell['building_id'] ?? null) !== $allowedBuildingId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, activeProductionId: ?string}|null
     */
    private function getBuilding(string $playerId, string $buildingId): ?array
    {
        $result = $this->dynamoDb->getItem([
            'TableName' => $this->tableName('buildings'),
            'Key' => $this->marshaler->marshalItem([
                'player_id' => $playerId,
                'building_id' => $buildingId,
            ]),
            'ConsistentRead' => true,
        ]);
        $item = $result->get('Item');

        if (! is_array($item)) {
            return null;
        }

        /** @var array<string, mixed> $building */
        $building = $this->marshaler->unmarshalItem($item);
        $type = $building['type'] ?? null;
        $buildingX = $building['x'] ?? null;
        $buildingY = $building['y'] ?? null;
        $width = $building['width'] ?? null;
        $height = $building['height'] ?? null;
        $level = $building['level'] ?? null;
        $version = $building['version'] ?? 1;
        $placedAt = $building['placed_at'] ?? null;
        $activeProductionId = $building['active_production_id'] ?? null;

        if (
            ! is_string($type) || $type === ''
            || ! is_int($buildingX) || $buildingX < 0
            || ! is_int($buildingY) || $buildingY < 0
            || ! is_int($width) || $width < 1
            || ! is_int($height) || $height < 1
            || ! is_int($level) || $level < 1
            || ! is_int($version) || $version < 1
            || ! is_string($placedAt) || $placedAt === ''
            || ($activeProductionId !== null
                && (! is_string($activeProductionId) || $activeProductionId === ''))
        ) {
            throw new LogicException("Building [{$buildingId}] has an invalid state.");
        }

        return [
            'id' => $buildingId,
            'type' => $type,
            'x' => $buildingX,
            'y' => $buildingY,
            'width' => $width,
            'height' => $height,
            'level' => $level,
            'version' => $version,
            'placedAt' => $placedAt,
            'activeProductionId' => $activeProductionId,
        ];
    }

    /** @return array<string, mixed>|null */
    private function getCommand(string $playerId, string $idempotencyKey): ?array
    {
        $result = $this->dynamoDb->getItem([
            'TableName' => $this->tableName('commands'),
            'Key' => $this->marshaler->marshalItem([
                'player_id' => $playerId,
                'idempotency_key' => $idempotencyKey,
            ]),
            'ConsistentRead' => true,
        ]);
        $item = $result->get('Item');

        if (! is_array($item)) {
            return null;
        }

        /** @var array<string, mixed> $command */
        $command = $this->marshaler->unmarshalItem($item);

        return $command;
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function replayCommand(array $command, string $requestHash): array
    {
        if (($command['request_hash'] ?? null) !== $requestHash) {
            throw new IdempotencyKeyReusedException;
        }

        $response = $command['response'] ?? null;

        if (! is_array($response)) {
            throw new LogicException('The stored idempotency response must be an array.');
        }

        /** @var array<string, mixed> $response */
        return $response;
    }

    private function placeRequestHash(string $buildingType, int $x, int $y): string
    {
        return hash('sha256', self::PlaceCommandType."\n{$buildingType}\n{$x}\n{$y}");
    }

    private function deleteRequestHash(string $buildingId): string
    {
        return hash('sha256', self::DeleteCommandType."\n{$buildingId}");
    }

    private function moveRequestHash(string $buildingId, int $x, int $y): string
    {
        return hash('sha256', self::MoveCommandType."\n{$buildingId}\n{$x}\n{$y}");
    }

    private function idempotencyTtlSeconds(): int
    {
        $ttl = config('game.idempotency_ttl_seconds');

        if (! is_int($ttl) || $ttl < 1) {
            throw new LogicException('The idempotency TTL must be a positive integer.');
        }

        return $ttl;
    }

    private function tableName(string $tableKey): string
    {
        $tableName = config("services.aws.dynamodb_tables.{$tableKey}");

        if (! is_string($tableName) || $tableName === '') {
            throw new LogicException("DynamoDB table [{$tableKey}] must be configured.");
        }

        return $tableName;
    }

    private function validateInput(
        string $playerId,
        string $buildingType,
        int $x,
        int $y,
        string $idempotencyKey,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($buildingType === '') {
            throw new InvalidArgumentException('Building type cannot be empty.');
        }

        if ($x < 0 || $y < 0) {
            throw new InvalidArgumentException('Building coordinates cannot be negative.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }

    private function validateMoveInput(
        string $playerId,
        string $buildingId,
        int $x,
        int $y,
        string $idempotencyKey,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($buildingId === '') {
            throw new InvalidArgumentException('Building ID cannot be empty.');
        }

        if ($x < 0 || $y < 0) {
            throw new InvalidArgumentException('Building coordinates cannot be negative.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }

    private function validateDeleteInput(
        string $playerId,
        string $buildingId,
        string $idempotencyKey,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($buildingId === '') {
            throw new InvalidArgumentException('Building ID cannot be empty.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }
}
