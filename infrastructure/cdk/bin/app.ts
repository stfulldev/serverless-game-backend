#!/usr/bin/env node

import { App } from 'aws-cdk-lib';
import { AuthStack } from '../lib/auth-stack';
import { GameplayStack } from '../lib/gameplay-stack';

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

new AuthStack(app, `ServerlessGameBackend-${environmentName}-Auth`, {
    environmentName,
    playersTable: gameplayStack.playersTable,
    walletsTable: gameplayStack.walletsTable,
    outboxEventsTable: gameplayStack.outboxEventsTable,
    stackName: `serverless-game-backend-${environmentName}-auth`,
    description: `Cognito authentication for Serverless Game Backend (${environmentName})`,
    env: stackEnvironment,
    terminationProtection: isProduction,
});
