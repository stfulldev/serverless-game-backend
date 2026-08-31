import {
    CfnOutput,
    Duration,
    Stack,
    type StackProps,
    Tags,
} from 'aws-cdk-lib';
import * as cloudwatch from 'aws-cdk-lib/aws-cloudwatch';
import type * as dynamodb from 'aws-cdk-lib/aws-dynamodb';
import type * as lambda from 'aws-cdk-lib/aws-lambda';
import type * as sqs from 'aws-cdk-lib/aws-sqs';
import type { Construct } from 'constructs';

type NamedResources<T> = Readonly<Record<string, T>>;

export interface ObservabilityStackProps extends StackProps {
    readonly environmentName: string;
    readonly lambdaFunctions: NamedResources<lambda.IFunction>;
    readonly coreTables: NamedResources<dynamodb.ITable>;
    readonly gameplayTables: NamedResources<dynamodb.ITable>;
    readonly deadLetterQueues: NamedResources<sqs.IQueue>;
    readonly domainEventQueue: sqs.IQueue;
    readonly outboxPipeName: string;
}

export class ObservabilityStack extends Stack {
    public readonly dashboard: cloudwatch.Dashboard;

    public readonly alarms: cloudwatch.Alarm[];

    private readonly metricPeriod = Duration.minutes(5);

