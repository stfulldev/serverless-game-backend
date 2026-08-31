import {
    App,
    Stack,
} from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import * as dynamodb from 'aws-cdk-lib/aws-dynamodb';
import * as lambda from 'aws-cdk-lib/aws-lambda';
import * as sqs from 'aws-cdk-lib/aws-sqs';
import assert from 'node:assert/strict';
import test from 'node:test';
import { ObservabilityStack } from '../lib/observability-stack';

function observabilityTemplate(): Template {
    const app = new App();
    const environment = {
        account: '111111111111',
        region: 'us-east-1',
    };
    const resources = new Stack(app, 'Resources', {
        env: environment,
    });
    const functions = Object.fromEntries(
        ['Auth', 'Production', 'Enrichment', 'Consumer'].map((name) => [
            name,
            new lambda.Function(resources, `${name}Function`, {
                runtime: lambda.Runtime.NODEJS_22_X,
                handler: 'index.handler',
                code: lambda.Code.fromInline('exports.handler = async () => {};'),
            }),
        ]),
    );
    const tables = Object.fromEntries(
        [
            'Players',
            'Wallets',
            'Commands',
            'Outbox',
            'Buildings',
            'Productions',
            'OccupiedCells',
            'ClearedObstacles',
        ].map((name) => [
            name,
            new dynamodb.Table(resources, `${name}Table`, {
                partitionKey: {
                    name: 'pk',
                    type: dynamodb.AttributeType.STRING,
                },
            }),
        ]),
    );
    const deadLetterQueues = Object.fromEntries(
        ['Production', 'Pipe', 'Delivery', 'Consumer'].map((name) => [
            name,
            new sqs.Queue(resources, `${name}DeadLetterQueue`),
        ]),
    );
    const domainEventQueue = new sqs.Queue(resources, 'DomainEventQueue');
    const observability = new ObservabilityStack(app, 'Observability', {
        environmentName: 'test',
        lambdaFunctions: functions,
        coreTables: {
            Players: tables.Players,
            Wallets: tables.Wallets,
            Commands: tables.Commands,
            Outbox: tables.Outbox,
        },
        gameplayTables: {
            Buildings: tables.Buildings,
            Productions: tables.Productions,
            'Occupied cells': tables.OccupiedCells,
            'Cleared obstacles': tables.ClearedObstacles,
        },
        deadLetterQueues,
        domainEventQueue,
        outboxPipeName: 'serverless-game-backend-test-outbox-events',
        env: environment,
    });

    return Template.fromStack(observability);
}

const template = observabilityTemplate();

test('operations dashboard covers backend compute, storage, queues, and event delivery', () => {
    template.resourceCountIs('AWS::CloudWatch::Dashboard', 1);
    template.hasResourceProperties('AWS::CloudWatch::Dashboard', {
        DashboardName: 'serverless-game-backend-test-operations',
    });

    const dashboards = template.findResources('AWS::CloudWatch::Dashboard');
    const dashboardBody = JSON.stringify(
        Object.values(dashboards)[0].Properties.DashboardBody,
    );

    assert.match(dashboardBody, /Lambda errors/);
    assert.match(dashboardBody, /DynamoDB throttle events/);
    assert.match(dashboardBody, /Dead-letter queue depth/);
});

test('critical backend failures create actionable alarms', () => {
    template.resourceCountIs('AWS::CloudWatch::Alarm', 8);
    template.hasResourceProperties('AWS::CloudWatch::Alarm', {
        AlarmName: 'serverless-game-backend-test-lambda-errors',
        Threshold: 1,
        TreatMissingData: 'notBreaching',
    });
    template.hasResourceProperties('AWS::CloudWatch::Alarm', {
        AlarmName: 'serverless-game-backend-test-domain-event-backlog',
        ComparisonOperator: 'GreaterThanThreshold',
        Threshold: 300,
    });
    template.hasResourceProperties('AWS::CloudWatch::Alarm', {
        AlarmName: 'serverless-game-backend-test-outbox-pipe-failures',
        Metrics: Match.arrayWith([
            Match.objectLike({
                MetricStat: {
                    Metric: {
                        Dimensions: [{
                            Name: 'PipeName',
                            Value: 'serverless-game-backend-test-outbox-events',
                        }],
                        MetricName: 'ExecutionFailed',
                        Namespace: 'AWS/EventBridge/Pipes',
                    },
                },
            }),
        ]),
    });
});

test('metric-math alarms stay within the CloudWatch ten-metric quota', () => {
    const alarms = template.findResources('AWS::CloudWatch::Alarm');

    for (const [logicalId, alarm] of Object.entries(alarms)) {
        const metricQueries = alarm.Properties.Metrics ?? [];
        const metricStats = metricQueries.filter(
            (query: Record<string, unknown>) => 'MetricStat' in query,
        );

        assert.ok(
            metricStats.length <= 10,
            `${logicalId} contains ${metricStats.length} underlying metrics`,
        );
    }
});
