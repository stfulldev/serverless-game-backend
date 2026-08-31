<?php

namespace App\Services;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use stdClass;

final class PlayerService
{
    private const int SchemaVersion = 1;

    private readonly Marshaler $marshaler;

    public function __construct(private readonly DynamoDbClient $dynamoDb)
    {
        $this->marshaler = new Marshaler;
    }

    /**
     * @return array{
     *     playerId: string,
     *     map: array{version: string, seed: string},
     *     wallet: array{coins: int, resources: array<string, int>},
     *     createdAt: string,
     *     updatedAt: string
     * }
     */
    public function setupPlayer(
        string $playerId,
        string $mapVersion,
        string $mapSeed,
        int $startingCoins,
    ): array {
        $this->validateSetupInput($playerId, $mapVersion, $mapSeed, $startingCoins);

        $existingProfile = $this->getProfile($playerId);

        if ($existingProfile !== null) {
            return $existingProfile;
        }

        $timestamp = now()->utc()->toISOString();
        $eventId = (string) Str::uuid();

        try {
            $this->dynamoDb->transactWriteItems([
                'TransactItems' => [
                    [
                        'Put' => [
                            'TableName' => $this->tableName('players'),
                            'Item' => $this->marshaler->marshalItem([
                                'player_id' => $playerId,
                                'schema_version' => self::SchemaVersion,
                                'map_version' => $mapVersion,
                                'map_seed' => $mapSeed,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]),
                            'ConditionExpression' => 'attribute_not_exists(player_id)',
                        ],
                    ],
                    [
                        'Put' => [
                            'TableName' => $this->tableName('wallets'),
                            'Item' => $this->marshaler->marshalItem([
                                'player_id' => $playerId,
                                'schema_version' => self::SchemaVersion,
                                'coins' => $startingCoins,
                                'resources' => new stdClass,
                                'version' => 1,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]),
                            'ConditionExpression' => 'attribute_not_exists(player_id)',
                        ],
                    ],
                    [
                        'Put' => [
                            'TableName' => $this->tableName('outbox_events'),
                            'Item' => $this->marshaler->marshalItem([
                                'player_id' => $playerId,
                                'event_id' => $eventId,
                                'schema_version' => self::SchemaVersion,
                                'event_type' => 'PlayerCreated.v1',
                                'occurred_at' => $timestamp,
                                'correlation_id' => (string) Str::uuid(),
                                'payload' => [
                                    'map_version' => $mapVersion,
                                ],
                                'published_at' => null,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]),
                            'ConditionExpression' => 'attribute_not_exists(player_id)',
                        ],
                    ],
                ],
            ]);
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }

            $existingProfile = $this->getProfile($playerId);

            if ($existingProfile === null) {
                throw $exception;
            }

            return $existingProfile;
        }

        return $this->getProfile($playerId)
            ?? throw new LogicException("Player [{$playerId}] was not readable after setup.");
    }

    /**
     * @return array{
     *     playerId: string,
     *     map: array{version: string, seed: string},
     *     wallet: array{coins: int, resources: array<string, int>},
     *     createdAt: string,
     *     updatedAt: string
     * }|null
     */
    public function getProfile(string $playerId): ?array
    {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        $result = $this->dynamoDb->transactGetItems([
            'TransactItems' => [
                [
                    'Get' => [
                        'TableName' => $this->tableName('players'),
                        'Key' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                        ]),
                    ],
                ],
                [
                    'Get' => [
                        'TableName' => $this->tableName('wallets'),
                        'Key' => $this->marshaler->marshalItem([
                            'player_id' => $playerId,
                        ]),
                    ],
                ],
            ],
        ]);

        $responses = $result->get('Responses');
        $metaItem = is_array($responses) ? ($responses[0]['Item'] ?? null) : null;
        $walletItem = is_array($responses) ? ($responses[1]['Item'] ?? null) : null;

        if ($metaItem === null && $walletItem === null) {
            return null;
        }

        if (! is_array($metaItem) || ! is_array($walletItem)) {
            throw new LogicException("Player [{$playerId}] has an incomplete farm state.");
        }

        /** @var array<string, mixed> $meta */
        $meta = $this->marshaler->unmarshalItem($metaItem);
        /** @var array<string, mixed> $wallet */
        $wallet = $this->marshaler->unmarshalItem($walletItem);
        $resources = $wallet['resources'] ?? [];

        if (! is_array($resources)) {
            throw new LogicException("Player [{$playerId}] has an invalid wallet state.");
        }

        /** @var array<string, int> $resources */
        return [
            'playerId' => (string) $meta['player_id'],
            'map' => [
                'version' => (string) $meta['map_version'],
                'seed' => (string) $meta['map_seed'],
            ],
            'wallet' => [
                'coins' => (int) $wallet['coins'],
                'resources' => $resources,
            ],
            'createdAt' => (string) $meta['created_at'],
            'updatedAt' => (string) $meta['updated_at'],
        ];
    }

    private function tableName(string $tableKey): string
    {
        $tableName = config("services.aws.dynamodb_tables.{$tableKey}");

        if (! is_string($tableName) || $tableName === '') {
            throw new LogicException("DynamoDB table [{$tableKey}] must be configured.");
        }

        return $tableName;
    }

    private function validateSetupInput(
        string $playerId,
        string $mapVersion,
        string $mapSeed,
        int $startingCoins,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($mapVersion === '') {
            throw new InvalidArgumentException('Map version cannot be empty.');
        }

        if ($mapSeed === '') {
            throw new InvalidArgumentException('Map seed cannot be empty.');
        }

        if ($startingCoins < 0) {
            throw new InvalidArgumentException('Starting coins cannot be negative.');
        }
    }
}