    public constructor(scope: Construct, id: string, props: ObservabilityStackProps) {
        super(scope, id, props);

        this.assertMetricGroupSize('lambdaFunctions', props.lambdaFunctions, 10);
        this.assertMetricGroupSize('coreTables', props.coreTables, 4);
        this.assertMetricGroupSize('gameplayTables', props.gameplayTables, 4);
        this.assertMetricGroupSize('deadLetterQueues', props.deadLetterQueues, 10);

        const resourcePrefix = `serverless-game-backend-${props.environmentName}`;
        const lambdaErrors = this.lambdaMetrics(
            props.lambdaFunctions,
            'errors',
        );
        const lambdaThrottles = this.lambdaMetrics(
            props.lambdaFunctions,
            'throttles',
        );
        const coreTableThrottles = this.tableThrottleMetrics(props.coreTables);
        const gameplayTableThrottles = this.tableThrottleMetrics(props.gameplayTables);
        const allTables = {
            ...props.coreTables,
            ...props.gameplayTables,
        };
        const transactionConflicts = Object.entries(allTables).map(([name, table]) => (
            table.metric('TransactionConflict', {
                label: name,
                period: this.metricPeriod,
                statistic: 'Sum',
            })
        ));
        const deadLetterMessages = Object.entries(props.deadLetterQueues).map(([name, queue]) => (
            queue.metricApproximateNumberOfMessagesVisible({
                label: name,
                period: this.metricPeriod,
                statistic: 'Maximum',
            })
        ));
        const pipeFailures = [
            this.pipeMetric(props.outboxPipeName, 'ExecutionFailed'),
            this.pipeMetric(props.outboxPipeName, 'ExecutionPartiallyFailed'),
            this.pipeMetric(props.outboxPipeName, 'ExecutionTimeout'),
        ];
        const lambdaErrorTotal = this.sumMetrics(lambdaErrors, 'Total Lambda errors', 'le');
        const lambdaThrottleTotal = this.sumMetrics(
            lambdaThrottles,
            'Total Lambda throttles',
            'lt',
        );
        const coreTableThrottleTotal = this.sumMetrics(
            coreTableThrottles,
            'Core table throttle events',
            'ct',
        );
        const gameplayTableThrottleTotal = this.sumMetrics(
            gameplayTableThrottles,
            'Gameplay table throttle events',
            'gt',
        );
        const transactionConflictTotal = this.sumMetrics(
            transactionConflicts,
            'DynamoDB transaction conflicts',
            'tc',
        );
        const deadLetterMessageTotal = this.sumMetrics(
            deadLetterMessages,
            'Messages in dead-letter queues',
            'dq',
        );
        const pipeFailureTotal = this.sumMetrics(
            pipeFailures,
            'Outbox Pipe failures',
            'pf',
        );
        const domainEventOldestMessage = props.domainEventQueue
            .metricApproximateAgeOfOldestMessage({
                label: 'Oldest domain event age',
                period: this.metricPeriod,
                statistic: 'Maximum',
            });

        this.alarms = [
            this.createAlarm(
                'LambdaErrorsAlarm',
                `${resourcePrefix}-lambda-errors`,
                'At least one backend Lambda invocation failed within five minutes.',
                lambdaErrorTotal,
                1,
            ),
            this.createAlarm(
                'LambdaThrottlesAlarm',
                `${resourcePrefix}-lambda-throttles`,
                'At least one backend Lambda invocation was throttled within five minutes.',
                lambdaThrottleTotal,
                1,
            ),
            this.createAlarm(
                'CoreTableThrottlesAlarm',
                `${resourcePrefix}-core-table-throttles`,
                'A player, wallet, command, or outbox table read/write was throttled.',
                coreTableThrottleTotal,
                1,
            ),
            this.createAlarm(
                'GameplayTableThrottlesAlarm',
                `${resourcePrefix}-gameplay-table-throttles`,
                'A building, production, cell, or obstacle table read/write was throttled.',
                gameplayTableThrottleTotal,
                1,
            ),
            this.createAlarm(
                'TransactionConflictsAlarm',
                `${resourcePrefix}-transaction-conflicts`,
                'DynamoDB reported at least ten transactional item conflicts within five minutes.',
                transactionConflictTotal,
                10,
            ),
            this.createAlarm(
                'DeadLetterMessagesAlarm',
                `${resourcePrefix}-dead-letter-messages`,
                'At least one message is waiting in a backend dead-letter queue.',
                deadLetterMessageTotal,
                1,
            ),
            this.createAlarm(
                'DomainEventBacklogAlarm',
                `${resourcePrefix}-domain-event-backlog`,
                'The oldest unprocessed domain event has waited more than five minutes.',
                domainEventOldestMessage,
                300,
                cloudwatch.ComparisonOperator.GREATER_THAN_THRESHOLD,
            ),
            this.createAlarm(
                'OutboxPipeFailuresAlarm',
                `${resourcePrefix}-outbox-pipe-failures`,
                'The outbox EventBridge Pipe failed, partially failed, or timed out.',
                pipeFailureTotal,
                1,
            ),
        ];

        this.dashboard = new cloudwatch.Dashboard(this, 'BackendDashboard', {
            dashboardName: `${resourcePrefix}-operations`,
            defaultInterval: Duration.hours(6),
            periodOverride: cloudwatch.PeriodOverride.INHERIT,
        });
        this.dashboard.addWidgets(
            new cloudwatch.TextWidget({
                markdown: `# Serverless Game Backend — ${props.environmentName}\nOperational health for the current serverless backend resources.`,
                width: 24,
                height: 2,
            }),
        );
        this.dashboard.addWidgets(
            new cloudwatch.AlarmStatusWidget({
                alarms: this.alarms,
                title: 'Backend alarms',
                width: 24,
                height: 4,
                sortBy: cloudwatch.AlarmStatusWidgetSortBy.STATE_UPDATED_TIMESTAMP,
            }),
        );
        this.dashboard.addWidgets(
            new cloudwatch.GraphWidget({
                title: 'Lambda errors',
                left: lambdaErrors,
                width: 12,
                height: 6,
                period: this.metricPeriod,
            }),
            new cloudwatch.GraphWidget({
                title: 'Lambda throttles',
                left: lambdaThrottles,
                width: 12,
                height: 6,
                period: this.metricPeriod,
            }),
        );
        this.dashboard.addWidgets(
            new cloudwatch.GraphWidget({
                title: 'Outbox Pipe failures',
                left: pipeFailures,
                width: 12,
                height: 6,
                period: this.metricPeriod,
            }),
            new cloudwatch.GraphWidget({
                title: 'Domain event queue',
                left: [
                    props.domainEventQueue.metricApproximateNumberOfMessagesOutstanding({
                        label: 'Outstanding messages',
                        period: this.metricPeriod,
                    }),
                ],
                right: [domainEventOldestMessage],
                width: 12,
                height: 6,
                period: this.metricPeriod,
            }),
        );
        this.dashboard.addWidgets(
            new cloudwatch.GraphWidget({
                title: 'DynamoDB throttle events',
                left: [coreTableThrottleTotal, gameplayTableThrottleTotal],
                width: 12,
                height: 6,
                period: this.metricPeriod,
            }),
            new cloudwatch.GraphWidget({
                title: 'DynamoDB transaction conflicts',
                left: transactionConflicts,
                width: 12,
                height: 6,
                period: this.metricPeriod,
            }),
        );
        this.dashboard.addWidgets(
            new cloudwatch.GraphWidget({
                title: 'Dead-letter queue depth',
                left: deadLetterMessages,
                width: 24,
                height: 6,
                period: this.metricPeriod,
            }),
        );

        Tags.of(this).add('Project', 'serverless-game-backend');
        Tags.of(this).add('Environment', props.environmentName);

        new CfnOutput(this, 'DashboardName', {
            value: this.dashboard.dashboardName,
        });
    }

