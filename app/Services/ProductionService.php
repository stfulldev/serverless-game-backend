<?php

namespace App\Services;

use App\Exceptions\BuildingHasActiveProductionException;
use App\Exceptions\BuildingNotFoundException;
use App\Exceptions\GameStateConflictException;
use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\InvalidRecipeException;
use App\Game\RecipeCatalog;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ProductionService
{
    private const int SchemaVersion = 1;

    private const string StartCommandType = 'StartProduction';

    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly RecipeCatalog $recipes,
        private readonly DynamoDbClient $dynamoDb,
    ) {
        $this->marshaler = new Marshaler;
    }

    /**
     * @return array{
     *     production: array{
     *         id: string,
     *         buildingId: string,
     *         recipe: string,
     *         status: string,
     *         output: array{resource: string, quantity: int},
     *         version: int,
     *         startedAt: string,
     *         completesAt: string
     *     }
     * }
     */
    public function start(
        string $playerId,
        string $buildingId,
        string $recipeId,
        string $idempotencyKey,
    ): array {
        $this->validateInput($playerId, $buildingId, $recipeId, $idempotencyKey);
        $requestHash = $this->requestHash($buildingId, $recipeId);
        $existingCommand = $this->getCommand($playerId, $idempotencyKey);

        if ($existingCommand !== null) {
            return $this->replayCommand($existingCommand, $requestHash);
        }

        $building = $this->getBuilding($playerId, $buildingId)
            ?? throw new BuildingNotFoundException;
        $recipe = $this->recipes->find($recipeId)
            ?? throw new InvalidRecipeException;

        if (! in_array($building['type'], $recipe['buildingTypes'], true)) {
            throw new InvalidRecipeException('Recipe is not available for this building.');
        }

        if ($building['activeProductionId'] !== null) {
            throw new BuildingHasActiveProductionException;
        }

        $startedAt = now()->utc();
        $startedAtTimestamp = $startedAt->toISOString();
        $completesAtTimestamp = $startedAt->copy()
            ->addSeconds($recipe['durationSeconds'])
            ->toISOString();
        $productionId = (string) Str::uuid();
        $response = [
            'production' => [
                'id' => $productionId,
                'buildingId' => $buildingId,
                'recipe' => $recipe['id'],
                'status' => 'pending',
                'output' => $recipe['output'],
                'version' => 1,
                'startedAt' => $startedAtTimestamp,
                'completesAt' => $completesAtTimestamp,
            ],
        ];

        try {
            $this->writeStartTransaction(
                playerId: $playerId,
                building: $building,
                recipe: $recipe,
                response: $response,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                timestamp: $startedAtTimestamp,
            );
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            return $this->recoverCanceledStartTransaction(
                playerId: $playerId,
                buildingId: $buildingId,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
            );
        }

        return $response;
    }

    /**
     * @param  array{id: string, type: string, version: int, activeProductionId: ?string}  $building
     * @param  array{
     *     id: string,
     *     buildingTypes: list<string>,
     *     durationSeconds: int,
     *     output: array{resource: string, quantity: int}
     * }  $recipe
     * @param  array{
     *     production: array{
     *         id: string,
     *         buildingId: string,
     *         recipe: string,
     *         status: string,
     *         output: array{resource: string, quantity: int},
     *         version: int,
     *         startedAt: string,
     *         completesAt: string
     *     }
     * }  $response
     */
    private function writeStartTransaction(
        string $playerId,
        array $building,
        array $recipe,
        array $response,
        string $idempotencyKey,
        string $requestHash,
        string $timestamp,
    ): void {
        $production = $response['production'];
        $eventId = (string) Str::uuid();
        $this->dynamoDb->transactWriteItems([
            'TransactItems' => [
                [
                    'Update' => [
                        'TableName' => $this->tableName('buildings'),
                        'Key' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'building_id' => $building['id'],
                        ]),
                        'UpdateExpression' => 'SET active_production_id = :production_id, #building_version = :building_version, updated_at = :updated_at',
                        'ConditionExpression' => '#building_type = :expected_building_type AND (#building_version = :expected_version OR attribute_not_exists(#building_version)) AND attribute_not_exists(active_production_id)',
                        'ExpressionAttributeNames' => [
                            '#building_type' => 'type',
                            '#building_version' => 'version',
                        ],
                        'ExpressionAttributeValues' => [
                            ':production_id' => $this->marshaler->marshalValue($production['id']),
                            ':building_version' => $this->marshaler->marshalValue($building['version'] + 1),
                            ':updated_at' => $this->marshaler->marshalValue($timestamp),
                            ':expected_building_type' => $this->marshaler->marshalValue($building['type']),
                            ':expected_version' => $this->marshaler->marshalValue($building['version']),
                        ],
                    ],
                ],
                [
                    'Put' => [
                        'TableName' => $this->tableName('productions'),
                        'Item' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'production_id' => $production['id'],
                            'schema_version' => self::SchemaVersion,
                            'building_id' => $building['id'],
                            'recipe' => $recipe['id'],
                            'status' => 'pending',
                            'output' => $recipe['output'],
                            'version' => 1,
                            'started_at' => $production['startedAt'],
                            'completes_at' => $production['completesAt'],
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]),
                        'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(production_id)',
                    ],
                ],
                [
                    'Put' => [
                        'TableName' => $this->tableName('commands'),
                        'Item' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'idempotency_key' => $idempotencyKey,
                            'schema_version' => self::SchemaVersion,
                            'command_type' => self::StartCommandType,
                            'request_hash' => $requestHash,
                            'response' => $response,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                            'expires_at' => now()->addSeconds($this->idempotencyTtlSeconds())->getTimestamp(),
                        ]),
                        'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(idempotency_key)',
                    ],
                ],
                [
                    'Put' => [
                        'TableName' => $this->tableName('outbox_events'),
                        'Item' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'event_id' => $eventId,
                            'schema_version' => self::SchemaVersion,
                            'event_type' => 'ProductionStarted.v1',
                            'occurred_at' => $timestamp,
                            'correlation_id' => $idempotencyKey,
                            'payload' => [
                                'production_id' => $production['id'],
                                'building_id' => $building['id'],
                                'recipe' => $recipe['id'],
                                'duration_seconds' => $recipe['durationSeconds'],
                                'completes_at' => $production['completesAt'],
                                'output' => $recipe['output'],
                            ],
                            'published_at' => null,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]),
                        'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(event_id)',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array{
     *     production: array{
     *         id: string,
     *         buildingId: string,
     *         recipe: string,
     *         status: string,
     *         output: array{resource: string, quantity: int},
     *         version: int,
     *         startedAt: string,
     *         completesAt: string
     *     }
     * }
     */
    private function recoverCanceledStartTransaction(
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

    /** @return array{id: string, type: string, version: int, activeProductionId: ?string}|null */
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
        $buildingType = $building['type'] ?? null;
        $buildingVersion = $building['version'] ?? 1;
        $activeProductionId = $building['active_production_id'] ?? null;

        if (
            ! is_string($buildingType) || $buildingType === ''
            || ! is_int($buildingVersion) || $buildingVersion < 1
            || ($activeProductionId !== null
                && (! is_string($activeProductionId) || $activeProductionId === ''))
        ) {
            throw new LogicException("Building [{$buildingId}] has an invalid production state.");
        }

        return [
            'id' => $buildingId,
            'type' => $buildingType,
            'version' => $buildingVersion,
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

    private function requestHash(string $buildingId, string $recipeId): string
    {
        return hash('sha256', self::StartCommandType."\n{$buildingId}\n{$recipeId}");
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
        string $buildingId,
        string $recipeId,
        string $idempotencyKey,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($buildingId === '') {
            throw new InvalidArgumentException('Building ID cannot be empty.');
        }

        if ($recipeId === '') {
            throw new InvalidArgumentException('Recipe ID cannot be empty.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }
}
