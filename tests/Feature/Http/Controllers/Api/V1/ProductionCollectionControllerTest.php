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

final class ProductionCollectionControllerTest extends TestCase
{
    private const string BuildingId = '44444444-4444-4444-8444-444444444444';

    private const string CollectedEventId = '33333333-3333-4333-8333-333333333333';

    private const string CompletedEventId = '22222222-2222-4222-8222-222222222222';

    private const string IdempotencyKey = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string ProductionId = '11111111-1111-4111-8111-111111111111';

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
            ->postJson($this->endpoint());

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The X-Player-Id header is required for local requests.',
                ],
            ]);
    }

    public function test_returns_404_when_production_id_is_not_a_uuid(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders($this->headers())
            ->postJson('/api/v1/productions/not-a-uuid/collect');

        $response->assertNotFound();
    }

    public function test_returns_422_when_idempotency_header_is_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->postJson($this->endpoint());

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
            ->postJson($this->endpoint());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header must be a valid UUID.',
            );
    }

    public function test_collects_completed_production_atomically_and_releases_building(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:02:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::CollectedEventId),
        ]);
        $this->configureGame();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result([
                'Item' => $this->productionItem(
                    status: 'completed',
                    version: 2,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                ),
            ])),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), ['player_id' => 'another-player']);

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->collectedResponse(),
            ]);
        $this->assertSame(
            ['GetItem', 'GetItem', 'TransactWriteItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $marshaler = new Marshaler;
        $this->assertSame([
            'player_id' => 'player-123',
            'production_id' => self::ProductionId,
        ], $marshaler->unmarshalItem($commands[1]['Key']));

        $transaction = $commands[2]['TransactItems'];
        $this->assertCount(5, $transaction);

        $productionUpdate = $transaction[0]['Update'];
        $this->assertSame('test-productions', $productionUpdate['TableName']);
        $this->assertSame(
            'SET #production_status = :collected, completed_at = if_not_exists(completed_at, :completed_at), collected_at = :collected_at, #production_version = :next_version, updated_at = :updated_at',
            $productionUpdate['UpdateExpression'],
        );
        $this->assertSame(
            '#production_status = :expected_status AND #production_version = :expected_version AND completes_at = :expected_completes_at AND completes_at <= :collected_at AND attribute_not_exists(collected_at)',
            $productionUpdate['ConditionExpression'],
        );
        $this->assertSame(
            'completed',
            $marshaler->unmarshalValue(
                $productionUpdate['ExpressionAttributeValues'][':expected_status'],
            ),
        );
        $this->assertSame(
            3,
            $marshaler->unmarshalValue(
                $productionUpdate['ExpressionAttributeValues'][':next_version'],
            ),
        );

        $walletUpdate = $transaction[1]['Update'];
        $this->assertSame('test-wallets', $walletUpdate['TableName']);
        $this->assertSame(
            ['player_id' => 'player-123'],
            $marshaler->unmarshalItem($walletUpdate['Key']),
        );
        $this->assertSame('wheat', $walletUpdate['ExpressionAttributeNames']['#resource_name']);
        $this->assertSame(
            1,
            $marshaler->unmarshalValue(
                $walletUpdate['ExpressionAttributeValues'][':quantity'],
            ),
        );

        $buildingUpdate = $transaction[2]['Update'];
        $this->assertSame('test-buildings', $buildingUpdate['TableName']);
        $this->assertSame([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
        ], $marshaler->unmarshalItem($buildingUpdate['Key']));
        $this->assertSame(
            'active_production_id = :production_id',
            $buildingUpdate['ConditionExpression'],
        );
        $this->assertStringContainsString(
            'REMOVE active_production_id',
            $buildingUpdate['UpdateExpression'],
        );

        $command = $marshaler->unmarshalItem($transaction[3]['Put']['Item']);
        $this->assertSame('CollectProduction', $command['command_type']);
        $this->assertSame($this->requestHash(), $command['request_hash']);
        $this->assertSame($this->collectedResponse(), $command['response']);
        $this->assertSame(1788782520, $command['expires_at']);

        $outboxEvent = $marshaler->unmarshalItem($transaction[4]['Put']['Item']);
        $this->assertSame(self::CollectedEventId, $outboxEvent['event_id']);
        $this->assertSame('ProductionCollected.v1', $outboxEvent['event_type']);
        $this->assertSame(self::IdempotencyKey, $outboxEvent['correlation_id']);
        $this->assertSame([
            'production_id' => self::ProductionId,
            'building_id' => self::BuildingId,
            'recipe' => 'wheat',
            'completed_at' => '2026-08-31T12:01:00.000000Z',
            'collected_at' => '2026-08-31T12:02:00.000000Z',
            'output' => ['resource' => 'wheat', 'quantity' => 1],
        ], $outboxEvent['payload']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_collects_due_pending_production_when_scheduler_did_not_complete_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:02:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::CompletedEventId),
            Uuid::fromString(self::CollectedEventId),
        ]);
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->productionItem(status: 'pending')]),
            new Result,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => $this->collectedResponse(
                    completedAt: '2026-08-31T12:02:00.000000Z',
                    version: 2,
                ),
            ]);

        $marshaler = new Marshaler;
        $transaction = $mockHandler->getLastCommand()['TransactItems'];
        $this->assertCount(6, $transaction);
        $completedEvent = $marshaler->unmarshalItem($transaction[4]['Put']['Item']);
        $collectedEvent = $marshaler->unmarshalItem($transaction[5]['Put']['Item']);

        $this->assertSame(self::CompletedEventId, $completedEvent['event_id']);
        $this->assertSame('ProductionCompleted.v1', $completedEvent['event_type']);
        $this->assertSame(
            '2026-08-31T12:02:00.000000Z',
            $completedEvent['payload']['completed_at'],
        );

        $this->assertSame(self::CollectedEventId, $collectedEvent['event_id']);
        $this->assertSame('ProductionCollected.v1', $collectedEvent['event_type']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_replays_stored_response_for_same_idempotency_key(): void
    {
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result([
                'Item' => $marshaler->marshalItem([
                    'player_id' => 'player-123',
                    'idempotency_key' => self::IdempotencyKey,
                    'request_hash' => $this->requestHash(),
                    'response' => $this->collectedResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

        $response
            ->assertOk()
            ->assertExactJson(['data' => $this->collectedResponse()]);
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
                    'response' => $this->collectedResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

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

    public function test_returns_404_without_revealing_production_owned_by_another_player(): void
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
            ->postJson($this->endpoint(), ['player_id' => 'production-owner']);

        $response
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'PRODUCTION_NOT_FOUND',
                    'message' => 'Production not found.',
                ],
            ]);
        $this->assertSame([
            'player_id' => 'player-123',
            'production_id' => self::ProductionId,
        ], (new Marshaler)->unmarshalItem($commands[1]['Key']));
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_production_is_not_ready(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result([
                'Item' => $this->productionItem(
                    status: 'pending',
                    completesAt: '2026-08-31T12:01:00.000000Z',
                ),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'PRODUCTION_NOT_READY',
                    'message' => 'Production is not ready.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_production_was_already_collected(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result([
                'Item' => $this->productionItem(
                    status: 'collected',
                    version: 3,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                    collectedAt: '2026-08-31T12:02:00.000000Z',
                ),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'ALREADY_COLLECTED',
                    'message' => 'Production has already been collected.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_replays_concurrently_saved_collection_after_transaction_is_canceled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:02:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::CollectedEventId),
        ]);
        $this->configureGame();
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            new Result,
            new Result([
                'Item' => $this->productionItem(
                    status: 'completed',
                    version: 2,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                ),
            ]),
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
                    'response' => $this->collectedResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

        $response
            ->assertOk()
            ->assertExactJson(['data' => $this->collectedResponse()]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_another_command_collects_production_concurrently(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:02:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::CollectedEventId),
        ]);
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result([
                'Item' => $this->productionItem(
                    status: 'completed',
                    version: 2,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                ),
            ]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            new Result([
                'Item' => $this->productionItem(
                    status: 'collected',
                    version: 3,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                    collectedAt: '2026-08-31T12:02:00.000000Z',
                ),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

        $response
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'ALREADY_COLLECTED',
                    'message' => 'Production has already been collected.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_collection_transaction_conflicts_with_game_state(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:02:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::CollectedEventId),
        ]);
        $this->configureGame();
        $completedProduction = new Result([
            'Item' => $this->productionItem(
                status: 'completed',
                version: 2,
                completedAt: '2026-08-31T12:01:00.000000Z',
            ),
        ]);
        $mockHandler = new MockHandler([
            new Result,
            $completedProduction,
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result,
            $completedProduction,
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint());

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
            'services.aws.scheduler.enabled' => false,
            'services.aws.dynamodb_tables.buildings' => 'test-buildings',
            'services.aws.dynamodb_tables.productions' => 'test-productions',
            'services.aws.dynamodb_tables.wallets' => 'test-wallets',
            'services.aws.dynamodb_tables.commands' => 'test-commands',
            'services.aws.dynamodb_tables.outbox_events' => 'test-outbox-events',
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
    private function collectedResponse(
        string $completedAt = '2026-08-31T12:01:00.000000Z',
        int $version = 3,
    ): array {
        return [
            'production' => [
                'id' => self::ProductionId,
                'buildingId' => self::BuildingId,
                'recipe' => 'wheat',
                'status' => 'collected',
                'output' => ['resource' => 'wheat', 'quantity' => 1],
                'version' => $version,
                'completedAt' => $completedAt,
                'collectedAt' => '2026-08-31T12:02:00.000000Z',
            ],
        ];
    }

    private function endpoint(): string
    {
        return '/api/v1/productions/'.self::ProductionId.'/collect';
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-Player-Id' => 'player-123',
            'Idempotency-Key' => self::IdempotencyKey,
        ];
    }

    /** @return array<string, mixed> */
    private function productionItem(
        string $status,
        int $version = 1,
        string $completesAt = '2026-08-31T12:01:00.000000Z',
        ?string $completedAt = null,
        ?string $collectedAt = null,
    ): array {
        $production = [
            'player_id' => 'player-123',
            'production_id' => self::ProductionId,
            'schema_version' => 1,
            'building_id' => self::BuildingId,
            'recipe' => 'wheat',
            'status' => $status,
            'output' => ['resource' => 'wheat', 'quantity' => 1],
            'version' => $version,
            'started_at' => '2026-08-31T12:00:00.000000Z',
            'completes_at' => $completesAt,
            'created_at' => '2026-08-31T12:00:00.000000Z',
            'updated_at' => '2026-08-31T12:00:00.000000Z',
        ];

        if ($completedAt !== null) {
            $production['completed_at'] = $completedAt;
        }

        if ($collectedAt !== null) {
            $production['collected_at'] = $collectedAt;
        }

        return (new Marshaler)->marshalItem($production);
    }

    private function requestHash(): string
    {
        return hash('sha256', "CollectProduction\n".self::ProductionId);
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
