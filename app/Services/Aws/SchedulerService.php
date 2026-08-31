<?php

namespace App\Services\Aws;

use Aws\Scheduler\SchedulerClient;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;
use LogicException;

final readonly class SchedulerService
{
    public function __construct(private SchedulerClient $scheduler) {}

    /**
     * @throws JsonException
     */
    public function scheduleProductionCompletion(
        string $playerId,
        string $productionId,
        string $correlationId,
        string $completesAt,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $this->validateIdentifiers($playerId, $productionId, $correlationId);
        $completionTime = CarbonImmutable::parse($completesAt)->utc();
        $target = [
            'Arn' => $this->requiredString('target_arn'),
            'RoleArn' => $this->requiredString('role_arn'),
            'Input' => json_encode([
                'playerId' => $playerId,
                'productionId' => $productionId,
                'correlationId' => $correlationId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'RetryPolicy' => [
                'MaximumEventAgeInSeconds' => $this->requiredPositiveInteger(
                    'maximum_event_age_seconds',
                ),
                'MaximumRetryAttempts' => $this->requiredNonNegativeInteger(
                    'maximum_retry_attempts',
                ),
            ],
        ];
        $deadLetterQueueArn = $this->requiredString('dead_letter_queue_arn');
        $target['DeadLetterConfig'] = ['Arn' => $deadLetterQueueArn];

        $this->scheduler->createSchedule([
            'ActionAfterCompletion' => 'DELETE',
            'ClientToken' => $productionId,
            'Description' => "Complete production {$productionId} for player {$playerId}.",
            'FlexibleTimeWindow' => ['Mode' => 'OFF'],
            'GroupName' => $this->requiredString('group_name'),
            'Name' => "production-{$productionId}",
            'ScheduleExpression' => 'at('.$completionTime->format('Y-m-d\TH:i:s').')',
            'ScheduleExpressionTimezone' => 'UTC',
            'State' => 'ENABLED',
            'Target' => $target,
        ]);
    }

    private function enabled(): bool
    {
        $enabled = config('services.aws.scheduler.enabled');

        if (! is_bool($enabled)) {
            throw new LogicException('The EventBridge Scheduler enabled flag must be boolean.');
        }

        return $enabled;
    }

    private function requiredString(string $key): string
    {
        $value = config("services.aws.scheduler.{$key}");

        if (! is_string($value) || $value === '') {
            throw new LogicException("EventBridge Scheduler [{$key}] must be configured.");
        }

        return $value;
    }

    private function requiredPositiveInteger(string $key): int
    {
        $value = config("services.aws.scheduler.{$key}");

        if (! is_int($value) || $value < 1) {
            throw new LogicException("EventBridge Scheduler [{$key}] must be a positive integer.");
        }

        return $value;
    }

    private function requiredNonNegativeInteger(string $key): int
    {
        $value = config("services.aws.scheduler.{$key}");

        if (! is_int($value) || $value < 0) {
            throw new LogicException(
                "EventBridge Scheduler [{$key}] must be a non-negative integer.",
            );
        }

        return $value;
    }

    private function validateIdentifiers(
        string $playerId,
        string $productionId,
        string $correlationId,
    ): void {
        if ($playerId === '') {
            throw new InvalidArgumentException('Player ID cannot be empty.');
        }

        if ($productionId === '') {
            throw new InvalidArgumentException('Production ID cannot be empty.');
        }

        if ($correlationId === '') {
            throw new InvalidArgumentException('Correlation ID cannot be empty.');
        }
    }
}
