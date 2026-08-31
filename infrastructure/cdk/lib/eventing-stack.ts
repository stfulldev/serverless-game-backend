import {
    CfnOutput,
    Duration,
    RemovalPolicy,
    Stack,
    type StackProps,
    Tags,
} from 'aws-cdk-lib';
import { PhpFunction } from '@bref.sh/constructs';
import type * as dynamodb from 'aws-cdk-lib/aws-dynamodb';
import * as events from 'aws-cdk-lib/aws-events';
import * as targets from 'aws-cdk-lib/aws-events-targets';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as sources from 'aws-cdk-lib/aws-lambda-event-sources';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as pipes from 'aws-cdk-lib/aws-pipes';
import * as sqs from 'aws-cdk-lib/aws-sqs';
import type { Construct } from 'constructs';
import { packageLaravelCode } from './laravel-code';

export interface EventingStackProps extends StackProps {
    readonly environmentName: string;
    readonly outboxEventsTable: dynamodb.ITable;
}

export class EventingStack extends Stack {
    public readonly domainEventBus: events.EventBus;

    public readonly outboxEnrichmentFunction: PhpFunction;

    public readonly domainEventConsumerFunction: PhpFunction;

    public readonly domainEventQueue: sqs.Queue;

    public readonly consumerDeadLetterQueue: sqs.Queue;

    public readonly outboxPipeDeadLetterQueue: sqs.Queue;

    public readonly eventDeliveryDeadLetterQueue: sqs.Queue;

    public readonly outboxPipe: pipes.CfnPipe;

