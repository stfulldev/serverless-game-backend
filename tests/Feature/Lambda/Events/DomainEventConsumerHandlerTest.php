<?php

declare(strict_types=1);

namespace Tests\Feature\Lambda\Events;

use App\Lambda\Events\DomainEventConsumerHandler;
use Bref\Bref;
use Bref\Context\Context;
use Bref\LaravelBridge\HandlerResolver;
use Psr\Log\LoggerInterface;
use Tests\TestCase;
use UnexpectedValueException;

final class DomainEventConsumerHandlerTest extends TestCase
{
    public function test_consumes_valid_eventbridge_messages_without_batch_failures(): void
    {
        $logger = $this->mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('Domain event consumed.', [
                'event_id' => '11111111-1111-4111-8111-111111111111',
                'event_type' => 'BuildingPlaced.v1',
                'schema_version' => 1,
                'occurred_at' => '2026-08-31T12:00:00.000000Z',
                'player_id' => 'player-123',
                'correlation_id' => '22222222-2222-4222-8222-222222222222',
            ]);
        $logger->shouldNotReceive('warning');
        $handler = new DomainEventConsumerHandler($logger);

        $result = $handler([
            'Records' => [$this->sqsRecord('message-1')],
        ], Context::fake());

        $this->assertSame(['batchItemFailures' => []], $result);
    }

    public function test_reports_only_invalid_message_as_batch_failure(): void
    {
        $logger = $this->mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once();
        $logger->shouldReceive('warning')
            ->once()
            ->with('Domain event message could not be consumed.', [
                'message_id' => 'message-2',
                'exception' => UnexpectedValueException::class,
                'error' => 'The SQS domain event body must contain valid JSON.',
            ]);
        $handler = new DomainEventConsumerHandler($logger);

        $result = $handler([
            'Records' => [
                $this->sqsRecord('message-1'),
                ['messageId' => 'message-2', 'body' => '{invalid-json'],
            ],
        ], Context::fake());

        $this->assertSame([
            'batchItemFailures' => [
                ['itemIdentifier' => 'message-2'],
            ],
        ], $result);
    }

    public function test_rejects_event_without_sqs_records_batch(): void
    {
        $handler = new DomainEventConsumerHandler($this->mock(LoggerInterface::class));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'The domain event consumer must receive an SQS Records batch.',
        );

        $handler([], Context::fake());
    }

    public function test_rejects_record_without_message_identifier(): void
    {
        $handler = new DomainEventConsumerHandler($this->mock(LoggerInterface::class));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'The event must contain a non-empty [messageId] string.',
        );

        $handler([
            'Records' => [['body' => '{}']],
        ], Context::fake());
    }

    public function test_laravel_bridge_resolves_domain_event_consumer_handler(): void
    {
        $this->assertInstanceOf(HandlerResolver::class, Bref::getContainer());
        $this->assertInstanceOf(
            DomainEventConsumerHandler::class,
            $this->app->make(DomainEventConsumerHandler::class),
        );
    }

    /** @return array<string, mixed> */
    private function sqsRecord(string $messageId): array
    {
        return [
            'messageId' => $messageId,
            'body' => json_encode([
                'version' => '0',
                'id' => 'eventbridge-event-id',
                'detail-type' => 'BuildingPlaced.v1',
                'source' => 'serverless-game-backend',
                'time' => '2026-08-31T12:00:00Z',
                'detail' => [
                    'eventId' => '11111111-1111-4111-8111-111111111111',
                    'eventType' => 'BuildingPlaced.v1',
                    'schemaVersion' => 1,
                    'occurredAt' => '2026-08-31T12:00:00.000000Z',
                    'playerId' => 'player-123',
                    'correlationId' => '22222222-2222-4222-8222-222222222222',
                    'payload' => [
                        'building_id' => '33333333-3333-4333-8333-333333333333',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
