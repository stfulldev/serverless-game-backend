<?php

namespace App\Console\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

#[Signature('dynamodb:setup-local')]
#[Description('Create the local DynamoDB game tables when they do not exist')]
final class SetupLocalDynamoDb extends Command
{
    private const array TableDefinitions = [
        'players' => [
            'partition_key' => 'player_id',
        ],
        'wallets' => [
            'partition_key' => 'player_id',
        ],
        'buildings' => [
            'partition_key' => 'player_id',
            'sort_key' => 'building_id',
        ],
        'productions' => [
            'partition_key' => 'player_id',
            'sort_key' => 'production_id',
        ],
        'occupied_cells' => [
            'partition_key' => 'player_id',
            'sort_key' => 'cell_id',
        ],
        'commands' => [
            'partition_key' => 'player_id',
            'sort_key' => 'idempotency_key',
            'ttl_attribute' => 'expires_at',
        ],
        'outbox_events' => [
            'partition_key' => 'player_id',
            'sort_key' => 'event_id',
            'stream_view_type' => 'NEW_AND_OLD_IMAGES',
            'ttl_attribute' => 'expires_at',
        ],
    ];

    public function handle(DynamoDbClient $dynamoDb): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('This command can only run in the local environment.');

            return self::FAILURE;
        }

        $endpoint = config('services.aws.dynamodb_endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            $this->error('DYNAMODB_ENDPOINT must point to DynamoDB Local.');

            return self::FAILURE;
        }

        $tableNames = $this->configuredTableNames();

        if ($tableNames === null) {
            return self::FAILURE;
        }

        foreach (self::TableDefinitions as $tableKey => $definition) {
            $this->setupTable($dynamoDb, $tableNames[$tableKey], $definition);
        }

        return self::SUCCESS;
    }

    /** @return array<string, string>|null */
    private function configuredTableNames(): ?array
    {
        $configuredTables = config('services.aws.dynamodb_tables');

        if (! is_array($configuredTables)) {
            $this->error('DynamoDB tables must be configured.');

            return null;
        }

        $tableNames = [];

        foreach (array_keys(self::TableDefinitions) as $tableKey) {
            $tableName = $configuredTables[$tableKey] ?? null;

            if (! is_string($tableName) || $tableName === '') {
                $this->error("DynamoDB table [{$tableKey}] must be configured.");

                return null;
            }

            $tableNames[$tableKey] = $tableName;
        }

        return $tableNames;
    }

    /** @param array<string, string> $definition */
    private function setupTable(DynamoDbClient $dynamoDb, string $tableName, array $definition): void
    {
        if ($this->tableExists($dynamoDb, $tableName)) {
            $this->info("DynamoDB table [{$tableName}] already exists.");
        } else {
            $this->createTable($dynamoDb, $tableName, $definition);
            $this->info("Created DynamoDB table [{$tableName}].");
        }

        $ttlAttribute = $definition['ttl_attribute'] ?? null;

        if (is_string($ttlAttribute)) {
            $this->enableTimeToLive($dynamoDb, $tableName, $ttlAttribute);
        }
    }

    /** @param array<string, string> $definition */
    private function createTable(DynamoDbClient $dynamoDb, string $tableName, array $definition): void
    {
        $attributeDefinitions = [
            ['AttributeName' => $definition['partition_key'], 'AttributeType' => 'S'],
        ];
        $keySchema = [
            ['AttributeName' => $definition['partition_key'], 'KeyType' => 'HASH'],
        ];
        $sortKey = $definition['sort_key'] ?? null;

        if (is_string($sortKey)) {
            $attributeDefinitions[] = ['AttributeName' => $sortKey, 'AttributeType' => 'S'];
            $keySchema[] = ['AttributeName' => $sortKey, 'KeyType' => 'RANGE'];
        }

        $request = [
            'AttributeDefinitions' => $attributeDefinitions,
            'BillingMode' => 'PAY_PER_REQUEST',
            'KeySchema' => $keySchema,
            'TableName' => $tableName,
        ];
        $streamViewType = $definition['stream_view_type'] ?? null;

        if (is_string($streamViewType)) {
            $request['StreamSpecification'] = [
                'StreamEnabled' => true,
                'StreamViewType' => $streamViewType,
            ];
        }

        $dynamoDb->createTable($request);
        $dynamoDb->waitUntil('TableExists', ['TableName' => $tableName]);
    }

    private function tableExists(DynamoDbClient $dynamoDb, string $tableName): bool
    {
        try {
            $dynamoDb->describeTable(['TableName' => $tableName]);

            return true;
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() === 'ResourceNotFoundException') {
                return false;
            }

            throw $exception;
        }
    }

    private function enableTimeToLive(
        DynamoDbClient $dynamoDb,
        string $tableName,
        string $attributeName,
    ): void {
        $description = $dynamoDb->describeTimeToLive([
            'TableName' => $tableName,
        ])->get('TimeToLiveDescription');
        $status = is_array($description) ? ($description['TimeToLiveStatus'] ?? 'DISABLED') : 'DISABLED';
        $configuredAttribute = is_array($description) ? ($description['AttributeName'] ?? null) : null;

        if (in_array($status, ['ENABLING', 'ENABLED'], true)) {
            if ($configuredAttribute !== $attributeName) {
                throw new LogicException(sprintf(
                    'DynamoDB TTL for [%s] uses [%s] instead of [%s].',
                    $tableName,
                    $configuredAttribute,
                    $attributeName,
                ));
            }

            $this->info("DynamoDB TTL for [{$tableName}] is already configured.");

            return;
        }

        if ($status === 'DISABLING') {
            throw new LogicException("DynamoDB TTL for [{$tableName}] is currently being disabled.");
        }

        $dynamoDb->updateTimeToLive([
            'TableName' => $tableName,
            'TimeToLiveSpecification' => [
                'AttributeName' => $attributeName,
                'Enabled' => true,
            ],
        ]);

        $this->info("Enabled DynamoDB TTL for [{$tableName}.{$attributeName}].");
    }
}