    public constructor(scope: Construct, id: string, props: EventingStackProps) {
        super(scope, id, props);

        const outboxStreamArn = props.outboxEventsTable.tableStreamArn;

        if (outboxStreamArn === undefined) {
            throw new Error('The outbox events table must have a DynamoDB Stream enabled.');
        }

        const isProduction = props.environmentName === 'prod';
        const removalPolicy = isProduction ? RemovalPolicy.RETAIN : RemovalPolicy.DESTROY;
        const resourcePrefix = `serverless-game-backend-${props.environmentName}`;
        const logRetention = isProduction
            ? logs.RetentionDays.ONE_MONTH
            : logs.RetentionDays.ONE_WEEK;

        this.domainEventBus = new events.EventBus(this, 'DomainEventBus', {
            eventBusName: `${resourcePrefix}-domain-events`,
        });
        this.domainEventBus.applyRemovalPolicy(removalPolicy);

        this.outboxEnrichmentFunction = new PhpFunction(this, 'OutboxEnrichment', {
            functionName: `${resourcePrefix}-outbox-enrichment`,
            description: 'Converts DynamoDB outbox records into versioned domain events.',
            phpVersion: '8.4',
            handler: 'App\\Lambda\\Events\\OutboxEventEnrichmentHandler',
            code: packageLaravelCode(),
            memorySize: 512,
            timeout: Duration.seconds(10),
            logGroup: new logs.LogGroup(this, 'OutboxEnrichmentLogs', {
                logGroupName: `/aws/lambda/${resourcePrefix}-outbox-enrichment`,
                retention: logRetention,
                removalPolicy,
            }),
            environment: this.lambdaEnvironment(props.environmentName),
        });

        this.domainEventConsumerFunction = new PhpFunction(this, 'DomainEventConsumer', {
            functionName: `${resourcePrefix}-domain-event-consumer`,
            description: 'Consumes versioned domain events from SQS with partial batch failures.',
            phpVersion: '8.4',
            handler: 'App\\Lambda\\Events\\DomainEventConsumerHandler',
            code: packageLaravelCode(),
            memorySize: 512,
            timeout: Duration.seconds(30),
            logGroup: new logs.LogGroup(this, 'DomainEventConsumerLogs', {
                logGroupName: `/aws/lambda/${resourcePrefix}-domain-event-consumer`,
                retention: logRetention,
                removalPolicy,
            }),
            environment: this.lambdaEnvironment(props.environmentName),
        });

        this.outboxPipeDeadLetterQueue = this.deadLetterQueue(
            'OutboxPipeDeadLetterQueue',
            `${resourcePrefix}-outbox-pipe-dlq`,
            removalPolicy,
        );
        this.eventDeliveryDeadLetterQueue = this.deadLetterQueue(
            'EventDeliveryDeadLetterQueue',
            `${resourcePrefix}-event-delivery-dlq`,
            removalPolicy,
        );
        this.consumerDeadLetterQueue = this.deadLetterQueue(
            'ConsumerDeadLetterQueue',
            `${resourcePrefix}-domain-event-consumer-dlq`,
            removalPolicy,
        );
        this.domainEventQueue = new sqs.Queue(this, 'DomainEventQueue', {
            queueName: `${resourcePrefix}-domain-events`,
            encryption: sqs.QueueEncryption.SQS_MANAGED,
            enforceSSL: true,
            retentionPeriod: Duration.days(4),
            visibilityTimeout: Duration.seconds(180),
            deadLetterQueue: {
                queue: this.consumerDeadLetterQueue,
                maxReceiveCount: 5,
            },
            removalPolicy,
        });

        const domainEventRule = new events.Rule(this, 'DomainEventsToConsumer', {
            description: 'Routes all backend domain events to the first durable consumer queue.',
            eventBus: this.domainEventBus,
            eventPattern: {
                source: ['serverless-game-backend'],
            },
        });
        domainEventRule.addTarget(new targets.SqsQueue(this.domainEventQueue, {
            deadLetterQueue: this.eventDeliveryDeadLetterQueue,
            maxEventAge: Duration.hours(2),
            retryAttempts: 10,
        }));

        this.domainEventConsumerFunction.addEventSource(
            new sources.SqsEventSource(this.domainEventQueue, {
                batchSize: 10,
                maxBatchingWindow: Duration.seconds(1),
                reportBatchItemFailures: true,
            }),
        );

        const pipeExecutionRole = new iam.Role(this, 'OutboxPipeExecutionRole', {
            assumedBy: new iam.ServicePrincipal('pipes.amazonaws.com'),
            description: 'Reads the outbox stream, invokes enrichment, and publishes domain events.',
        });
        const pipeExecutionPolicy = new iam.Policy(this, 'OutboxPipeExecutionPolicy', {
            statements: [
                new iam.PolicyStatement({
                    actions: [
                        'dynamodb:DescribeStream',
                        'dynamodb:GetRecords',
                        'dynamodb:GetShardIterator',
                    ],
                    resources: [outboxStreamArn],
                }),
                new iam.PolicyStatement({
                    actions: ['dynamodb:ListStreams'],
                    resources: ['*'],
                }),
                new iam.PolicyStatement({
                    actions: ['lambda:InvokeFunction'],
                    resources: [this.outboxEnrichmentFunction.functionArn],
                }),
                new iam.PolicyStatement({
                    actions: ['events:PutEvents'],
                    resources: [this.domainEventBus.eventBusArn],
                }),
                new iam.PolicyStatement({
                    actions: ['sqs:SendMessage'],
                    resources: [this.outboxPipeDeadLetterQueue.queueArn],
                }),
            ],
        });
        pipeExecutionRole.attachInlinePolicy(pipeExecutionPolicy);

        this.outboxPipe = new pipes.CfnPipe(this, 'OutboxPipe', {
            name: `${resourcePrefix}-outbox-events`,
            description: 'Publishes immutable outbox inserts as canonical domain events.',
            desiredState: 'RUNNING',
            roleArn: pipeExecutionRole.roleArn,
            source: outboxStreamArn,
            sourceParameters: {
                filterCriteria: {
                    filters: [{
                        pattern: JSON.stringify({ eventName: ['INSERT'] }),
                    }],
                },
                dynamoDbStreamParameters: {
                    startingPosition: 'TRIM_HORIZON',
                    batchSize: 10,
                    maximumBatchingWindowInSeconds: 1,
                    maximumRecordAgeInSeconds: 82_800,
                    maximumRetryAttempts: 10,
                    onPartialBatchItemFailure: 'AUTOMATIC_BISECT',
                    parallelizationFactor: 1,
                    deadLetterConfig: {
                        arn: this.outboxPipeDeadLetterQueue.queueArn,
                    },
                },
            },
            enrichment: this.outboxEnrichmentFunction.functionArn,
            target: this.domainEventBus.eventBusArn,
            targetParameters: {
                eventBridgeEventBusParameters: {
                    source: 'serverless-game-backend',
                    detailType: '$.eventType',
                    time: '$.occurredAt',
                },
            },
            tags: {
                Project: 'serverless-game-backend',
                Environment: props.environmentName,
            },
        });
        this.outboxPipe.node.addDependency(pipeExecutionPolicy);

        Tags.of(this).add('Project', 'serverless-game-backend');
        Tags.of(this).add('Environment', props.environmentName);

        new CfnOutput(this, 'DomainEventBusName', {
            value: this.domainEventBus.eventBusName,
        });
        new CfnOutput(this, 'DomainEventQueueUrl', {
            value: this.domainEventQueue.queueUrl,
        });
        new CfnOutput(this, 'ConsumerDeadLetterQueueUrl', {
            value: this.consumerDeadLetterQueue.queueUrl,
        });
        new CfnOutput(this, 'OutboxPipeArn', {
            value: this.outboxPipe.attrArn,
        });
        new CfnOutput(this, 'OutboxPipeDeadLetterQueueUrl', {
            value: this.outboxPipeDeadLetterQueue.queueUrl,
        });
        new CfnOutput(this, 'EventDeliveryDeadLetterQueueUrl', {
            value: this.eventDeliveryDeadLetterQueue.queueUrl,
        });
    }

    /** @return Record<string, string> */
    private lambdaEnvironment(environmentName: string): Record<string, string> {
        return {
            APP_ENV: environmentName,
            APP_DEBUG: 'false',
            LOG_CHANNEL: 'stderr',
            LOG_LEVEL: 'info',
            CACHE_STORE: 'array',
            SESSION_DRIVER: 'array',
        };
    }

    private deadLetterQueue(
        constructId: string,
        queueName: string,
        removalPolicy: RemovalPolicy,
    ): sqs.Queue {
        return new sqs.Queue(this, constructId, {
            queueName,
            encryption: sqs.QueueEncryption.SQS_MANAGED,
            enforceSSL: true,
            retentionPeriod: Duration.days(14),
            removalPolicy,
        });
    }
}
