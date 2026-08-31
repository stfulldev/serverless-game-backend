import {
    CfnOutput,
    RemovalPolicy,
    Stack,
    type StackProps,
    Tags,
} from 'aws-cdk-lib';
import * as dynamodb from 'aws-cdk-lib/aws-dynamodb';
import type { Construct } from 'constructs';

export interface GameplayStackProps extends StackProps {
    readonly environmentName: string;
}

interface GameTableOptions {
    readonly partitionKey: string;
    readonly sortKey?: string;
    readonly stream?: dynamodb.StreamViewType;
    readonly timeToLiveAttribute?: string;
}

export class GameplayStack extends Stack {
    public readonly playersTable: dynamodb.Table;

    public readonly walletsTable: dynamodb.Table;

    public readonly buildingsTable: dynamodb.Table;

    public readonly productionsTable: dynamodb.Table;

    public readonly occupiedCellsTable: dynamodb.Table;

    public readonly commandsTable: dynamodb.Table;

    public readonly outboxEventsTable: dynamodb.Table;

    public constructor(scope: Construct, id: string, props: GameplayStackProps) {
        super(scope, id, props);

        const isProduction = props.environmentName === 'prod';
        const removalPolicy = isProduction ? RemovalPolicy.RETAIN : RemovalPolicy.DESTROY;
        const resourcePrefix = `serverless-game-backend-${props.environmentName}`;
        const createTable = (
            constructId: string,
            tableSuffix: string,
            options: GameTableOptions,
        ): dynamodb.Table => new dynamodb.Table(this, constructId, {
            tableName: `${resourcePrefix}-${tableSuffix}`,
            partitionKey: {
                name: options.partitionKey,
                type: dynamodb.AttributeType.STRING,
            },
            sortKey: options.sortKey === undefined
                ? undefined
                : {
                    name: options.sortKey,
                    type: dynamodb.AttributeType.STRING,
                },
            billingMode: dynamodb.BillingMode.PAY_PER_REQUEST,
            stream: options.stream,
            timeToLiveAttribute: options.timeToLiveAttribute,
            deletionProtection: isProduction,
            pointInTimeRecoverySpecification: isProduction
                ? { pointInTimeRecoveryEnabled: true }
                : undefined,
            removalPolicy,
        });

        this.playersTable = createTable('Players', 'players', {
            partitionKey: 'player_id',
        });

        this.walletsTable = createTable('Wallets', 'wallets', {
            partitionKey: 'player_id',
        });

        this.buildingsTable = createTable('Buildings', 'buildings', {
            partitionKey: 'player_id',
            sortKey: 'building_id',
        });

        this.productionsTable = createTable('Productions', 'productions', {
            partitionKey: 'player_id',
            sortKey: 'production_id',
        });

        this.occupiedCellsTable = createTable('OccupiedCells', 'occupied-cells', {
            partitionKey: 'player_id',
            sortKey: 'cell_id',
        });

        this.commandsTable = createTable('Commands', 'commands', {
            partitionKey: 'player_id',
            sortKey: 'idempotency_key',
            timeToLiveAttribute: 'expires_at',
        });

        this.outboxEventsTable = createTable('OutboxEvents', 'outbox-events', {
            partitionKey: 'player_id',
            sortKey: 'event_id',
            stream: dynamodb.StreamViewType.NEW_AND_OLD_IMAGES,
            timeToLiveAttribute: 'expires_at',
        });

        Tags.of(this).add('Project', 'serverless-game-backend');
        Tags.of(this).add('Environment', props.environmentName);

        this.outputTableName('PlayersTableName', this.playersTable);
        this.outputTableName('WalletsTableName', this.walletsTable);
        this.outputTableName('BuildingsTableName', this.buildingsTable);
        this.outputTableName('ProductionsTableName', this.productionsTable);
        this.outputTableName('OccupiedCellsTableName', this.occupiedCellsTable);
        this.outputTableName('CommandsTableName', this.commandsTable);
        this.outputTableName('OutboxEventsTableName', this.outboxEventsTable);
    }

    private outputTableName(outputId: string, table: dynamodb.Table): void {
        new CfnOutput(this, outputId, {
            value: table.tableName,
        });
    }
}
