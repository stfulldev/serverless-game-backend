<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Aws;

use App\Services\Aws\SchedulerService;
use Aws\Credentials\Credentials;
use Aws\MockHandler;
use Aws\Result;
use Aws\Scheduler\SchedulerClient;
use LogicException;
use Tests\TestCase;

final class SchedulerServiceTest extends TestCase
{
    public function test_creates_one_time_production_schedule_with_retry_and_dlq(): void
    {
        $this->configureScheduler();
        $mockHandler = new MockHandler([
            new Result([
                'ScheduleArn' => 'arn:aws:scheduler:us-east-1:123456789012:schedule/game/production-1',
            ]),
        ]);
        $scheduler = new SchedulerService($this->schedulerClient($mockHandler));

        $scheduler->scheduleProductionCompletion(
            playerId: 'player-123',
            productionId: '11111111-1111-4111-8111-111111111111',
            correlationId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            completesAt: '2026-08-31T12:01:00.000000Z',
        );

        $command = $mockHandler->getLastCommand();
        $this->assertSame('CreateSchedule', $command->getName());
        $this->assertSame('DELETE', $command['ActionAfterCompletion']);
        $this->assertSame(
            '11111111-1111-4111-8111-111111111111',
            $command['ClientToken'],
        );
        $this->assertSame('game-productions', $command['GroupName']);
        $this->assertSame(
            'production-11111111-1111-4111-8111-111111111111',
            $command['Name'],
        );
        $this->assertSame('at(2026-08-31T12:01:00)', $command['ScheduleExpression']);
        $this->assertSame('UTC', $command['ScheduleExpressionTimezone']);
        $this->assertSame(['Mode' => 'OFF'], $command['FlexibleTimeWindow']);
        $this->assertSame('ENABLED', $command['State']);
        $this->assertSame([
            'Arn' => 'arn:aws:lambda:us-east-1:123456789012:function:complete-production',
            'RoleArn' => 'arn:aws:iam::123456789012:role/scheduler-role',
            'Input' => json_encode([
                'playerId' => 'player-123',
                'productionId' => '11111111-1111-4111-8111-111111111111',
                'correlationId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'RetryPolicy' => [
                'MaximumEventAgeInSeconds' => 3600,
                'MaximumRetryAttempts' => 10,
            ],
            'DeadLetterConfig' => [
                'Arn' => 'arn:aws:sqs:us-east-1:123456789012:completion-dlq',
            ],
        ], $command['Target']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_does_not_access_aws_when_scheduler_is_disabled(): void
    {
        config()->set('services.aws.scheduler.enabled', false);
        $mockHandler = new MockHandler;
        $scheduler = new SchedulerService($this->schedulerClient($mockHandler));

        $scheduler->scheduleProductionCompletion('', '', '', 'not-a-timestamp');

        $this->assertCount(0, $mockHandler);
    }

    public function test_rejects_missing_target_configuration_when_enabled(): void
    {
        $this->configureScheduler();
        config()->set('services.aws.scheduler.target_arn', null);
        $mockHandler = new MockHandler;
        $scheduler = new SchedulerService($this->schedulerClient($mockHandler));

        try {
            $scheduler->scheduleProductionCompletion(
                playerId: 'player-123',
                productionId: '11111111-1111-4111-8111-111111111111',
                correlationId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                completesAt: '2026-08-31T12:01:00.000000Z',
            );
            $this->fail('Missing Scheduler target configuration was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'EventBridge Scheduler [target_arn] must be configured.',
                $exception->getMessage(),
            );
        }

        $this->assertCount(0, $mockHandler);
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

    private function schedulerClient(MockHandler $mockHandler): SchedulerClient
    {
        return new SchedulerClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => new Credentials('test', 'test'),
            'handler' => $mockHandler,
        ]);
    }
}
