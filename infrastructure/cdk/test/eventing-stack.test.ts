import { App } from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import test from 'node:test';
import { EventingStack } from '../lib/eventing-stack';
import { GameplayStack } from '../lib/gameplay-stack';

function eventingTemplate(): Template {
    const app = new App();
    const environment = {
        account: '111111111111',
        region: 'us-east-1',
    };
    const gameplay = new GameplayStack(app, 'Gameplay', {
        environmentName: 'test',
        env: environment,
    });
    const eventing = new EventingStack(app, 'Eventing', {
        environmentName: 'test',
        outboxEventsTable: gameplay.outboxEventsTable,
        env: environment,
    });

    return Template.fromStack(eventing);
}

const template = eventingTemplate();

test('outbox inserts are enriched and published to the domain event bus', () => {
    template.resourceCountIs('AWS::Pipes::Pipe', 1);
    template.hasResourceProperties('AWS::Pipes::Pipe', {
        DesiredState: 'RUNNING',
        SourceParameters: {
            DynamoDBStreamParameters: {
                BatchSize: 10,
                DeadLetterConfig: {
                    Arn: Match.anyValue(),
                },
                MaximumBatchingWindowInSeconds: 1,
                MaximumRecordAgeInSeconds: 82_800,
                MaximumRetryAttempts: 10,
                OnPartialBatchItemFailure: 'AUTOMATIC_BISECT',
                ParallelizationFactor: 1,
                StartingPosition: 'TRIM_HORIZON',
            },
            FilterCriteria: {
                Filters: [{
                    Pattern: '{"eventName":["INSERT"]}',
                }],
            },
        },
        Enrichment: Match.anyValue(),
        LogConfiguration: {
            CloudwatchLogsLogDestination: {
                LogGroupArn: Match.anyValue(),
            },
            Level: 'ERROR',
        },
        Target: Match.anyValue(),
        TargetParameters: {
            EventBridgeEventBusParameters: {
                DetailType: '$.eventType',
                Source: 'serverless-game-backend',
                Time: '$.occurredAt',
            },
        },
    });
    template.hasResourceProperties('AWS::Lambda::Function', {
        FunctionName: 'serverless-game-backend-test-outbox-enrichment',
        Handler: 'App\\Lambda\\Events\\OutboxEventEnrichmentHandler',
    });
});

test('domain events use a durable queue with independent delivery and consumer failures', () => {
    template.resourceCountIs('AWS::SQS::Queue', 4);
    template.hasResourceProperties('AWS::SQS::Queue', {
        QueueName: 'serverless-game-backend-test-domain-events',
        RedrivePolicy: {
            deadLetterTargetArn: Match.anyValue(),
            maxReceiveCount: 5,
        },
        SqsManagedSseEnabled: true,
        VisibilityTimeout: 180,
    });
    template.hasResourceProperties('AWS::Events::Rule', {
        EventPattern: {
            source: ['serverless-game-backend'],
        },
        Targets: [Match.objectLike({
            DeadLetterConfig: {
                Arn: Match.anyValue(),
            },
            RetryPolicy: {
                MaximumEventAgeInSeconds: 7_200,
                MaximumRetryAttempts: 10,
            },
        })],
    });
});

test('SQS consumer reports partial failures and pipe role is least privileged', () => {
    template.hasResourceProperties('AWS::Lambda::Function', {
        FunctionName: 'serverless-game-backend-test-domain-event-consumer',
        Handler: 'App\\Lambda\\Events\\DomainEventConsumerHandler',
        Timeout: 30,
    });
    template.hasResourceProperties('AWS::Lambda::EventSourceMapping', {
        BatchSize: 10,
        FunctionResponseTypes: ['ReportBatchItemFailures'],
        MaximumBatchingWindowInSeconds: 1,
    });
    template.hasResourceProperties('AWS::IAM::Policy', {
        PolicyDocument: {
            Statement: Match.arrayWith([
                Match.objectLike({
                    Action: [
                        'dynamodb:DescribeStream',
                        'dynamodb:GetRecords',
                        'dynamodb:GetShardIterator',
                    ],
                    Effect: 'Allow',
                }),
                Match.objectLike({
                    Action: 'dynamodb:ListStreams',
                    Effect: 'Allow',
                    Resource: '*',
                }),
                Match.objectLike({
                    Action: 'lambda:InvokeFunction',
                    Effect: 'Allow',
                }),
                Match.objectLike({
                    Action: 'events:PutEvents',
                    Effect: 'Allow',
                }),
                Match.objectLike({
                    Action: 'sqs:SendMessage',
                    Effect: 'Allow',
                }),
                Match.objectLike({
                    Action: [
                        'logs:CreateLogStream',
                        'logs:PutLogEvents',
                    ],
                    Effect: 'Allow',
                }),
            ]),
        },
    });
});
