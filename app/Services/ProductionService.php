<?php

namespace App\Services;

use App\Exceptions\BuildingHasActiveProductionException;
use App\Exceptions\BuildingNotFoundException;
use App\Exceptions\GameStateConflictException;
use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\InvalidRecipeException;
use App\Exceptions\ProductionAlreadyCollectedException;
use App\Exceptions\ProductionNotFoundException;
use App\Exceptions\ProductionNotReadyException;
use App\Game\RecipeCatalog;
use App\Services\Aws\SchedulerService;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ProductionService
{
    private const int SchemaVersion = 1;

    private const string StartCommandType = 'StartProduction';

    private const string CollectCommandType = 'CollectProduction';

    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly RecipeCatalog $recipes,
        private readonly DynamoDbClient $dynamoDb,
        private readonly SchedulerService $scheduler,
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

        $this->scheduler->scheduleProductionCompletion(
            playerId: $playerId,
            productionId: $productionId,
            correlationId: $idempotencyKey,
            completesAt: $completesAtTimestamp,
        );

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
     * @return array{id: string, status: string, completedAt: ?string}|null
     */
    public function complete(
        string $playerId,
        string $productionId,
        string $correlationId,
    ): ?array {
        $this->validateCompletionInput($playerId, $productionId, $correlationId);
        $production = $this->getProduction($playerId, $productionId);

        if ($production === null) {
            return null;
        }

        if ($production['status'] !== 'pending') {
            return $this->completionResponse($production);
        }

        $completedAt = CarbonImmutable::now('UTC');

        if (CarbonImmutable::parse($production['completesAt'])->utc()->isAfter($completedAt)) {
            throw new ProductionNotReadyException;
        }

        $completedAtTimestamp = $completedAt->toISOString();

        try {
            $this->writeCompletionTransaction(
                playerId: $playerId,
                production: $production,
                correlationId: $correlationId,
                completedAt: $completedAtTimestamp,
            );
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            return $this->recoverCanceledCompletionTransaction(
                playerId: $playerId,
                productionId: $productionId,
                completedAt: $completedAt,
            );
        }

        return [
            'id' => $productionId,
            'status' => 'completed',
            'completedAt' => $completedAtTimestamp,
        ];
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
     *         completedAt: string,
     *         collectedAt: string
     *     }
     * }
     */
    public function collect(
        string $playerId,
        string $productionId,
        string $idempotencyKey,
    ): array {
        $this->validateCollectionInput($playerId, $productionId, $idempotencyKey);
        $requestHash = $this->collectionRequestHash($productionId);
        $existingCommand = $this->getCommand($playerId, $idempotencyKey);

        if ($existingCommand !== null) {
            return $this->replayCommand($existingCommand, $requestHash);
        }

        $production = $this->getProduction($playerId, $productionId)
            ?? throw new ProductionNotFoundException;

        if ($production['status'] === 'collected') {
            throw new ProductionAlreadyCollectedException;
        }

        $collectedAt = CarbonImmutable::now('UTC');

        if (CarbonImmutable::parse($production['completesAt'])->utc()->isAfter($collectedAt)) {
            throw new ProductionNotReadyException;
        }

        $collectedAtTimestamp = $collectedAt->toISOString();
        $completedAtTimestamp = $production['completedAt'] ?? $collectedAtTimestamp;
        $response = [
            'production' => [
                'id' => $production['id'],
                'buildingId' => $production['buildingId'],
                'recipe' => $production['recipe'],
                'status' => 'collected',
                'output' => $production['output'],
                'version' => $production['version'] + 1,
                'completedAt' => $completedAtTimestamp,
                'collectedAt' => $collectedAtTimestamp,
            ],
        ];

        try {
            $this->writeCollectionTransaction(
                playerId: $playerId,
                production: $production,
                response: $response,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                completedAt: $completedAtTimestamp,
                collectedAt: $collectedAtTimestamp,
            );
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            return $this->recoverCanceledCollectionTransaction(
                playerId: $playerId,
                productionId: $productionId,
                idempotencyKey: $idempotencyKey,
                requestHash: $requestHash,
                collectedAt: $collectedAt,
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

    /**
     * @param  array{
     *     id: string,
     *     buildingId: string,
     *     recipe: string,
     *     status: string,
     *     output: array{resource: string, quantity: int},
     *     version: int,
     *     completesAt: string,
     *     completedAt: ?string
     * }  $production
     */
    private function writeCompletionTransaction(
        string $playerId,
        array $production,
        string $correlationId,
        string $completedAt,
    ): void {
        $eventId = (string) Str::uuid();
        $this->dynamoDb->transactWriteItems([
            'TransactItems' => [
                [
                    'Update' => [
                        'TableName' => $this->tableName('productions'),
                        'Key' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'production_id' => $production['id'],
                        ]),
                        'UpdateExpression' => 'SET #production_status = :completed, completed_at = :completed_at, #production_version = :next_version, updated_at = :updated_at',
                        'ConditionExpression' => '#production_status = :pending AND #production_version = :expected_version AND completes_at = :expected_completes_at AND completes_at <= :completed_at',
                        'ExpressionAttributeNames' => [
                            '#production_status' => 'status',
                            '#production_version' => 'version',
                        ],
                        'ExpressionAttributeValues' => [
                            ':completed' => $this->marshaler->marshalValue('completed'),
                            ':completed_at' => $this->marshaler->marshalValue($completedAt),
                            ':next_version' => $this->marshaler->marshalValue(
                                $production['version'] + 1,
                            ),
                            ':updated_at' => $this->marshaler->marshalValue($completedAt),
                            ':pending' => $this->marshaler->marshalValue('pending'),
                            ':expected_version' => $this->marshaler->marshalValue(
                                $production['version'],
                            ),
                            ':expected_completes_at' => $this->marshaler->marshalValue(
                                $production['completesAt'],
                            ),
                        ],
                    ],
                ],
                [
                    'Put' => [
                        'TableName' => $this->tableName('outbox_events'),
                        'Item' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'event_id' => $eventId,
                            'schema_version' => self::SchemaVersion,
                            'event_type' => 'ProductionCompleted.v1',
                            'occurred_at' => $completedAt,
                            'correlation_id' => $correlationId,
                            'payload' => [
                                'production_id' => $production['id'],
                                'building_id' => $production['buildingId'],
                                'recipe' => $production['recipe'],
                                'completed_at' => $completedAt,
                                'output' => $production['output'],
                            ],
                            'published_at' => null,
                            'created_at' => $completedAt,
                            'updated_at' => $completedAt,
                        ]),
                        'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(event_id)',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array{id: string, status: string, completedAt: ?string}|null
     */
    private function recoverCanceledCompletionTransaction(
        string $playerId,
        string $productionId,
        CarbonImmutable $completedAt,
    ): ?array {
        $production = $this->getProduction($playerId, $productionId);

        if ($production === null) {
            return null;
        }

        if ($production['status'] !== 'pending') {
            return $this->completionResponse($production);
        }

        if (CarbonImmutable::parse($production['completesAt'])->utc()->isAfter($completedAt)) {
            throw new ProductionNotReadyException;
        }

        throw new GameStateConflictException;
    }

    /**
     * @param  array{
     *     id: string,
     *     buildingId: string,
     *     recipe: string,
     *     status: string,
     *     output: array{resource: string, quantity: int},
     *     version: int,
     *     completesAt: string,
     *     completedAt: ?string,
     *     collectedAt: ?string
     * }  $production
     * @param  array{
     *     production: array{
     *         id: string,
     *         buildingId: string,
     *         recipe: string,
     *         status: string,
     *         output: array{resource: string, quantity: int},
     *         version: int,
     *         completedAt: string,
     *         collectedAt: string
     *     }
     * }  $response
     */
    private function writeCollectionTransaction(
        string $playerId,
        array $production,
        array $response,
        string $idempotencyKey,
        string $requestHash,
        string $completedAt,
        string $collectedAt,
    ): void {
        $transactItems = [
            [
                'Update' => [
                    'TableName' => $this->tableName('productions'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'production_id' => $production['id'],
                    ]),
                    'UpdateExpression' => 'SET #production_status = :collected, completed_at = if_not_exists(completed_at, :completed_at), collected_at = :collected_at, #production_version = :next_version, updated_at = :updated_at',
                    'ConditionExpression' => '#production_status = :expected_status AND #production_version = :expected_version AND completes_at = :expected_completes_at AND completes_at <= :collected_at AND attribute_not_exists(collected_at)',
                    'ExpressionAttributeNames' => [
                        '#production_status' => 'status',
                        '#production_version' => 'version',
                    ],
                    'ExpressionAttributeValues' => [
                        ':collected' => $this->marshaler->marshalValue('collected'),
                        ':completed_at' => $this->marshaler->marshalValue($completedAt),
                        ':collected_at' => $this->marshaler->marshalValue($collectedAt),
                        ':next_version' => $this->marshaler->marshalValue(
                            $production['version'] + 1,
                        ),
                        ':updated_at' => $this->marshaler->marshalValue($collectedAt),
                        ':expected_status' => $this->marshaler->marshalValue(
                            $production['status'],
                        ),
                        ':expected_version' => $this->marshaler->marshalValue(
                            $production['version'],
                        ),
                        ':expected_completes_at' => $this->marshaler->marshalValue(
                            $production['completesAt'],
                        ),
                    ],
                ],
            ],
            [
                'Update' => [
                    'TableName' => $this->tableName('wallets'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                    ]),
                    'UpdateExpression' => 'SET #resources.#resource_name = if_not_exists(#resources.#resource_name, :zero) + :quantity, #wallet_version = if_not_exists(#wallet_version, :zero) + :one, updated_at = :updated_at',
                    'ConditionExpression' => 'attribute_exists(player_id) AND attribute_type(#resources, :resources_type)',
                    'ExpressionAttributeNames' => [
                        '#resources' => 'resources',
                        '#resource_name' => $production['output']['resource'],
                        '#wallet_version' => 'version',
                    ],
                    'ExpressionAttributeValues' => [
                        ':zero' => $this->marshaler->marshalValue(0),
                        ':quantity' => $this->marshaler->marshalValue(
                            $production['output']['quantity'],
                        ),
                        ':one' => $this->marshaler->marshalValue(1),
                        ':updated_at' => $this->marshaler->marshalValue($collectedAt),
                        ':resources_type' => $this->marshaler->marshalValue('M'),
                    ],
                ],
            ],
            [
                'Update' => [
                    'TableName' => $this->tableName('buildings'),
                    'Key' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'building_id' => $production['buildingId'],
                    ]),
                    'UpdateExpression' => 'SET #building_version = if_not_exists(#building_version, :zero) + :one, updated_at = :updated_at REMOVE active_production_id',
                    'ConditionExpression' => 'active_production_id = :production_id',
                    'ExpressionAttributeNames' => [
                        '#building_version' => 'version',
                    ],
                    'ExpressionAttributeValues' => [
                        ':zero' => $this->marshaler->marshalValue(0),
                        ':one' => $this->marshaler->marshalValue(1),
                        ':updated_at' => $this->marshaler->marshalValue($collectedAt),
                        ':production_id' => $this->marshaler->marshalValue($production['id']),
                    ],
                ],
            ],
            [
                'Put' => [
                    'TableName' => $this->tableName('commands'),
                    'Item' => $this->marshaler->marshalItem([
                        'player_id' => $playerId,
                        'idempotency_key' => $idempotencyKey,
                        'schema_version' => self::SchemaVersion,
                        'command_type' => self::CollectCommandType,
                        'request_hash' => $requestHash,
                        'response' => $response,
                        'created_at' => $collectedAt,
                        'updated_at' => $collectedAt,
                        'expires_at' => now()
                            ->addSeconds($this->idempotencyTtlSeconds())
                            ->getTimestamp(),
                    ]),
                    'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(idempotency_key)',
                ],
            ],
        ];

        if ($production['status'] === 'pending') {
            $transactItems[] = $this->outboxPut(
                playerId: $playerId,
                eventId: (string) Str::uuid(),
                eventType: 'ProductionCompleted.v1',
                correlationId: $idempotencyKey,
                timestamp: $collectedAt,
                payload: [
                    'production_id' => $production['id'],
                    'building_id' => $production['buildingId'],
                    'recipe' => $production['recipe'],
                    'completed_at' => $completedAt,
                    'output' => $production['output'],
                ],
            );
        }

        $transactItems[] = $this->outboxPut(
            playerId: $playerId,
            eventId: (string) Str::uuid(),
            eventType: 'ProductionCollected.v1',
            correlationId: $idempotencyKey,
            timestamp: $collectedAt,
            payload: [
                'production_id' => $production['id'],
                'building_id' => $production['buildingId'],
                'recipe' => $production['recipe'],
                'completed_at' => $completedAt,
                'collected_at' => $collectedAt,
                'output' => $production['output'],
            ],
        );

        $this->dynamoDb->transactWriteItems([
            'TransactItems' => $transactItems,
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
     *         completedAt: string,
     *         collectedAt: string
     *     }
     * }
     */
    private function recoverCanceledCollectionTransaction(
        string $playerId,
        string $productionId,
        string $idempotencyKey,
        string $requestHash,
        CarbonImmutable $collectedAt,
    ): array {
        $command = $this->getCommand($playerId, $idempotencyKey);

        if ($command !== null) {
            return $this->replayCommand($command, $requestHash);
        }

        $production = $this->getProduction($playerId, $productionId)
            ?? throw new ProductionNotFoundException;

        if ($production['status'] === 'collected') {
            throw new ProductionAlreadyCollectedException;
        }

        if (CarbonImmutable::parse($production['completesAt'])->utc()->isAfter($collectedAt)) {
            throw new ProductionNotReadyException;
        }

        throw new GameStateConflictException;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{Put: array<string, mixed>}
     */
    private function outboxPut(
        string $playerId,
        string $eventId,
        string $eventType,
        string $correlationId,
        string $timestamp,
        array $payload,
    ): array {
        return [
            'Put' => [
                'TableName' => $this->tableName('outbox_events'),
                'Item' => $this->marshaler->marshalItem([
                    'player_id' => $playerId,
                    'event_id' => $eventId,
                    'schema_version' => self::SchemaVersion,
                    'event_type' => $eventType,
                    'occurred_at' => $timestamp,
                    'correlation_id' => $correlationId,
                    'payload' => $payload,
                    'published_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]),
                'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(event_id)',
            ],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     buildingId: string,
     *     recipe: string,
     *     status: string,
     *     output: array{resource: string, quantity: int},
     *     version: int,
     *     completesAt: string,
     *     completedAt: ?string,
     *     collectedAt: ?string
     * }|null
     */
    private function getProduction(string $playerId, string $productionId): ?array
    {
        $result = $this->dynamoDb->getItem([
            'TableName' => $this->tableName('productions'),
            'Key' => $this->marshaler->marshalItem([
                'player_id' => $playerId,
                'production_id' => $productionId,
            ]),
            'ConsistentRead' => true,
        ]);
        $item = $result->get('Item');

        if (! is_array($item)) {
            return null;
        }

        /** @var array<string, mixed> $production */
        $production = $this->marshaler->unmarshalItem($item);
        $buildingId = $production['building_id'] ?? null;
        $recipe = $production['recipe'] ?? null;
        $status = $production['status'] ?? null;
        $output = $production['output'] ?? null;
        $version = $production['version'] ?? null;
        $completesAt = $production['completes_at'] ?? null;
        $completedAt = $production['completed_at'] ?? null;
        $collectedAt = $production['collected_at'] ?? null;
        $outputResource = is_array($output) ? ($output['resource'] ?? null) : null;
        $outputQuantity = is_array($output) ? ($output['quantity'] ?? null) : null;

        if (
            ! is_string($buildingId) || $buildingId === ''
            || ! is_string($recipe) || $recipe === ''
            || ! is_string($status) || ! in_array($status, ['pending', 'completed', 'collected'], true)
            || ! is_string($outputResource) || $outputResource === ''
            || ! is_int($outputQuantity) || $outputQuantity < 1
            || ! is_int($version) || $version < 1
            || ! is_string($completesAt) || $completesAt === ''
            || ($completedAt !== null && (! is_string($completedAt) || $completedAt === ''))
            || ($collectedAt !== null && (! is_string($collectedAt) || $collectedAt === ''))
            || ($status !== 'pending' && $completedAt === null)
            || ($status !== 'collected' && $collectedAt !== null)
            || ($status === 'collected' && $collectedAt === null)
        ) {
            throw new LogicException("Production [{$productionId}] has invalid state.");
        }

        return [
            'id' => $productionId,
            'buildingId' => $buildingId,
            'recipe' => $recipe,
            'status' => $status,
            'output' => [
                'resource' => $outputResource,
                'quantity' => $outputQuantity,
            ],
            'version' => $version,
            'completesAt' => $completesAt,
            'completedAt' => $completedAt,
            'collectedAt' => $collectedAt,
        ];
    }

    /**
     * @param  array{id: string, status: string, completedAt: ?string}  $production
     * @return array{id: string, status: string, completedAt: ?string}
     */
    private function completionResponse(array $production): array
    {
        return [
            'id' => $production['id'],
            'status' => $production['status'],
            'completedAt' => $production['completedAt'],
        ];
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

    private function collectionRequestHash(string $productionId): string
    {
        return hash('sha256', self::CollectCommandType."\n{$productionId}");
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

    private function validateCompletionInput(
        string $playerId,
        string $productionId,
        string $correlationId,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($productionId === '') {
            throw new InvalidArgumentException('Production ID cannot be empty.');
        }

        if ($correlationId === '') {
            throw new InvalidArgumentException('Correlation ID cannot be empty.');
        }
    }

    private function validateCollectionInput(
        string $playerId,
        string $productionId,
        string $idempotencyKey,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($productionId === '') {
            throw new InvalidArgumentException('Production ID cannot be empty.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }
}
