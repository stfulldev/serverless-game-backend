<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\V1;

use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BuildingControllerTest extends TestCase
{
    private const string BuildingId = '11111111-1111-4111-8111-111111111111';

    private const string EventId = '22222222-2222-4222-8222-222222222222';

    private const string IdempotencyKey = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }

    public function test_returns_401_when_local_player_header_is_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('Idempotency-Key', self::IdempotencyKey)
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The X-Player-Id header is required for local requests.',
                ],
            ]);
    }

    public function test_returns_422_when_required_payload_and_idempotency_header_are_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->postJson('/api/v1/buildings');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['building_type', 'x', 'y', 'idempotencyKey'])
            ->assertJsonPath('errors.building_type.0', 'The building_type field is required.')
            ->assertJsonPath('errors.x.0', 'The x field is required.')
            ->assertJsonPath('errors.y.0', 'The y field is required.')
            ->assertJsonPath('errors.idempotencyKey.0', 'The Idempotency-Key header is required.');
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function test_returns_422_when_payload_fails_transport_validation(
        array $payload,
        string $field,
        string $message,
    ): void {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field])
            ->assertJsonPath("errors.{$field}.0", $message);
    }

    public function test_returns_422_when_idempotency_header_is_not_a_uuid(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders([
                'X-Player-Id' => 'player-123',
                'Idempotency-Key' => 'not-a-uuid',
            ])
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header must be a valid UUID.',
            );
    }

    public function test_places_building_atomically_and_returns_updated_wallet(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::BuildingId),
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result([
                'Responses' => $this->profileResponses(),
            ])),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', [
                ...$this->payload(),
                'player_id' => 'another-player',
            ]);

        $response
            ->assertCreated()
            ->assertExactJson([
                'data' => $this->placedBuildingResponse(),
            ]);

        $this->assertSame(
            ['GetItem', 'TransactGetItems', 'TransactWriteItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $transaction = $commands[2]['TransactItems'];
        $this->assertCount(8, $transaction);
        $this->assertSame('test-wallets', $transaction[0]['Update']['TableName']);
        $this->assertSame('test-buildings', $transaction[1]['Put']['TableName']);
        $this->assertSame(
            ['test-occupied-cells', 'test-occupied-cells', 'test-occupied-cells', 'test-occupied-cells'],
            array_map(
                static fn (array $item): string => $item['Put']['TableName'],
                array_slice($transaction, 2, 4),
            ),
        );
        $this->assertSame('test-commands', $transaction[6]['Put']['TableName']);
        $this->assertSame('test-outbox-events', $transaction[7]['Put']['TableName']);
        $this->assertSame(
            'coins = :expected_coins AND #wallet_version = :expected_wallet_version',
            $transaction[0]['Update']['ConditionExpression'],
        );

        $marshaler = new Marshaler;
        $building = $marshaler->unmarshalItem($transaction[1]['Put']['Item']);
        $cells = array_map(
            static fn (array $item): array => $marshaler->unmarshalItem($item['Put']['Item']),
            array_slice($transaction, 2, 4),
        );
        $command = $marshaler->unmarshalItem($transaction[6]['Put']['Item']);
        $outboxEvent = $marshaler->unmarshalItem($transaction[7]['Put']['Item']);

        $this->assertSame('player-123', $building['player_id']);
        $this->assertSame(self::BuildingId, $building['building_id']);
        $this->assertSame('garden-bed', $building['type']);
        $this->assertSame(2, $building['width']);
        $this->assertSame(2, $building['height']);
        $this->assertSame(1, $building['version']);
        $this->assertSame(200, $building['placement_cost']);
        $this->assertSame('2026-08-31T12:00:00.000000Z', $building['placed_at']);

        $this->assertSame(
            ['000#000', '001#000', '000#001', '001#001'],
            array_column($cells, 'cell_id'),
        );
        $this->assertSame(
            [self::BuildingId, self::BuildingId, self::BuildingId, self::BuildingId],
            array_column($cells, 'building_id'),
        );

        $this->assertSame(self::IdempotencyKey, $command['idempotency_key']);
        $this->assertSame('PlaceBuilding', $command['command_type']);
        $this->assertSame($this->requestHash(), $command['request_hash']);
        $this->assertSame($this->placedBuildingResponse(), $command['response']);
        $this->assertSame(1788782400, $command['expires_at']);

        $this->assertSame(self::EventId, $outboxEvent['event_id']);
        $this->assertSame('BuildingPlaced.v1', $outboxEvent['event_type']);
        $this->assertSame(self::IdempotencyKey, $outboxEvent['correlation_id']);
        $this->assertSame([
            'building_id' => self::BuildingId,
            'building_type' => 'garden-bed',
            'x' => 0,
            'y' => 0,
            'width' => 2,
            'height' => 2,
            'placement_cost' => 200,
        ], $outboxEvent['payload']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_places_building_on_a_cleared_obstacle(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::BuildingId),
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $marshaler = new Marshaler;
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result(['Responses' => $this->profileResponses()])),
            $this->record($commands, new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'obstacle_id' => 'tree-001',
                ]),
            ])),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload(x: 2, y: 3));

        $response->assertCreated();
        $transaction = $commands[3]['TransactItems'];
        $this->assertSame('test-cleared-obstacles', $transaction[6]['ConditionCheck']['TableName']);
        $this->assertSame(
            'attribute_exists(player_id) AND attribute_exists(obstacle_id)',
            $transaction[6]['ConditionCheck']['ConditionExpression'],
        );
        $this->assertCount(9, $transaction);
        $this->assertCount(0, $mockHandler);
    }

    public function test_replays_stored_response_for_the_same_idempotency_key(): void
    {
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'idempotency_key' => self::IdempotencyKey,
                    'request_hash' => $this->requestHash(),
                    'response' => $this->placedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertCreated()
            ->assertExactJson([
                'data' => $this->placedBuildingResponse(),
            ]);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_idempotency_key_was_used_for_another_request(): void
    {
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'idempotency_key' => self::IdempotencyKey,
                    'request_hash' => 'different-request',
                    'response' => $this->placedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'IDEMPOTENCY_KEY_REUSED',
                    'message' => 'The idempotency key has already been used for another request.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_404_when_player_state_does_not_exist(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => [[], []]]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'FARM_NOT_FOUND',
                    'message' => 'Farm not found.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_422_when_building_type_is_not_available(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload(buildingType: 'unknown'));

        $response
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVALID_PLACEMENT',
                    'message' => 'Building type is not available.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_422_when_footprint_is_outside_map_bounds(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload(x: 15, y: 15));

        $response
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVALID_PLACEMENT',
                    'message' => 'Building cannot be placed at the requested coordinates.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_footprint_overlaps_uncleared_obstacle(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
            new Result,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload(x: 2, y: 3));

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'CELLS_OCCUPIED',
                    'message' => 'One or more cells are occupied.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_wallet_has_insufficient_coins(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses(coins: 100)]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'INSUFFICIENT_FUNDS',
                    'message' => 'The wallet does not contain enough coins.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_concurrent_placement_occupies_a_cell(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::BuildingId),
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'cell_id' => '000#000',
                    'building_id' => 'other-building',
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'CELLS_OCCUPIED',
                    'message' => 'One or more cells are occupied.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_concurrent_state_change_cancels_transaction(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::BuildingId),
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result,
            new Result,
            new Result,
            new Result,
            new Result(['Responses' => $this->profileResponses(coins: 450)]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/buildings', $this->payload());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'GAME_STATE_CONFLICT',
                    'message' => 'The game state changed while processing the request. Retry the request.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    private function bindDynamoDb(MockHandler $mockHandler): void
    {
        $this->app->instance(DynamoDbClient::class, new DynamoDbClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => new Credentials('test', 'test'),
            'handler' => $mockHandler,
        ]));
        $this->useLocalAuthentication();
    }

    private function configureGame(): void
    {
        config()->set([
            'game.idempotency_ttl_seconds' => 604800,
            'game.max_building_footprint_cells' => 16,
            'game.buildings' => [
                'garden-bed' => [
                    'width' => 2,
                    'height' => 2,
                    'placement_cost' => 200,
                ],
            ],
            'game.maps' => [
                'v1' => [
                    'width' => 16,
                    'height' => 16,
                    'obstacles' => [
                        'tree-001' => [
                            'type' => 'tree',
                            'x' => 3,
                            'y' => 4,
                            'clear_cost' => 100,
                        ],
                    ],
                ],
            ],
            'services.aws.dynamodb_tables.players' => 'test-players',
            'services.aws.dynamodb_tables.wallets' => 'test-wallets',
            'services.aws.dynamodb_tables.buildings' => 'test-buildings',
            'services.aws.dynamodb_tables.occupied_cells' => 'test-occupied-cells',
            'services.aws.dynamodb_tables.cleared_obstacles' => 'test-cleared-obstacles',
            'services.aws.dynamodb_tables.commands' => 'test-commands',
            'services.aws.dynamodb_tables.outbox_events' => 'test-outbox-events',
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-Player-Id' => 'player-123',
            'Idempotency-Key' => self::IdempotencyKey,
        ];
    }

    /** @return array{building_type: string, x: int, y: int} */
    private function payload(string $buildingType = 'garden-bed', int $x = 0, int $y = 0): array
    {
        return [
            'building_type' => $buildingType,
            'x' => $x,
            'y' => $y,
        ];
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
     */
    private function placedBuildingResponse(): array
    {
        return [
            'building' => [
                'id' => self::BuildingId,
                'type' => 'garden-bed',
                'x' => 0,
                'y' => 0,
                'width' => 2,
                'height' => 2,
                'level' => 1,
                'version' => 1,
                'placedAt' => '2026-08-31T12:00:00.000000Z',
            ],
            'wallet' => [
                'coins' => 300,
                'resources' => [],
                'version' => 3,
            ],
        ];
    }

    /** @return array{array{Item: array<string, mixed>}, array{Item: array<string, mixed>}} */
    private function profileResponses(int $coins = 500): array
    {
        $marshaler = new Marshaler;

        return [
            ['Item' => $marshaler->marshalItem([
                'player_id' => 'player-123',
                'map_version' => 'v1',
                'map_seed' => 'seed-v1',
                'created_at' => '2026-08-01T10:00:00.000000Z',
                'updated_at' => '2026-08-02T10:00:00.000000Z',
            ])],
            ['Item' => $marshaler->marshalItem([
                'player_id' => 'player-123',
                'coins' => $coins,
                'resources' => [],
                'version' => 2,
            ])],
        ];
    }

    private function requestHash(): string
    {
        return hash('sha256', "PlaceBuilding\ngarden-bed\n0\n0");
    }

    /** @param array<int, CommandInterface> $commands */
    private function record(array &$commands, Result $result): Closure
    {
        return static function (CommandInterface $command) use (&$commands, $result): Result {
            $commands[] = $command;

            return $result;
        };
    }

    private function useLocalAuthentication(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'building type must be a string' => [
                ['building_type' => 123, 'x' => 0, 'y' => 0],
                'building_type',
                'The building_type field must be a string.',
            ],
            'building type length is limited' => [
                ['building_type' => str_repeat('a', 65), 'x' => 0, 'y' => 0],
                'building_type',
                'The building_type field must not be greater than 64 characters.',
            ],
            'building type uses canonical format' => [
                ['building_type' => 'Garden Bed', 'x' => 0, 'y' => 0],
                'building_type',
                'The building_type field may only contain lowercase letters, numbers, and dashes.',
            ],
            'x must be an integer' => [
                ['building_type' => 'garden-bed', 'x' => 'not-an-integer', 'y' => 0],
                'x',
                'The x field must be an integer.',
            ],
            'x must be inside transport range' => [
                ['building_type' => 'garden-bed', 'x' => -1, 'y' => 0],
                'x',
                'The x field must be between 0 and 999.',
            ],
            'y must be an integer' => [
                ['building_type' => 'garden-bed', 'x' => 0, 'y' => 'not-an-integer'],
                'y',
                'The y field must be an integer.',
            ],
            'y must be inside transport range' => [
                ['building_type' => 'garden-bed', 'x' => 0, 'y' => 1000],
                'y',
                'The y field must be between 0 and 999.',
            ],
        ];
    }
}
