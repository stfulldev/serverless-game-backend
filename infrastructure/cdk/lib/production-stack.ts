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
import * as iam from 'aws-cdk-lib/aws-iam';
import * as logs from 'aws-cdk-lib/aws-logs';
import * as scheduler from 'aws-cdk-lib/aws-scheduler';
import * as sqs from 'aws-cdk-lib/aws-sqs';
import type { Construct } from 'constructs';
import { packageLaravelCode } from './laravel-code';

export interface ProductionStackProps extends StackProps {
    readonly environmentName: string;
    readonly productionsTable: dynamodb.ITable;
    readonly outboxEventsTable: dynamodb.ITable;
}

export class ProductionStack extends Stack {
    public readonly completeProductionFunction: PhpFunction;

    public readonly completionDeadLetterQueue: sqs.Queue;

    public readonly completionScheduleGroup: scheduler.ScheduleGroup;

    public readonly schedulerExecutionRole: iam.Role;

    public constructor(scope: Construct, id: string, props: ProductionStackProps) {
        super(scope, id, props);

        const isProduction = props.environmentName === 'prod';
        const removalPolicy = isProduction ? RemovalPolicy.RETAIN : RemovalPolicy.DESTROY;
        const resourcePrefix = `serverless-game-backend-${props.environmentName}`;

        const completeProductionLogGroup = new logs.LogGroup(this, 'CompleteProductionLogs', {
            logGroupName: `/aws/lambda/${resourcePrefix}-complete-production`,
            retention: isProduction
                ? logs.RetentionDays.ONE_MONTH
                : logs.RetentionDays.ONE_WEEK,
            removalPolicy,
        });

        this.completeProductionFunction = new PhpFunction(this, 'CompleteProduction', {
            functionName: `${resourcePrefix}-complete-production`,
            description: 'Idempotently completes a production when its source timestamp is due.',
            phpVersion: '8.4',
            handler: 'App\\Lambda\\Production\\CompleteProductionHandler',
            code: packageLaravelCode(),
            memorySize: 1024,
            timeout: Duration.seconds(15),
            logGroup: completeProductionLogGroup,
            environment: {
                APP_ENV: props.environmentName,
                APP_DEBUG: 'false',
                LOG_CHANNEL: 'stderr',
                LOG_LEVEL: 'info',
                CACHE_STORE: 'array',
                SESSION_DRIVER: 'array',
                DYNAMODB_PRODUCTIONS_TABLE: props.productionsTable.tableName,
                DYNAMODB_OUTBOX_EVENTS_TABLE: props.outboxEventsTable.tableName,
                EVENTBRIDGE_SCHEDULER_ENABLED: 'false',
            },
        });

        this.completeProductionFunction.addToRolePolicy(new iam.PolicyStatement({
            actions: ['dynamodb:GetItem'],
            resources: [props.productionsTable.tableArn],
        }));

        this.completeProductionFunction.addToRolePolicy(new iam.PolicyStatement({
            actions: ['dynamodb:TransactWriteItems'],
            resources: [
                props.productionsTable.tableArn,
                props.outboxEventsTable.tableArn,
            ],
        }));

        this.completionDeadLetterQueue = new sqs.Queue(this, 'CompletionDeadLetterQueue', {
            queueName: `${resourcePrefix}-production-completion-dlq`,
            encryption: sqs.QueueEncryption.SQS_MANAGED,
            enforceSSL: true,
            retentionPeriod: Duration.days(14),
            removalPolicy,
        });

        this.completionScheduleGroup = new scheduler.ScheduleGroup(
            this,
            'CompletionScheduleGroup',
            {
                scheduleGroupName: `${resourcePrefix}-productions`,
                removalPolicy,
            },
        );

        this.schedulerExecutionRole = new iam.Role(this, 'SchedulerExecutionRole', {
            assumedBy: new iam.ServicePrincipal('scheduler.amazonaws.com'),
            description: 'Lets EventBridge Scheduler invoke production completion and its DLQ.',
        });
        this.completeProductionFunction.grantInvoke(this.schedulerExecutionRole);
        this.completionDeadLetterQueue.grantSendMessages(this.schedulerExecutionRole);

        Tags.of(this).add('Project', 'serverless-game-backend');
        Tags.of(this).add('Environment', props.environmentName);

        new CfnOutput(this, 'CompleteProductionFunctionArn', {
            value: this.completeProductionFunction.functionArn,
            description: 'Set this value as COMPLETE_PRODUCTION_FUNCTION_ARN.',
        });

        new CfnOutput(this, 'CompletionScheduleGroupName', {
            value: this.completionScheduleGroup.scheduleGroupName,
            description: 'Set this value as EVENTBRIDGE_SCHEDULER_GROUP.',
        });

        new CfnOutput(this, 'SchedulerExecutionRoleArn', {
            value: this.schedulerExecutionRole.roleArn,
            description: 'Set this value as EVENTBRIDGE_SCHEDULER_ROLE_ARN.',
        });

        new CfnOutput(this, 'CompletionDeadLetterQueueArn', {
            value: this.completionDeadLetterQueue.queueArn,
            description: 'Set this value as EVENTBRIDGE_SCHEDULER_DLQ_ARN.',
        });
    }
}