    private lambdaMetrics(
        functions: NamedResources<lambda.IFunction>,
        metric: 'errors' | 'throttles',
    ): cloudwatch.Metric[] {
        return Object.entries(functions).map(([name, lambdaFunction]) => {
            const options = {
                label: name,
                period: this.metricPeriod,
                statistic: 'Sum',
            };

            return metric === 'errors'
                ? lambdaFunction.metricErrors(options)
                : lambdaFunction.metricThrottles(options);
        });
    }

    private tableThrottleMetrics(
        tables: NamedResources<dynamodb.ITable>,
    ): cloudwatch.Metric[] {
        return Object.entries(tables).flatMap(([name, table]) => [
            table.metric('ReadThrottleEvents', {
                label: `${name} reads`,
                period: this.metricPeriod,
                statistic: 'Sum',
            }),
            table.metric('WriteThrottleEvents', {
                label: `${name} writes`,
                period: this.metricPeriod,
                statistic: 'Sum',
            }),
        ]);
    }

    private pipeMetric(pipeName: string, metricName: string): cloudwatch.Metric {
        return new cloudwatch.Metric({
            namespace: 'AWS/EventBridge/Pipes',
            metricName,
            dimensionsMap: {
                PipeName: pipeName,
            },
            label: metricName,
            period: this.metricPeriod,
            statistic: 'Sum',
        });
    }

    private sumMetrics(
        metrics: cloudwatch.IMetric[],
        label: string,
        metricIdPrefix: string,
    ): cloudwatch.MathExpression {
        const usingMetrics = Object.fromEntries(
            metrics.map((metric, index) => [`${metricIdPrefix}${index}`, metric]),
        );

        return new cloudwatch.MathExpression({
            expression: Object.keys(usingMetrics).join(' + '),
            usingMetrics,
            label,
            period: this.metricPeriod,
        });
    }

    private createAlarm(
        constructId: string,
        alarmName: string,
        alarmDescription: string,
        metric: cloudwatch.IMetric,
        threshold: number,
        comparisonOperator = cloudwatch.ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
    ): cloudwatch.Alarm {
        return new cloudwatch.Alarm(this, constructId, {
            alarmName,
            alarmDescription,
            metric,
            threshold,
            comparisonOperator,
            evaluationPeriods: 1,
            datapointsToAlarm: 1,
            treatMissingData: cloudwatch.TreatMissingData.NOT_BREACHING,
        });
    }

    private assertMetricGroupSize<T>(
        name: string,
        resources: NamedResources<T>,
        maximumSize: number,
    ): void {
        const size = Object.keys(resources).length;

        if (size === 0 || size > maximumSize) {
            throw new Error(
                `Observability metric group [${name}] must contain between 1 and ${maximumSize} resources.`,
            );
        }
    }
}
