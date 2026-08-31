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
use Aws\Scheduler\SchedulerClient;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ProductionControllerTest extends TestCase
{
    private const string BuildingId = '33333333-3333-4333-8333-333333333333';

    private const string EventId = '22222222-2222-4222-8222-222222222222';

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
            ->postJson($this->endpoint(), $this->payload());

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
            ->postJson('/api/v1/buildings/not-a-uuid/productions', $this->payload());

        $response->assertNotFound();
    }

    public function test_returns_422_when_recipe_and_idempotency_header_are_missing(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeader('X-Player-Id', 'player-123')
            ->postJson($this->endpoint());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipe', 'idempotencyKey'])
            ->assertJsonPath('errors.recipe.0', 'The recipe field is required.')
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header is required.',
            );
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function test_returns_422_when_recipe_fails_transport_validation(
        array $payload,
        string $message,
    ): void {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipe'])
            ->assertJsonPath('errors.recipe.0', $message);
    }

    public function test_returns_422_when_idempotency_header_is_not_a_uuid(): void
    {
        $this->useLocalAuthentication();

        $response = $this
            ->withHeaders([
                'X-Player-Id' => 'player-123',
                'Idempotency-Key' => 'not-a-uuid',
            ])
            ->postJson($this->endpoint(), $this->payload());

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotencyKey'])
            ->assertJsonPath(
                'errors.idempotencyKey.0',
                'The Idempotency-Key header must be a valid UUID.',
            );
    }

    public function test_starts_production_atomically_and_marks_building_as_active(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::ProductionId),
            Uuid::fromString(self::EventId),
        ]);
        $this->configureGame();
        $this->configureScheduler();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result),
            $this->record($commands, new Result(['Item' => $this->buildingItem()])),
            $this->record($commands, new Result),
        ]);
        $this->bindDynamoDb($mockHandler);
        $schedulerMockHandler = new MockHandler([
            new Result([
                'ScheduleArn' => 'arn:aws:scheduler:us-east-1:123456789012:schedule/game/production',
            ]),
        ]);
        $this->bindScheduler($schedulerMockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), [
                ...$this->payload(),
                'player_id' => 'another-player',
            ]);

        $response
            ->assertCreated()
            ->assertExactJson([
                'data' => $this->startedProductionResponse(),
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
        $this->assertCount(4, $transaction);

        $buildingUpdate = $transaction[0]['Update'];
        $this->assertSame('test-buildings', $buildingUpdate['TableName']);
        $this->assertSame(
            'SET active_production_id = :production_id, #building_version = :building_version, updated_at = :updated_at',
            $buildingUpdate['UpdateExpression'],
        );
        $this->assertSame(
            '#building_type = :expected_building_type AND (#building_version = :expected_version OR attribute_not_exists(#building_version)) AND attribute_not_exists(active_production_id)',
            $buildingUpdate['ConditionExpression'],
        );
        $this->assertSame(
            self::ProductionId,
            $marshaler->unmarshalValue($buildingUpdate['ExpressionAttributeValues'][':production_id']),
        );
        $this->assertSame(
            2,
            $marshaler->unmarshalValue($buildingUpdate['ExpressionAttributeValues'][':building_version']),
        );

        $production = $marshaler->unmarshalItem($transaction[1]['Put']['Item']);
        $this->assertSame([
            'player_id' => 'player-123',
            'production_id' => self::ProductionId,
            'schema_version' => 1,
            'building_id' => self::BuildingId,
            'recipe' => 'wheat',
            'status' => 'pending',
            'output' => ['resource' => 'wheat', 'quantity' => 1],
            'version' => 1,
            'started_at' => '2026-08-31T12:00:00.000000Z',
            'completes_at' => '2026-08-31T12:01:00.000000Z',
            'created_at' => '2026-08-31T12:00:00.000000Z',
            'updated_at' => '2026-08-31T12:00:00.000000Z',
        ], $production);

        $command = $marshaler->unmarshalItem($transaction[2]['Put']['Item']);
        $outboxEvent = $marshaler->unmarshalItem($transaction[3]['Put']['Item']);

        $this->assertSame('StartProduction', $command['command_type']);
        $this->assertSame($this->requestHash(), $command['request_hash']);
        $this->assertSame($this->startedProductionResponse(), $command['response']);
        $this->assertSame(1788782400, $command['expires_at']);

        $this->assertSame(self::EventId, $outboxEvent['event_id']);
        $this->assertSame('ProductionStarted.v1', $outboxEvent['event_type']);
        $this->assertSame(self::IdempotencyKey, $outboxEvent['correlation_id']);
        $this->assertSame([
            'production_id' => self::ProductionId,
            'building_id' => self::BuildingId,
            'recipe' => 'wheat',
            'duration_seconds' => 60,
            'completes_at' => '2026-08-31T12:01:00.000000Z',
            'output' => ['resource' => 'wheat', 'quantity' => 1],
        ], $outboxEvent['payload']);
        $this->assertSame('CreateSchedule', $schedulerMockHandler->getLastCommand()->getName());
        $this->assertSame(
            self::ProductionId,
            $schedulerMockHandler->getLastCommand()['ClientToken'],
        );
        $this->assertCount(0, $schedulerMockHandler);
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
                    'response' => $this->startedProductionResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload());

        $response
            ->assertCreated()
            ->assertExactJson([
                'data' => $this->startedProductionResponse(),
            ]);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_replays_concurrently_stored_response_when_transaction_is_canceled(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::ProductionId),
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
                    'response' => $this->startedProductionResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload());

        $response
            ->assertCreated()
            ->assertExactJson([
                'data' => $this->startedProductionResponse(),
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
                    'response' => $this->startedProductionResponse(),
                ]),
            ]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload());

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
            ->postJson($this->endpoint(), [
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
        $this->assertSame([
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
        ], (new Marshaler)->unmarshalItem($commands[1]['Key']));
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_422_when_recipe_is_not_available(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem()]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload('corn'));

        $response
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVALID_RECIPE',
                    'message' => 'Recipe is not available.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_422_when_recipe_is_not_supported_by_building(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem(type: 'bakery')]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload());

        $response
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'INVALID_RECIPE',
                    'message' => 'Recipe is not available for this building.',
                ],
            ]);
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_409_when_building_already_has_active_production(): void
    {
        $this->configureGame();
        $mockHandler = new MockHandler([
            new Result,
            new Result(['Item' => $this->buildingItem(activeProductionId: 'active-production')]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload());

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

    public function test_returns_409_when_concurrent_request_starts_another_production(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::ProductionId),
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
            new Result(['Item' => $this->buildingItem(activeProductionId: 'another-production')]),
        ]);
        $this->bindDynamoDb($mockHandler);

        $response = $this
            ->withHeaders($this->headers())
            ->postJson($this->endpoint(), $this->payload());

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

    public function test_returns_404_when_building_is_deleted_concurrently(): void
    {
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::ProductionId),
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
            ->postJson($this->endpoint(), $this->payload());

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
            Uuid::fromString(self::ProductionId),
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
            ->postJson($this->endpoint(), $this->payload());

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

    private function bindScheduler(MockHandler $mockHandler): void
    {
        $this->app->instance(SchedulerClient::class, new SchedulerClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => new Credentials('test', 'test'),
            'handler' => $mockHandler,
        ]));
    }

    private function configureGame(): void
    {
        config()->set([
            'game.idempotency_ttl_seconds' => 604800,
            'game.recipes' => [
                'wheat' => [
                    'building_types' => ['garden-bed'],
                    'duration_seconds' => 60,
                    'output' => [
                        'resource' => 'wheat',
                        'quantity' => 1,
                    ],
                ],
            ],
            'services.aws.dynamodb_tables.buildings' => 'test-buildings',
            'services.aws.dynamodb_tables.productions' => 'test-productions',
            'services.aws.dynamodb_tables.commands' => 'test-commands',
            'services.aws.dynamodb_tables.outbox_events' => 'test-outbox-events',
            'services.aws.scheduler.enabled' => false,
        ]);
    }

    private function configureScheduler(): void
    {
        config()->set([
            'services.aws.scheduler.enabled' => true,
            'services.aws.scheduler.group_name' => 'game-productions',
            'services.aws.scheduler.target_arn' => 'arn:aws:lambda:us-east-1:123456789012:function:complete-production',
            'services.aws.scheduler.role_arn' => 'arn:aws:iam::123456789012:role/scheduler-role',
            'services.aws.scheduler.dead_letter_queue_arn' => 'arn:aws:sqs:us-east-1:123456789012:completion-dlq',
            'services.aws.scheduler.maximum_event_age_seconds' => 3600,
            'services.aws.scheduler.maximum_retry_attempts' => 10,
        ]);
    }

    /** @return array<string, mixed> */
    private function buildingItem(
        string $type = 'garden-bed',
        int $version = 1,
        ?string $activeProductionId = null,
    ): array {
        $building = [
            'player_id' => 'player-123',
            'building_id' => self::BuildingId,
            'type' => $type,
            'version' => $version,
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

    /** @return array{recipe: string} */
    private function payload(string $recipe = 'wheat'): array
    {
        return ['recipe' => $recipe];
    }

    private function endpoint(): string
    {
        return '/api/v1/buildings/'.self::BuildingId.'/productions';
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
     *         startedAt: string,
     *         completesAt: string
     *     }
     * }
     */
    private function startedProductionResponse(): array
    {
        return [
            'production' => [
                'id' => self::ProductionId,
                'buildingId' => self::BuildingId,
                'recipe' => 'wheat',
                'status' => 'pending',
                'output' => [
                    'resource' => 'wheat',
                    'quantity' => 1,
                ],
                'version' => 1,
                'startedAt' => '2026-08-31T12:00:00.000000Z',
                'completesAt' => '2026-08-31T12:01:00.000000Z',
            ],
        ];
    }

    private function requestHash(): string
    {
        return hash('sha256', "StartProduction\n".self::BuildingId."\nwheat");
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

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'recipe must be a string' => [
                ['recipe' => ['wheat']],
                'The recipe field must be a string.',
            ],
            'recipe length is limited' => [
                ['recipe' => str_repeat('a', 65)],
                'The recipe field must not be greater than 64 characters.',
            ],
            'recipe uses canonical format' => [
                ['recipe' => 'Wheat Seed'],
                'The recipe field may only contain lowercase letters, numbers, and dashes.',
            ],
        ];
    }
}
