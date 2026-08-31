#!/usr/bin/env node

import { App } from 'aws-cdk-lib';
import { AuthStack } from '../lib/auth-stack';
import { EventingStack } from '../lib/eventing-stack';
import { GameplayStack } from '../lib/gameplay-stack';
import { ObservabilityStack } from '../lib/observability-stack';
import { ProductionStack } from '../lib/production-stack';

const app = new App();
const environmentName = app.node.tryGetContext('environment');

if (typeof environmentName !== 'string' || !/^[a-z][a-z0-9-]*$/.test(environmentName)) {
    throw new Error('CDK context [environment] must contain a valid environment name.');
}

const isProduction = environmentName === 'prod';
const stackEnvironment = {
    account: process.env.CDK_DEFAULT_ACCOUNT,
    region: process.env.CDK_DEFAULT_REGION ?? process.env.AWS_DEFAULT_REGION ?? 'us-east-1',
};

const gameplayStack = new GameplayStack(app, `ServerlessGameBackend-${environmentName}-Gameplay`, {
    environmentName,
    stackName: `serverless-game-backend-${environmentName}-gameplay`,
    description: `DynamoDB gameplay storage for Serverless Game Backend (${environmentName})`,
    env: stackEnvironment,
    terminationProtection: isProduction,
});

const authStack = new AuthStack(app, `ServerlessGameBackend-${environmentName}-Auth`, {
    environmentName,
    playersTable: gameplayStack.playersTable,
    walletsTable: gameplayStack.walletsTable,
    outboxEventsTable: gameplayStack.outboxEventsTable,
    stackName: `serverless-game-backend-${environmentName}-auth`,
    description: `Cognito authentication for Serverless Game Backend (${environmentName})`,
    env: stackEnvironment,
    terminationProtection: isProduction,
});

const productionStack = new ProductionStack(app, `ServerlessGameBackend-${environmentName}-Production`, {
    environmentName,
    productionsTable: gameplayStack.productionsTable,
    outboxEventsTable: gameplayStack.outboxEventsTable,
    stackName: `serverless-game-backend-${environmentName}-production`,
    description: `Production timers and completion Lambda for Serverless Game Backend (${environmentName})`,
    env: stackEnvironment,
    terminationProtection: isProduction,
});

const eventingStack = new EventingStack(app, `ServerlessGameBackend-${environmentName}-Eventing`, {
    environmentName,
    outboxEventsTable: gameplayStack.outboxEventsTable,
    stackName: `serverless-game-backend-${environmentName}-eventing`,
    description: `Outbox publication and domain event consumers for Serverless Game Backend (${environmentName})`,
    env: stackEnvironment,
    terminationProtection: isProduction,
});

new ObservabilityStack(app, `ServerlessGameBackend-${environmentName}-Observability`, {
    environmentName,
    lambdaFunctions: {
        'Cognito post-confirmation': authStack.postConfirmationFunction,
        'Production completion': productionStack.completeProductionFunction,
        'Outbox enrichment': eventingStack.outboxEnrichmentFunction,
        'Domain event consumer': eventingStack.domainEventConsumerFunction,
    },
    coreTables: {
        Players: gameplayStack.playersTable,
        Wallets: gameplayStack.walletsTable,
        Commands: gameplayStack.commandsTable,
        Outbox: gameplayStack.outboxEventsTable,
    },
    gameplayTables: {
        Buildings: gameplayStack.buildingsTable,
        Productions: gameplayStack.productionsTable,
        'Occupied cells': gameplayStack.occupiedCellsTable,
        'Cleared obstacles': gameplayStack.clearedObstaclesTable,
    },
    deadLetterQueues: {
        'Production completion': productionStack.completionDeadLetterQueue,
        'Outbox Pipe': eventingStack.outboxPipeDeadLetterQueue,
        'Event delivery': eventingStack.eventDeliveryDeadLetterQueue,
        'Domain event consumer': eventingStack.consumerDeadLetterQueue,
    },
    domainEventQueue: eventingStack.domainEventQueue,
    outboxPipeName: eventingStack.outboxPipe.ref,
    stackName: `serverless-game-backend-${environmentName}-observability`,
    description: `CloudWatch dashboards and alarms for Serverless Game Backend (${environmentName})`,
    env: stackEnvironment,
    terminationProtection: isProduction,
});
