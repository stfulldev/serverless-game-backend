<?php

declare(strict_types=1);

namespace App\Lambda\Events;

use Bref\Context\Context;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

final readonly class DomainEventConsumerHandler
{
    private const string EventSource = 'serverless-game-backend';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  array<string, mixed>  $event
     * @return array{batchItemFailures: list<array{itemIdentifier: string}>}
     */
    public function __invoke(array $event, Context $context): array
    {
        $records = $event['Records'] ?? null;

        if (! is_array($records) || ! array_is_list($records)) {
            throw new UnexpectedValueException(
                'The domain event consumer must receive an SQS Records batch.',
            );
        }

        $failures = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new UnexpectedValueException(
                    'Every domain event consumer record must be an SQS record object.',
                );
            }

            $messageId = $this->messageId($record);

            try {
                $this->consume($record);
            } catch (Throwable $exception) {
                $this->logger->warning('Domain event message could not be consumed.', [
                    'message_id' => $messageId,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                $failures[] = ['itemIdentifier' => $messageId];
            }
        }

        return ['batchItemFailures' => $failures];
    }

    /** @param array<string, mixed> $record */
    private function consume(array $record): void
    {
        $body = $record['body'] ?? null;

        if (! is_string($body) || $body === '') {
            throw new UnexpectedValueException(
                'The SQS domain event record must contain a JSON body.',
            );
        }

        try {
            $eventBridgeEvent = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The SQS domain event body must contain valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($eventBridgeEvent)) {
            throw new UnexpectedValueException(
                'The SQS domain event body must contain an EventBridge event object.',
            );
        }

        if (($eventBridgeEvent['source'] ?? null) !== self::EventSource) {
            throw new UnexpectedValueException(
                'The EventBridge event must come from the serverless game backend.',
            );
        }

        $detailType = $this->requiredString($eventBridgeEvent, 'detail-type');
        $detail = $eventBridgeEvent['detail'] ?? null;

        if (! is_array($detail)) {
            throw new UnexpectedValueException(
                'The EventBridge event must contain a detail object.',
            );
        }

        $domainEvent = $this->domainEvent($detail);

        if ($detailType !== $domainEvent['eventType']) {
            throw new UnexpectedValueException(
                'The EventBridge detail type must match the domain event type.',
            );
        }

        $this->logger->info('Domain event consumed.', [
            'event_id' => $domainEvent['eventId'],
            'event_type' => $domainEvent['eventType'],
            'schema_version' => $domainEvent['schemaVersion'],
            'occurred_at' => $domainEvent['occurredAt'],
            'player_id' => $domainEvent['playerId'],
            'correlation_id' => $domainEvent['correlationId'],
        ]);
    }

    /** @param array<string, mixed> $record */
    private function messageId(array $record): string
    {
        return $this->requiredString($record, 'messageId');
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{
     *     eventId: string,
     *     eventType: string,
     *     schemaVersion: int,
     *     occurredAt: string,
     *     playerId: string,
     *     correlationId: string,
     *     payload: array<string, mixed>
     * }
     */
    private function domainEvent(array $detail): array
    {
        $schemaVersion = $detail['schemaVersion'] ?? null;
        $payload = $detail['payload'] ?? null;

        if (! is_int($schemaVersion) || $schemaVersion < 1) {
            throw new UnexpectedValueException(
                'The domain event must contain a positive schemaVersion integer.',
            );
        }

        if (! is_array($payload)) {
            throw new UnexpectedValueException(
                'The domain event must contain a payload object.',
            );
        }

        return [
            'eventId' => $this->requiredString($detail, 'eventId'),
            'eventType' => $this->requiredString($detail, 'eventType'),
            'schemaVersion' => $schemaVersion,
            'occurredAt' => $this->requiredString($detail, 'occurredAt'),
            'playerId' => $this->requiredString($detail, 'playerId'),
            'correlationId' => $this->requiredString($detail, 'correlationId'),
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $event */
    private function requiredString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                "The event must contain a non-empty [{$key}] string.",
            );
        }

        return $value;
    }
}
