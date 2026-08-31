<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\V1;

use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use Tests\TestCase;

final class FarmControllerTest extends TestCase
{
    public function test_returns_401_when_local_player_header_is_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this->getJson('/api/v1/farm');

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The X-Player-Id header is required for local requests.',
                ],
            ]);
    }

    public function test_returns_404_when_player_state_does_not_exist(): void
    {
        $this->useLocalAuthentication();
        $this->configureTables();
        $this->app->instance(DynamoDbClient::class, $this->dynamoDb(new MockHandler([
            new Result(['Responses' => [[], []]]),
        ])));

        $response = $this
            ->withHeader('X-Player-Id', 'player-404')
            ->getJson('/api/v1/farm');

        $response
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Farm not found.',
                ],
            ]);
    }

    public function test_returns_complete_farm_for_authenticated_local_player(): void
    {
        $this->useLocalAuthentication();
        $this->configureTables();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result([
                'Responses' => [
                    ['Item' => $marshaler->marshalItem([
                        'player_id' => 'player-123',
                        'map_version' => 'map-v1',
                        'map_seed' => 'seed-v1',
                        'created_at' => '2026-08-01T10:00:00.000000Z',
                        'updated_at' => '2026-08-02T10:00:00.000000Z',
                    ])],
                    ['Item' => $marshaler->marshalItem([
                        'player_id' => 'player-123',
                        'coins' => 125,
                        'resources' => ['wheat' => 3],
                    ])],
                ],
            ]),
            new Result([
                'Items' => [
                    $marshaler->marshalItem([
                        'player_id' => 'player-123',
                        'building_id' => 'building-1',
                        'schema_version' => 1,
                        'type' => 'garden-bed',
                        'x' => 4,
                        'y' => 7,
                    ]),
                ],
            ]),
            new Result([
                'Items' => [
                    $marshaler->marshalItem([
                        'player_id' => 'player-123',
                        'production_id' => 'production-1',
                        'schema_version' => 1,
                        'recipe' => 'wheat',
                        'status' => 'running',
                    ]),
                ],
            ]),
        ]);
        $this->app->instance(DynamoDbClient::class, $this->dynamoDb($mockHandler));

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->getJson('/api/v1/farm');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'playerId' => 'player-123',
                    'map' => [
                        'version' => 'map-v1',
                        'seed' => 'seed-v1',
                    ],
                    'wallet' => [
                        'coins' => 125,
                        'resources' => ['wheat' => 3],
                    ],
                    'buildings' => [
                        [
                            'buildingId' => 'building-1',
                            'type' => 'garden-bed',
                            'x' => 4,
                            'y' => 7,
                        ],
                    ],
                    'productions' => [
                        [
                            'productionId' => 'production-1',
                            'recipe' => 'wheat',
                            'status' => 'running',
                        ],
                    ],
                    'createdAt' => '2026-08-01T10:00:00.000000Z',
                    'updatedAt' => '2026-08-02T10:00:00.000000Z',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    private function dynamoDb(MockHandler $mockHandler): DynamoDbClient
    {
        return new DynamoDbClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => new Credentials('test', 'test'),
            'handler' => $mockHandler,
        ]);
    }

    private function useLocalAuthentication(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
    }

    private function configureTables(): void
    {
        config()->set([
            'services.aws.dynamodb_tables.players' => 'test-players',
            'services.aws.dynamodb_tables.wallets' => 'test-wallets',
            'services.aws.dynamodb_tables.buildings' => 'test-buildings',
            'services.aws.dynamodb_tables.productions' => 'test-productions',
        ]);
    }
}
