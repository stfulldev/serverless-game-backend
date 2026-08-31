<?php

declare(strict_types=1);

namespace App\Lambda\Events;

use Aws\DynamoDb\Marshaler;
use Bref\Context\Context;
use UnexpectedValueException;

final readonly class OutboxEventEnrichmentHandler
{
    public function __construct(private Marshaler $marshaler = new Marshaler) {}

    /**
     * @param  array<int, array<string, mixed>>  $event
     * @return list<array{
     *     eventId: string,
     *     eventType: string,
     *     schemaVersion: int,
     *     occurredAt: string,
     *     playerId: string,
     *     correlationId: string,
     *     payload: array<string, mixed>
     * }>
     */
    public function __invoke(array $event, Context $context): array
    {
        if (! array_is_list($event)) {
            throw new UnexpectedValueException(
                'The outbox enrichment event must be a batch of DynamoDB Stream records.',
            );
        }

        return array_map($this->enrichRecord(...), $event);
    }

    /**
     * @param  array<string, mixed>  $record
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
    private function enrichRecord(array $record): array
    {
        if (($record['eventName'] ?? null) !== 'INSERT') {
            throw new UnexpectedValueException(
                'The outbox enrichment event must contain only INSERT records.',
            );
        }

        $dynamoDb = $record['dynamodb'] ?? null;
        $newImage = is_array($dynamoDb) ? ($dynamoDb['NewImage'] ?? null) : null;

        if (! is_array($newImage)) {
            throw new UnexpectedValueException(
                'The outbox DynamoDB Stream record must contain a NewImage.',
            );
        }

        /** @var array<string, mixed> $outboxEvent */
        $outboxEvent = $this->marshaler->unmarshalItem($newImage);
        $payload = $outboxEvent['payload'] ?? null;

        if (! is_array($payload)) {
            throw new UnexpectedValueException(
                'The outbox event must contain a payload object.',
            );
        }

        return [
            'eventId' => $this->requiredString($outboxEvent, 'event_id'),
            'eventType' => $this->requiredString($outboxEvent, 'event_type'),
            'schemaVersion' => $this->requiredPositiveInteger($outboxEvent, 'schema_version'),
            'occurredAt' => $this->requiredString($outboxEvent, 'occurred_at'),
            'playerId' => $this->requiredString($outboxEvent, 'player_id'),
            'correlationId' => $this->requiredString($outboxEvent, 'correlation_id'),
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $event */
    private function requiredString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                "The outbox event must contain a non-empty [{$key}] string.",
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $event */
    private function requiredPositiveInteger(array $event, string $key): int
    {
        $value = $event[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new UnexpectedValueException(
                "The outbox event must contain a positive [{$key}] integer.",
            );
        }

        return $value;
    }
}
