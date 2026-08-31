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

final class BuildingMovementControllerTest extends TestCase
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
            ->patchJson($this->endpoint(), $this->payload());

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The X-Player-Id header is required for local requests.',
                ],
            ]);
    }

    public function test_returns_404_when_building_id_is_not_a_uuid(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson('/api/v1/buildings/not-a-uuid/move', $this->payload());

        $response->assertNotFound();
    }

    public function test_returns_422_when_coordinates_and_idempotency_header_are_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->patchJson($this->endpoint());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['x', 'y', 'idempotencyKey'])
            ->assertJsonPath('errors.x.0', 'The x field is required.')
            ->assertJsonPath('errors.y.0', 'The y field is required.')
            ->assertJsonPath('errors.idempotencyKey.0', 'The Idempotency-Key header is required.');
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function test_returns_422_when_coordinates_fail_transport_validation(
        array $payload,
        string $field,
        string $message,
    ): void {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $payload);

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
            ->patchJson($this->endpoint(), $this->payload());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header must be a valid UUID.',
            );
    }

    public function test_moves_building_atomically_and_only_replaces_changed_cells(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result(['Item' => $this->buildingItem()])),
            $this->record($commands, new Result(['Responses' => $this->profileResponses()])),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), [
                ...$this->payload(),
                'player_id' => 'another-player',
            ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->movedBuildingResponse(),
            ]);
        $this->assertSame(
            ['GetItem', 'GetItem', 'TransactGetItems', 'TransactWriteItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $marshaler = new Marshaler;
        $buildingKey = $marshaler->unmarshalItem($commands[1]['Key']);
        $this->assertSame([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
        ], $buildingKey);

        $transaction = $commands[3]['TransactItems'];
        $this->assertCount(9, $transaction);
        $this->assertSame('test-buildings', $transaction[0]['Update']['TableName']);
        $this->assertSame(
            'x = :expected_x AND y = :expected_y AND (#building_version = :expected_version OR attribute_not_exists(#building_version))',
            $transaction[0]['Update']['ConditionExpression'],
        );

        $releasedCells = array_map(
            static fn (array $item): array => $marshaler->unmarshalItem($item['Delete']['Key']),
            array_slice($transaction, 1, 2),
        );
        $retainedCells = array_map(
            static fn (array $item): array => $marshaler->unmarshalItem($item['ConditionCheck']['Key']),
            array_slice($transaction, 3, 2),
        );
        $reservedCells = array_map(
            static fn (array $item): array => $marshaler->unmarshalItem($item['Put']['Item']),
            array_slice($transaction, 5, 2),
        );

        $this->assertSame(['000#000', '000#001'], array_column($releasedCells, 'cell_id'));
        $this->assertSame(['001#000', '001#001'], array_column($retainedCells, 'cell_id'));
        $this->assertSame(['002#000', '002#001'], array_column($reservedCells, 'cell_id'));
        $this->assertSame(
            [self::BuildingId, self::BuildingId],
            array_column($reservedCells, 'building_id'),
        );

        $command = $marshaler->unmarshalItem($transaction[7]['Put']['Item']);
        $outboxEvent = $marshaler->unmarshalItem($transaction[8]['Put']['Item']);

        $this->assertSame('MoveBuilding', $command['command_type']);
        $this->assertSame($this->requestHash(), $command['request_hash']);
        $this->assertSame($this->movedBuildingResponse(), $command['response']);
        $this->assertSame(1788782400, $command['expires_at']);

        $this->assertSame(self::EventId, $outboxEvent['event_id']);
        $this->assertSame('BuildingMoved.v1', $outboxEvent['event_type']);
        $this->assertSame(self::IdempotencyKey, $outboxEvent['correlation_id']);
        $this->assertSame([
            'building_id' => self::BuildingId,
            'building_type' => 'garden-bed',
            'from' => ['x' => 0, 'y' => 0],
            'to' => ['x' => 1, 'y' => 0],
            'width' => 2,
            'height' => 2,
        ], $outboxEvent['payload']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_moves_building_onto_a_cleared_obstacle(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $marshaler = new Marshaler;
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result(['Item' => $this->buildingItem()])),
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
            ->patchJson($this->endpoint(), $this->payload(x: 2, y: 3));

        $response->assertOk();
        $transaction = $commands[4]['TransactItems'];
        $this->assertCount(12, $transaction);
        $this->assertSame('test-cleared-obstacles', $transaction[9]['ConditionCheck']['TableName']);
        $this->assertSame(
            'attribute_exists(player_id) AND attribute_exists(obstacle_id)',
            $transaction[9]['ConditionCheck']['ConditionExpression'],
        );
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
                    'response' => $this->movedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload());

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->movedBuildingResponse(),
            ]);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_replays_concurrently_stored_response_when_transaction_is_canceled(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            new Result(['Responses' => $this->profileResponses()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'idempotency_key' => self::IdempotencyKey,
                    'request_hash' => $this->requestHash(),
                    'response' => $this->movedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload());

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->movedBuildingResponse(),
            ]);
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
                    'response' => $this->movedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload());

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

    public function test_returns_404_without_revealing_building_owned_by_another_player(): void
    {
        $this->configureGame();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), [
                ...$this->payload(),
                'player_id' => 'building-owner',
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'BUILDING_NOT_FOUND',
                    'message' => 'Building not found.',
                ],
            ]);
        $marshaler = new Marshaler;
        $this->assertSame([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
        ], $marshaler->unmarshalItem($commands[1]['Key']));
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_422_when_building_is_already_at_requested_coordinates(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload(x: 0, y: 0));

        $response
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVALID_PLACEMENT',
                    'message' => 'Building is already at the requested coordinates.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_422_when_new_footprint_is_outside_map_bounds(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            new Result(['Responses' => $this->profileResponses()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload(x: 15, y: 15));

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

    public function test_returns_409_when_new_footprint_overlaps_uncleared_obstacle(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            new Result(['Responses' => $this->profileResponses()]),
            new Result,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload(x: 2, y: 3));

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

    public function test_returns_409_when_concurrent_movement_occupies_target_cell(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            new Result(['Responses' => $this->profileResponses()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'cell_id' => '001#000',
                    'building_id' => 'other-building',
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload());

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
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            new Result(['Responses' => $this->profileResponses()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result(['Item' => $this->buildingItem(version: 2)]),
            new Result,
            new Result,
            new Result,
            new Result,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->patchJson($this->endpoint(), $this->payload());

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

    /** @return array<string, mixed> */
    private function buildingItem(int $version = 1): array
    {
        return (new Marshaler)->marshalItem([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
            'type' => 'garden-bed',
            'x' => 0,
            'y' => 0,
            'width' => 2,
            'height' => 2,
            'level' => 1,
            'version' => $version,
            'placed_at' => '2026-08-30T12:00:00.000000Z',
        ]);
    }

    /** @return array{array{Item: array<string, mixed>}, array{Item: array<string, mixed>}} */
    private function profileResponses(): array
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
                'coins' => 500,
                'resources' => [],
                'version' => 2,
            ])],
        ];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-Player-Id' => 'player-123',
            'Idempotency-Key' => self::IdempotencyKey,
        ];
    }

    /** @return array{x: int, y: int} */
    private function payload(int $x = 1, int $y = 0): array
    {
        return ['x' => $x, 'y' => $y];
    }

    private function endpoint(): string
    {
        return '/api/v1/buildings/'.self::BuildingId.'/move';
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, movedAt: string}
     * }
     */
    private function movedBuildingResponse(): array
    {
        return [
            'building' => [
                'id' => self::BuildingId,
                'type' => 'garden-bed',
                'x' => 1,
                'y' => 0,
                'width' => 2,
                'height' => 2,
                'level' => 1,
                'version' => 2,
                'placedAt' => '2026-08-30T12:00:00.000000Z',
                'movedAt' => '2026-08-31T12:00:00.000000Z',
            ],
        ];
    }

    private function requestHash(): string
    {
        return hash('sha256', "MoveBuilding\n".self::BuildingId."\n1\n0");
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
            'x must be an integer' => [
                ['x' => 'not-an-integer', 'y' => 0],
                'x',
                'The x field must be an integer.',
            ],
            'x must be inside transport range' => [
                ['x' => -1, 'y' => 0],
                'x',
                'The x field must be between 0 and 999.',
            ],
            'y must be an integer' => [
                ['x' => 1, 'y' => 'not-an-integer'],
                'y',
                'The y field must be an integer.',
            ],
            'y must be inside transport range' => [
                ['x' => 1, 'y' => 1000],
                'y',
                'The y field must be between 0 and 999.',
            ],
        ];
    }
}
