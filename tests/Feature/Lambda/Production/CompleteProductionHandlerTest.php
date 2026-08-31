<?php

declare(strict_types=1);

namespace Tests\Feature\Lambda\Production;

use App\Exceptions\ProductionNotReadyException;
use App\Lambda\Production\CompleteProductionHandler;
use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use Bref\Bref;
use Bref\Context\Context;
use Bref\LaravelBridge\HandlerResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;
use UnexpectedValueException;

final class CompleteProductionHandlerTest extends TestCase
{
    private const string EventId = '22222222-2222-4222-8222-222222222222';

    private const string ProductionId = '11111111-1111-4111-8111-111111111111';

    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }

    public function test_due_production_is_completed_atomically_and_emits_outbox_event(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:01:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureTables();
        $commands = [];
        $mockHandler = new MockHandler([
            $this->record($commands, new Result([
                'Item' => $this->productionItem(),
            ])),
            $this->record($commands, new Result),
        ]);
        $handler = $this->handler($mockHandler);

        $result = $handler($this->event(), Context::fake());

        $this->assertSame([
            'playerId' => 'player-123',
            'productionId' => self::ProductionId,
            'status' => 'completed',
            'completedAt' => '2026-08-31T12:01:00.000000Z',
        ], $result);
        $this->assertSame(
            ['GetItem', 'TransactWriteItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $marshaler = new Marshaler;
        $this->assertSame([
            'player_id' => 'player-123',
            'production_id' => self::ProductionId,
        ], $marshaler->unmarshalItem($commands[0]['Key']));

        $transaction = $commands[1]['TransactItems'];
        $this->assertCount(2, $transaction);
        $productionUpdate = $transaction[0]['Update'];
        $this->assertSame('test-productions', $productionUpdate['TableName']);
        $this->assertSame(
            'SET #production_status = :completed, completed_at = :completed_at, #production_version = :next_version, updated_at = :updated_at',
            $productionUpdate['UpdateExpression'],
        );
        $this->assertSame(
            '#production_status = :pending AND #production_version = :expected_version AND completes_at = :expected_completes_at AND completes_at <= :completed_at',
            $productionUpdate['ConditionExpression'],
        );
        $this->assertSame(
            'completed',
            $marshaler->unmarshalValue(
                $productionUpdate['ExpressionAttributeValues'][':completed'],
            ),
        );
        $this->assertSame(
            2,
            $marshaler->unmarshalValue(
                $productionUpdate['ExpressionAttributeValues'][':next_version'],
            ),
        );

        $outboxEvent = $marshaler->unmarshalItem($transaction[1]['Put']['Item']);
        $this->assertSame('test-outbox-events', $transaction[1]['Put']['TableName']);
        $this->assertSame(self::EventId, $outboxEvent['event_id']);
        $this->assertSame('ProductionCompleted.v1', $outboxEvent['event_type']);
        $this->assertSame('correlation-123', $outboxEvent['correlation_id']);
        $this->assertSame([
            'production_id' => self::ProductionId,
            'building_id' => 'building-123',
            'recipe' => 'wheat',
            'completed_at' => '2026-08-31T12:01:00.000000Z',
            'output' => ['resource' => 'wheat', 'quantity' => 1],
        ], $outboxEvent['payload']);
        $this->assertNull($outboxEvent['published_at']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_completed_production_is_returned_without_second_write(): void
    {
        $this->configureTables();
        $mockHandler = new MockHandler([
            new Result([
                'Item' => $this->productionItem(
                    status: 'completed',
                    version: 2,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                ),
            ]),
        ]);
        $handler = $this->handler($mockHandler);

        $result = $handler($this->event(), Context::fake());

        $this->assertSame([
            'playerId' => 'player-123',
            'productionId' => self::ProductionId,
            'status' => 'completed',
            'completedAt' => '2026-08-31T12:01:00.000000Z',
        ], $result);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_missing_production_is_ignored_for_orphaned_schedule(): void
    {
        $this->configureTables();
        $mockHandler = new MockHandler([new Result]);
        $handler = $this->handler($mockHandler);

        $result = $handler($this->event(), Context::fake());

        $this->assertSame([
            'playerId' => 'player-123',
            'productionId' => self::ProductionId,
            'status' => 'ignored',
            'completedAt' => null,
        ], $result);
        $this->assertSame('GetItem', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_returns_concurrent_completion_after_condition_failure(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:01:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString(self::EventId),
        ]);
        $this->configureTables();
        $mockHandler = new MockHandler([
            new Result(['Item' => $this->productionItem()]),
            static fn (CommandInterface $command): DynamoDbException => new DynamoDbException(
                'Transaction was canceled.',
                $command,
                ['code' => 'TransactionCanceledException'],
            ),
            new Result([
                'Item' => $this->productionItem(
                    status: 'completed',
                    version: 2,
                    completedAt: '2026-08-31T12:01:00.000000Z',
                ),
            ]),
        ]);
        $handler = $this->handler($mockHandler);

        $result = $handler($this->event(), Context::fake());

        $this->assertSame('completed', $result['status']);
        $this->assertSame('2026-08-31T12:01:00.000000Z', $result['completedAt']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_rejects_production_that_is_not_ready(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        $this->configureTables();
        $mockHandler = new MockHandler([
            new Result([
                'Item' => $this->productionItem(
                    completesAt: '2026-08-31T12:01:00.000000Z',
                ),
            ]),
        ]);
        $handler = $this->handler($mockHandler);

        try {
            $handler($this->event(), Context::fake());
            $this->fail('An early production completion was accepted.');
        } catch (ProductionNotReadyException $exception) {
            $this->assertSame('PRODUCTION_NOT_READY', $exception->errorCode());
        }

        $this->assertCount(0, $mockHandler);
    }

    #[DataProvider('invalidEventProvider')]
    public function test_rejects_event_without_required_identifier(
        array $event,
        string $missingKey,
    ): void {
        $handler = $this->handler(new MockHandler);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "The Complete Production event must contain [{$missingKey}].",
        );

        $handler($event, Context::fake());
    }

    public function test_laravel_bridge_resolves_complete_production_handler(): void
    {
        $this->assertInstanceOf(HandlerResolver::class, Bref::getContainer());
        $this->assertInstanceOf(
            CompleteProductionHandler::class,
            $this->app->make(CompleteProductionHandler::class),
        );
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidEventProvider(): array
    {
        return [
            'missing player' => [[
                'productionId' => self::ProductionId,
                'correlationId' => 'correlation-123',
            ], 'playerId'],
            'missing production' => [[
                'playerId' => 'player-123',
                'correlationId' => 'correlation-123',
            ], 'productionId'],
            'missing correlation' => [[
                'playerId' => 'player-123',
                'productionId' => self::ProductionId,
            ], 'correlationId'],
        ];
    }

    private function handler(MockHandler $mockHandler): CompleteProductionHandler
    {
        $this->app->instance(DynamoDbClient::class, new DynamoDbClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => new Credentials('test', 'test'),
            'handler' => $mockHandler,
        ]));

        return $this->app->make(CompleteProductionHandler::class);
    }

    private function configureTables(): void
    {
        config()->set([
            'services.aws.scheduler.enabled' => false,
            'services.aws.dynamodb_tables.productions' => 'test-productions',
            'services.aws.dynamodb_tables.outbox_events' => 'test-outbox-events',
        ]);
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        return [
            'playerId' => 'player-123',
            'productionId' => self::ProductionId,
            'correlationId' => 'correlation-123',
        ];
    }

    /** @return array<string, mixed> */
    private function productionItem(
        string $status = 'pending',
        int $version = 1,
        string $completesAt = '2026-08-31T12:01:00.000000Z',
        ?string $completedAt = null,
    ): array {
        $production = [
            'player_id' => 'player-123',
            'production_id' => self::ProductionId,
            'schema_version' => 1,
            'building_id' => 'building-123',
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

        return (new Marshaler)->marshalItem($production);
    }

    /** @param array<int, CommandInterface> $commands */
    private function record(array &$commands, Result $result): Closure
    {
        return static function (CommandInterface $command) use (&$commands, $result): Result {
            $commands[] = $command;

            return $result;
        };
    }
}
