<?php

declare(strict_types=1);

namespace Tests\Feature\Lambda\Events;

use App\Lambda\Events\OutboxEventEnrichmentHandler;
use Aws\DynamoDb\Marshaler;
use Bref\Bref;
use Bref\Context\Context;
use Bref\LaravelBridge\HandlerResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

final class OutboxEventEnrichmentHandlerTest extends TestCase
{
    public function test_transforms_dynamodb_stream_batch_into_domain_events(): void
    {
        $handler = new OutboxEventEnrichmentHandler;

        $result = $handler([
            $this->streamRecord([
                'player_id' => 'player-123',
                'event_id' => '11111111-1111-4111-8111-111111111111',
                'schema_version' => 1,
                'event_type' => 'BuildingPlaced.v1',
                'occurred_at' => '2026-08-31T12:00:00.000000Z',
                'correlation_id' => '22222222-2222-4222-8222-222222222222',
                'payload' => [
                    'building_id' => '33333333-3333-4333-8333-333333333333',
                    'building_type' => 'garden-bed',
                    'x' => 4,
                    'y' => 7,
                ],
            ]),
            $this->streamRecord([
                'player_id' => 'player-456',
                'event_id' => '44444444-4444-4444-8444-444444444444',
                'schema_version' => 1,
                'event_type' => 'ProductionCollected.v1',
                'occurred_at' => '2026-08-31T12:01:00.000000Z',
                'correlation_id' => '55555555-5555-4555-8555-555555555555',
                'payload' => [
                    'production_id' => '66666666-6666-4666-8666-666666666666',
                    'output' => ['resource' => 'wheat', 'quantity' => 1],
                ],
            ]),
        ], Context::fake());

        $this->assertSame([
            [
                'eventId' => '11111111-1111-4111-8111-111111111111',
                'eventType' => 'BuildingPlaced.v1',
                'schemaVersion' => 1,
                'occurredAt' => '2026-08-31T12:00:00.000000Z',
                'playerId' => 'player-123',
                'correlationId' => '22222222-2222-4222-8222-222222222222',
                'payload' => [
                    'building_id' => '33333333-3333-4333-8333-333333333333',
                    'building_type' => 'garden-bed',
                    'x' => 4,
                    'y' => 7,
                ],
            ],
            [
                'eventId' => '44444444-4444-4444-8444-444444444444',
                'eventType' => 'ProductionCollected.v1',
                'schemaVersion' => 1,
                'occurredAt' => '2026-08-31T12:01:00.000000Z',
                'playerId' => 'player-456',
                'correlationId' => '55555555-5555-4555-8555-555555555555',
                'payload' => [
                    'production_id' => '66666666-6666-4666-8666-666666666666',
                    'output' => ['resource' => 'wheat', 'quantity' => 1],
                ],
            ],
        ], $result);
    }

    public function test_rejects_non_batch_event(): void
    {
        $handler = new OutboxEventEnrichmentHandler;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'The outbox enrichment event must be a batch of DynamoDB Stream records.',
        );

        $handler(['eventName' => 'INSERT'], Context::fake());
    }

    public function test_rejects_non_insert_record(): void
    {
        $handler = new OutboxEventEnrichmentHandler;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'The outbox enrichment event must contain only INSERT records.',
        );

        $handler([['eventName' => 'MODIFY']], Context::fake());
    }

    #[DataProvider('invalidOutboxEventProvider')]
    public function test_rejects_invalid_outbox_event(
        array $outboxEvent,
        string $expectedMessage,
    ): void {
        $handler = new OutboxEventEnrichmentHandler;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        $handler([$this->streamRecord($outboxEvent)], Context::fake());
    }

    public function test_laravel_bridge_resolves_outbox_enrichment_handler(): void
    {
        $this->assertInstanceOf(HandlerResolver::class, Bref::getContainer());
        $this->assertInstanceOf(
            OutboxEventEnrichmentHandler::class,
            $this->app->make(OutboxEventEnrichmentHandler::class),
        );
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidOutboxEventProvider(): array
    {
        $validEvent = [
            'player_id' => 'player-123',
            'event_id' => '11111111-1111-4111-8111-111111111111',
            'schema_version' => 1,
            'event_type' => 'BuildingPlaced.v1',
            'occurred_at' => '2026-08-31T12:00:00.000000Z',
            'correlation_id' => '22222222-2222-4222-8222-222222222222',
            'payload' => [],
        ];

        return [
            'missing event id' => [
                array_diff_key($validEvent, ['event_id' => true]),
                'The outbox event must contain a non-empty [event_id] string.',
            ],
            'invalid schema version' => [
                [...$validEvent, 'schema_version' => 0],
                'The outbox event must contain a positive [schema_version] integer.',
            ],
            'invalid payload' => [
                [...$validEvent, 'payload' => 'not-an-object'],
                'The outbox event must contain a payload object.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $outboxEvent
     * @return array<string, mixed>
     */
    private function streamRecord(array $outboxEvent): array
    {
        return [
            'eventID' => 'stream-event-id',
            'eventName' => 'INSERT',
            'eventSource' => 'aws:dynamodb',
            'dynamodb' => [
                'NewImage' => (new Marshaler)->marshalItem($outboxEvent),
            ],
        ];
    }
}
