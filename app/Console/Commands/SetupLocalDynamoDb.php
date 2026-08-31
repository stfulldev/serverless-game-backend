<?php

namespace App\Console\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dynamodb:setup-local')]
#[Description('Create the local DynamoDB game table when it does not exist')]
final class SetupLocalDynamoDb extends Command
{
    public function handle(DynamoDbClient $dynamoDb): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('This command can only run in the local environment.');

            return self::FAILURE;
        }

        $endpoint = config('services.aws.dynamodb_endpoint');
        $tableName = config('services.aws.dynamodb_table');

        if (! is_string($endpoint) || $endpoint === '') {
            $this->error('DYNAMODB_ENDPOINT must point to DynamoDB Local.');

            return self::FAILURE;
        }

        if (! is_string($tableName) || $tableName === '') {
            $this->error('DYNAMODB_TABLE must be configured.');

            return self::FAILURE;
        }

        if ($this->tableExists($dynamoDb, $tableName)) {
            $this->info("DynamoDB table [{$tableName}] already exists.");

            return self::SUCCESS;
        }

        $dynamoDb->createTable([
            'AttributeDefinitions' => [
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'S'],
            ],
            'BillingMode' => 'PAY_PER_REQUEST',
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'SK', 'KeyType' => 'RANGE'],
            ],
            'StreamSpecification' => [
                'StreamEnabled' => true,
                'StreamViewType' => 'NEW_AND_OLD_IMAGES',
            ],
            'TableName' => $tableName,
        ]);
        $dynamoDb->waitUntil('TableExists', ['TableName' => $tableName]);

        $this->info("Created DynamoDB table [{$tableName}].");

        return self::SUCCESS;
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
}
