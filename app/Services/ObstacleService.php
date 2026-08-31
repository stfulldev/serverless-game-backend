<?php

namespace App\Services;

use App\Exceptions\GameStateConflictException;
use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\ObstacleAlreadyClearedException;
use App\Exceptions\ObstacleNotFoundException;
use App\Exceptions\PlayerNotFoundException;
use App\Game\MapCatalog;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ObstacleService
{
    private const string CommandType = 'ClearObstacle';

    private const int SchemaVersion = 1;

    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly PlayerService $players,
        private readonly MapCatalog $maps,
        private readonly DynamoDbClient $dynamoDb,
    ) {
        $this->marshaler = new Marshaler;
    }

    /**
     * @return array{
     *     obstacle: array{id: string, type: string, x: int, y: int, clearCost: int, clearedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
     */
    public function clear(string $playerId, string $obstacleId, string $idempotencyKey): array
    {
        $this->validateInput($playerId, $obstacleId, $idempotencyKey);
        $requestHash = $this->requestHash($obstacleId);
        $existingCommand = $this->getCommand($playerId, $idempotencyKey);

        if ($existingCommand !== null) {
            return $this->replayCommand($existingCommand, $requestHash);
        }

        $profile = $this->players->getProfile($playerId)
            ?? throw new PlayerNotFoundException;
        $obstacle = $this->maps->findObstacle($profile['map']['version'], $obstacleId)
            ?? throw new ObstacleNotFoundException;

        if ($this->getClearedObstacle($playerId, $obstacleId) !== null) {
            throw new ObstacleAlreadyClearedException;
        }

        $wallet = $profile['wallet'];

        if ($wallet['coins'] < $obstacle['clearCost']) {
            throw new InsufficientFundsException;
        }

        $timestamp = now()->utc()->toISOString();
        $response = [
            'obstacle' => [
                ...$obstacle,
                'clearedAt' => $timestamp,
            ],
            'wallet' => [
                'coins' => $wallet['coins'] - $obstacle['clearCost'],
                'resources' => $wallet['resources'],
                'version' => $wallet['version'] + 1,
            ],
        ];

        try {
            $this->writeClearTransaction(
                playerId: $playerId,
                mapVersion: $profile['map']['version'],
                mapSeed: $profile['map']['seed'],
                obstacle: $obstacle,
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

            return $this->recoverCanceledTransaction(
                $playerId,
                $obstacleId,
                $obstacle['clearCost'],
                $idempotencyKey,
                $requestHash,
            );
        }

        return $response;
    }

    /**
     * @param  array{id: string, type: string, x: int, y: int, clearCost: int}  $obstacle
     * @param  array{coins: int, resources: array<string, int>, version: int}  $currentWallet
     * @param  array{
     *     obstacle: array{id: string, type: string, x: int, y: int, clearCost: int, clearedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }  $response
     */
    private function writeClearTransaction(
        string $playerId,
        string $mapVersion,
        string $mapSeed,
        array $obstacle,
        array $currentWallet,
        array $response,
        string $idempotencyKey,
        string $requestHash,
        string $timestamp,
    ): void {
        $eventId = (string) Str::uuid();

        $this->dynamoDb->transactWriteItems([
            'TransactItems' => [
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
                        'TableName' => $this->tableName('cleared_obstacles'),
                        'Item' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'obstacle_id' => $obstacle['id'],
                            'schema_version' => self::SchemaVersion,
                            'map_version' => $mapVersion,
                            'map_seed' => $mapSeed,
                            'obstacle_type' => $obstacle['type'],
                            'x' => $obstacle['x'],
                            'y' => $obstacle['y'],
                            'clear_cost' => $obstacle['clearCost'],
                            'cleared_at' => $timestamp,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]),
                        'ConditionExpression' => 'attribute_not_exists(player_id) AND attribute_not_exists(obstacle_id)',
                    ],
                ],
                [
                    'Put' => [
                        'TableName' => $this->tableName('commands'),
                        'Item' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                            'idempotency_key' => $idempotencyKey,
                            'schema_version' => self::SchemaVersion,
                            'command_type' => self::CommandType,
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
                            'event_type' => 'ObstacleCleared.v1',
                            'occurred_at' => $timestamp,
                            'correlation_id' => $idempotencyKey,
                            'payload' => [
                                'obstacle_id' => $obstacle['id'],
                                'obstacle_type' => $obstacle['type'],
                                'x' => $obstacle['x'],
                                'y' => $obstacle['y'],
                                'clear_cost' => $obstacle['clearCost'],
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
     *     obstacle: array{id: string, type: string, x: int, y: int, clearCost: int, clearedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
     */
    private function recoverCanceledTransaction(
        string $playerId,
        string $obstacleId,
        int $clearCost,
        string $idempotencyKey,
        string $requestHash,
    ): array {
        $command = $this->getCommand($playerId, $idempotencyKey);

        if ($command !== null) {
            return $this->replayCommand($command, $requestHash);
        }

        if ($this->getClearedObstacle($playerId, $obstacleId) !== null) {
            throw new ObstacleAlreadyClearedException;
        }

        $profile = $this->players->getProfile($playerId)
            ?? throw new PlayerNotFoundException;

        if ($profile['wallet']['coins'] < $clearCost) {
            throw new InsufficientFundsException;
        }

        throw new GameStateConflictException;
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

    /** @return array<string, mixed>|null */
    private function getClearedObstacle(string $playerId, string $obstacleId): ?array
    {
        $result = $this->dynamoDb->getItem([
            'TableName' => $this->tableName('cleared_obstacles'),
            'Key' => $this->marshaler->marshalItem([
                'player_id' => $playerId,
                'obstacle_id' => $obstacleId,
            ]),
            'ConsistentRead' => true,
        ]);
        $item = $result->get('Item');

        if (! is_array($item)) {
            return null;
        }

        /** @var array<string, mixed> $clearedObstacle */
        $clearedObstacle = $this->marshaler->unmarshalItem($item);

        return $clearedObstacle;
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array{
     *     obstacle: array{id: string, type: string, x: int, y: int, clearCost: int, clearedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
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

        /** @var array{
         *     obstacle: array{id: string, type: string, x: int, y: int, clearCost: int, clearedAt: string},
         *     wallet: array{coins: int, resources: array<string, int>, version: int}
         * } $response
         */
        return $response;
    }

    private function requestHash(string $obstacleId): string
    {
        return hash('sha256', self::CommandType."\n".$obstacleId);
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
        string $obstacleId,
        string $idempotencyKey,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($obstacleId === '') {
            throw new InvalidArgumentException('Obstacle ID cannot be empty.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }
}
