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

final class ClearedObstacleControllerTest extends TestCase
{
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
            ->postJson('/api/v1/obstacles/tree-001/clear');

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The X-Player-Id header is required for local requests.',
                ],
            ]);
    }

    public function test_returns_422_when_idempotency_header_is_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->postJson('/api/v1/obstacles/tree-001/clear');

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
            ->postJson('/api/v1/obstacles/tree-001/clear');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header must be a valid UUID.',
            );
    }

    public function test_clears_obstacle_atomically_and_returns_updated_wallet(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString('11111111-1111-4111-8111-111111111111'),
        ]);
        $this->configureGame();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result([
                'Responses' => $this->profileResponses(),
            ])),
            $this->record($commands, new Result),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->clearedObstacleResponse(),
            ]);

        $this->assertSame(
            ['GetItem', 'TransactGetItems', 'GetItem', 'TransactWriteItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $transaction = $commands[3]['TransactItems'];
        $this->assertSame(
            [
                'test-wallets',
                'test-cleared-obstacles',
                'test-commands',
                'test-outbox-events',
            ],
            [
                $transaction[0]['Update']['TableName'],
                $transaction[1]['Put']['TableName'],
                $transaction[2]['Put']['TableName'],
                $transaction[3]['Put']['TableName'],
            ],
        );
        $this->assertSame(
            'coins = :expected_coins AND #wallet_version = :expected_wallet_version',
            $transaction[0]['Update']['ConditionExpression'],
        );

        $marshaler = new Marshaler;
        $clearedObstacle = $marshaler->unmarshalItem($transaction[1]['Put']['Item']);
        $command = $marshaler->unmarshalItem($transaction[2]['Put']['Item']);
        $outboxEvent = $marshaler->unmarshalItem($transaction[3]['Put']['Item']);

        $this->assertSame('player-123', $clearedObstacle['player_id']);
        $this->assertSame('tree-001', $clearedObstacle['obstacle_id']);
        $this->assertSame('tree', $clearedObstacle['obstacle_type']);
        $this->assertSame(100, $clearedObstacle['clear_cost']);
        $this->assertSame('2026-08-31T12:00:00.000000Z', $clearedObstacle['cleared_at']);

        $this->assertSame(self::IdempotencyKey, $command['idempotency_key']);
        $this->assertSame('ClearObstacle', $command['command_type']);
        $this->assertSame($this->requestHash('tree-001'), $command['request_hash']);
        $this->assertSame($this->clearedObstacleResponse(), $command['response']);
        $this->assertSame(1788782400, $command['expires_at']);

        $this->assertSame('11111111-1111-4111-8111-111111111111', $outboxEvent['event_id']);
        $this->assertSame('ObstacleCleared.v1', $outboxEvent['event_type']);
        $this->assertSame(self::IdempotencyKey, $outboxEvent['correlation_id']);
        $this->assertSame([
            'obstacle_id' => 'tree-001',
            'obstacle_type' => 'tree',
            'x' => 3,
            'y' => 4,
            'clear_cost' => 100,
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
                    'request_hash' => $this->requestHash('tree-001'),
                    'response' => $this->clearedObstacleResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->clearedObstacleResponse(),
            ]);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_replays_concurrently_stored_response_when_transaction_is_canceled(): void
    {
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
            new Result,
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'idempotency_key' => self::IdempotencyKey,
                    'request_hash' => $this->requestHash('tree-001'),
                    'response' => $this->clearedObstacleResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->clearedObstacleResponse(),
            ]);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_concurrent_wallet_change_cancels_transaction(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
            new Result,
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

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
                    'response' => $this->clearedObstacleResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

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
            ->postJson('/api/v1/obstacles/tree-001/clear');

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

    public function test_returns_404_when_obstacle_does_not_exist_on_the_player_map(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/unknown/clear');

        $response
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'OBSTACLE_NOT_FOUND',
                    'message' => 'Obstacle not found.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_obstacle_was_already_cleared(): void
    {
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses()]),
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'obstacle_id' => 'tree-001',
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'OBSTACLE_ALREADY_CLEARED',
                    'message' => 'Obstacle has already been cleared.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_wallet_has_insufficient_coins(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Responses' => $this->profileResponses(coins: 50)]),
            new Result,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/obstacles/tree-001/clear');

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

    /**
     * @return array{
     *     obstacle: array{id: string, type: string, x: int, y: int, clearCost: int, clearedAt: string},
     *     wallet: array{coins: int, resources: array<string, int>, version: int}
     * }
     */
    private function clearedObstacleResponse(): array
    {
        return [
            'obstacle' => [
                'id' => 'tree-001',
                'type' => 'tree',
                'x' => 3,
                'y' => 4,
                'clearCost' => 100,
                'clearedAt' => '2026-08-31T12:00:00.000000Z',
            ],
            'wallet' => [
                'coins' => 400,
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

    private function requestHash(string $obstacleId): string
    {
        return hash('sha256', "ClearObstacle\n{$obstacleId}");
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
