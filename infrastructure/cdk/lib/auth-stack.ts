import {
    CfnOutput,
    Duration,
    RemovalPolicy,
    Stack,
    type StackProps,
    Tags,
} from 'aws-cdk-lib';
import { packagePhpCode, PhpFunction } from '@bref.sh/constructs';
import * as cognito from 'aws-cdk-lib/aws-cognito';
import type * as dynamodb from 'aws-cdk-lib/aws-dynamodb';
import * as iam from 'aws-cdk-lib/aws-iam';
import * as logs from 'aws-cdk-lib/aws-logs';
import type { Construct } from 'constructs';
import path from 'node:path';

export interface AuthStackProps extends StackProps {
    readonly environmentName: string;
    readonly playersTable: dynamodb.ITable;
    readonly walletsTable: dynamodb.ITable;
    readonly outboxEventsTable: dynamodb.ITable;
}

export class AuthStack extends Stack {
    public readonly userPool: cognito.UserPool;

    public readonly unityClient: cognito.UserPoolClient;

    public readonly postConfirmationFunction: PhpFunction;

    public constructor(scope: Construct, id: string, props: AuthStackProps) {
        super(scope, id, props);

        const isProduction = props.environmentName === 'prod';
        const removalPolicy = isProduction ? RemovalPolicy.RETAIN : RemovalPolicy.DESTROY;
        const resourcePrefix = `serverless-game-backend-${props.environmentName}`;

        this.userPool = new cognito.UserPool(this, 'Players', {
            userPoolName: `${resourcePrefix}-players`,
            selfSignUpEnabled: true,
            signInAliases: {
                email: true,
            },
            signInCaseSensitive: false,
            autoVerify: {
                email: true,
            },
            standardAttributes: {
                email: {
                    required: true,
                    mutable: true,
                },
            },
            accountRecovery: cognito.AccountRecovery.EMAIL_ONLY,
            passwordPolicy: {
                minLength: 10,
                requireDigits: true,
                requireLowercase: true,
                requireSymbols: false,
                requireUppercase: true,
                tempPasswordValidity: Duration.days(3),
            },
            userVerification: {
                emailSubject: 'Verify your Serverless Farm account',
                emailBody: 'Your verification code is {####}',
                emailStyle: cognito.VerificationEmailStyle.CODE,
            },
            deletionProtection: isProduction,
            removalPolicy,
        });

        this.unityClient = this.userPool.addClient('UnityClient', {
            userPoolClientName: `${resourcePrefix}-unity`,
            generateSecret: false,
            disableOAuth: true,
            authFlows: {
                userSrp: true,
            },
            preventUserExistenceErrors: true,
            enableTokenRevocation: true,
            accessTokenValidity: Duration.hours(1),
            idTokenValidity: Duration.hours(1),
            refreshTokenValidity: Duration.days(30),
        });

        const postConfirmationLogGroup = new logs.LogGroup(this, 'PostConfirmationLogs', {
            logGroupName: `/aws/lambda/${resourcePrefix}-post-confirmation`,
            retention: isProduction
                ? logs.RetentionDays.ONE_MONTH
                : logs.RetentionDays.ONE_WEEK,
            removalPolicy,
        });

        this.postConfirmationFunction = new PhpFunction(this, 'PostConfirmation', {
            functionName: `${resourcePrefix}-post-confirmation`,
            description: 'Handles Cognito events after a user confirms signup or password recovery.',
            phpVersion: '8.4',
            handler: 'App\\Lambda\\Cognito\\PostConfirmationHandler',
            code: packagePhpCode(path.join(__dirname, '../../..'), {
                exclude: [
                    '.env',
                    '.env.*',
                    '.agents',
                    '.claude',
                    '.codex',
                    '.cursor',
                    '.github',
                    '.idea',
                    '.vscode',
                    '.dockerignore',
                    '.editorconfig',
                    '.gitattributes',
                    '.gitignore',
                    '.mcp.json',
                    '.npmrc',
                    '.phpunit.result.cache',
                    'AGENTS.md',
                    'CLAUDE.md',
                    'boost.json',
                    'composer.json',
                    'composer.lock',
                    'database',
                    'docker',
                    'docker-compose.yml',
                    'docs',
                    'infrastructure',
                    'node_modules',
                    'public/build',
                    'storage/logs/*',
                    'tests',
                    'Makefile',
                    'README.md',
                    'package.json',
                    'package-lock.json',
                    'phpunit.xml',
                    'vite.config.js',
                ],
            }),
            memorySize: 1024,
            timeout: Duration.seconds(15),
            logGroup: postConfirmationLogGroup,
            environment: {
                APP_ENV: props.environmentName,
                APP_DEBUG: 'false',
                LOG_CHANNEL: 'stderr',
                LOG_LEVEL: 'info',
                CACHE_STORE: 'array',
                SESSION_DRIVER: 'array',
                DYNAMODB_PLAYERS_TABLE: props.playersTable.tableName,
                DYNAMODB_WALLETS_TABLE: props.walletsTable.tableName,
                DYNAMODB_OUTBOX_EVENTS_TABLE: props.outboxEventsTable.tableName,
                GAME_MAP_VERSION: 'v1',
                GAME_STARTING_COINS: '1000',
            },
        });

        this.postConfirmationFunction.addToRolePolicy(new iam.PolicyStatement({
            actions: ['dynamodb:TransactGetItems'],
            resources: [
                props.playersTable.tableArn,
                props.walletsTable.tableArn,
            ],
        }));

        this.postConfirmationFunction.addToRolePolicy(new iam.PolicyStatement({
            actions: ['dynamodb:TransactWriteItems'],
            resources: [
                props.playersTable.tableArn,
                props.walletsTable.tableArn,
                props.outboxEventsTable.tableArn,
            ],
        }));

        this.userPool.addTrigger(
            cognito.UserPoolOperation.POST_CONFIRMATION,
            this.postConfirmationFunction,
        );

        Tags.of(this).add('Project', 'serverless-game-backend');
        Tags.of(this).add('Environment', props.environmentName);

        new CfnOutput(this, 'UserPoolId', {
            value: this.userPool.userPoolId,
            description: 'Set this value as COGNITO_USER_POOL_ID.',
        });

        new CfnOutput(this, 'UserPoolClientId', {
            value: this.unityClient.userPoolClientId,
            description: 'Set this value as COGNITO_CLIENT_ID.',
        });

        new CfnOutput(this, 'Issuer', {
            value: `https://cognito-idp.${this.region}.${this.urlSuffix}/${this.userPool.userPoolId}`,
            description: 'Set this value as COGNITO_ISSUER.',
        });
    }
}
