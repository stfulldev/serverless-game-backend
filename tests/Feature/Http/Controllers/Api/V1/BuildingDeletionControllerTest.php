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
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class BuildingDeletionControllerTest extends TestCase
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
            ->deleteJson($this->endpoint());

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
            ->deleteJson('/api/v1/buildings/not-a-uuid');

        $response->assertNotFound();
    }

    public function test_returns_422_when_idempotency_header_is_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->deleteJson($this->endpoint());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header is required.',
            );
    }

    public function test_returns_422_when_idempotency_header_is_not_a_uuid(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders([
                'X-Player-Id' => 'player-123',
                'Idempotency-Key' => 'not-a-uuid',
            ])
            ->deleteJson($this->endpoint());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header must be a valid UUID.',
            );
    }

    public function test_deletes_building_and_occupied_cells_atomically(): void
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
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint(), ['player_id' => 'another-player']);

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->deletedBuildingResponse(),
            ]);
        $this->assertSame(
            ['GetItem', 'GetItem', 'TransactWriteItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $marshaler = new Marshaler;
        $this->assertSame([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
        ], $marshaler->unmarshalItem($commands[1]['Key']));

        $transaction = $commands[2]['TransactItems'];
        $this->assertCount(7, $transaction);
        $this->assertSame('test-buildings', $transaction[0]['Delete']['TableName']);
        $this->assertSame(
            'x = :expected_x AND y = :expected_y AND (#building_version = :expected_version OR attribute_not_exists(#building_version)) AND attribute_not_exists(active_production_id)',
            $transaction[0]['Delete']['ConditionExpression'],
        );
        $this->assertSame([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
        ], $marshaler->unmarshalItem($transaction[0]['Delete']['Key']));

        $deletedCells = array_map(
            static fn (array $item): array => $marshaler->unmarshalItem($item['Delete']['Key']),
            array_slice($transaction, 1, 4),
        );
        $this->assertSame(
            ['000#000', '001#000', '000#001', '001#001'],
            array_column($deletedCells, 'cell_id'),
        );

        foreach (array_slice($transaction, 1, 4) as $cellDeletion) {
            $this->assertSame('test-occupied-cells', $cellDeletion['Delete']['TableName']);
            $this->assertSame('building_id = :building_id', $cellDeletion['Delete']['ConditionExpression']);
            $this->assertSame(
                self::BuildingId,
                $marshaler->unmarshalValue(
                    $cellDeletion['Delete']['ExpressionAttributeValues'][':building_id'],
                ),
            );
        }

        $command = $marshaler->unmarshalItem($transaction[5]['Put']['Item']);
        $outboxEvent = $marshaler->unmarshalItem($transaction[6]['Put']['Item']);

        $this->assertSame('DeleteBuilding', $command['command_type']);
        $this->assertSame($this->requestHash(), $command['request_hash']);
        $this->assertSame($this->deletedBuildingResponse(), $command['response']);
        $this->assertSame(1788782400, $command['expires_at']);

        $this->assertSame(self::EventId, $outboxEvent['event_id']);
        $this->assertSame('BuildingDeleted.v1', $outboxEvent['event_type']);
        $this->assertSame(self::IdempotencyKey, $outboxEvent['correlation_id']);
        $this->assertSame([
            'building_id' => self::BuildingId,
            'building_type' => 'garden-bed',
            'x' => 0,
            'y' => 0,
            'width' => 2,
            'height' => 2,
        ], $outboxEvent['payload']);
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
                    'response' => $this->deletedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint());

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->deletedBuildingResponse(),
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
                    'response' => $this->deletedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint());

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->deletedBuildingResponse(),
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
                    'response' => $this->deletedBuildingResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint());

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
            ->deleteJson($this->endpoint(), ['player_id' => 'building-owner']);

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

    public function test_returns_409_when_building_has_active_production(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result([
                'Item' => $this->buildingItem(activeProductionId: 'active-production'),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'BUILDING_HAS_ACTIVE_PRODUCTION',
                    'message' => 'Building already has an active production.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_404_when_another_request_deletes_building_concurrently(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint());

        $response
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'BUILDING_NOT_FOUND',
                    'message' => 'Building not found.',
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
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result(['Item' => $this->buildingItem(version: 2)]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->deleteJson($this->endpoint());

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
            'services.aws.dynamodb_tables.buildings' => 'test-buildings',
            'services.aws.dynamodb_tables.occupied_cells' => 'test-occupied-cells',
            'services.aws.dynamodb_tables.commands' => 'test-commands',
            'services.aws.dynamodb_tables.outbox_events' => 'test-outbox-events',
        ]);
    }

    /** @return array<string, mixed> */
    private function buildingItem(
        int $version = 1,
        ?string $activeProductionId = null,
    ): array {
        $building = [
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
        ];

        if ($activeProductionId !== null) {
            $building['active_production_id'] = $activeProductionId;
        }

        return (new Marshaler)->marshalItem($building);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-Player-Id' => 'player-123',
            'Idempotency-Key' => self::IdempotencyKey,
        ];
    }

    private function endpoint(): string
    {
        return '/api/v1/buildings/'.self::BuildingId;
    }

    /**
     * @return array{
     *     building: array{id: string, type: string, x: int, y: int, width: int, height: int, level: int, version: int, placedAt: string, deletedAt: string}
     * }
     */
    private function deletedBuildingResponse(): array
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
                'placedAt' => '2026-08-30T12:00:00.000000Z',
                'deletedAt' => '2026-08-31T12:00:00.000000Z',
            ],
        ];
    }

    private function requestHash(): string
    {
        return hash('sha256', "DeleteBuilding\n".self::BuildingId);
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
}
