<?php

declare(strict_types=1);

namespace Tests\Feature\Lambda\Cognito;

use App\Lambda\Cognito\PostConfirmationHandler;
use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use Bref\Bref;
use Bref\Context\Context;
use Bref\LaravelBridge\HandlerResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PostConfirmationHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        parent::tearDown();
    }

    public function test_confirm_sign_up_creates_initial_player_state_and_returns_event(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-31 12:00:00 UTC'));
        Str::createUuidsUsingSequence([
            Uuid::fromString('11111111-1111-4111-8111-111111111111'),
            Uuid::fromString('22222222-2222-4222-8222-222222222222'),
            Uuid::fromString('33333333-3333-4333-8333-333333333333'),
        ]);
        $this->configureGame();

        $commands = [];
        $marshaler = new Marshaler;
        $mockHandler = new MockHandler([
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result(['Responses' => [[], []]]);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result;
            },
            static function (CommandInterface $command) use (&$commands, $marshaler): Result {
                $commands[] = $command;

                return new Result([
                    'Responses' => [
                        ['Item' => $marshaler->marshalItem([
                            'player_id' => 'player-123',
                            'map_version' => 'map-v1',
                            'map_seed' => '11111111-1111-4111-8111-111111111111',
                            'created_at' => '2026-08-31T12:00:00.000000Z',
                            'updated_at' => '2026-08-31T12:00:00.000000Z',
                        ])],
                        ['Item' => $marshaler->marshalItem([
                            'player_id' => 'player-123',
                            'coins' => 250,
                            'resources' => [],
                        ])],
                    ],
                ]);
            },
        ]);
        $handler = $this->handler($mockHandler);
        $event = $this->event('PostConfirmation_ConfirmSignUp', 'player-123');

        $result = $handler($event, Context::fake());

        $this->assertSame($event, $result);
        $this->assertSame(
            ['TransactGetItems', 'TransactWriteItems', 'TransactGetItems'],
            array_map(static fn (CommandInterface $command): string => $command->getName(), $commands),
        );

        $transaction = $commands[1]['TransactItems'];
        $this->assertSame(
            ['test-players', 'test-wallets', 'test-outbox-events'],
            array_map(
                static fn (array $item): string => $item['Put']['TableName'],
                $transaction,
            ),
        );

        $player = $marshaler->unmarshalItem($transaction[0]['Put']['Item']);
        $wallet = $marshaler->unmarshalItem($transaction[1]['Put']['Item']);
        $outboxEvent = $marshaler->unmarshalItem($transaction[2]['Put']['Item']);

        $this->assertSame('player-123', $player['player_id']);
        $this->assertSame('map-v1', $player['map_version']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $player['map_seed']);
        $this->assertSame('2026-08-31T12:00:00.000000Z', $player['created_at']);

        $this->assertSame(250, $wallet['coins']);
        $this->assertSame([], $wallet['resources']);

        $this->assertSame('22222222-2222-4222-8222-222222222222', $outboxEvent['event_id']);
        $this->assertSame('PlayerCreated.v1', $outboxEvent['event_type']);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $outboxEvent['correlation_id']);
        $this->assertSame(['map_version' => 'map-v1'], $outboxEvent['payload']);
        $this->assertNull($outboxEvent['published_at']);
        $this->assertCount(0, $mockHandler);
    }

    public function test_laravel_bridge_registers_the_handler_resolver(): void
    {
        $this->assertInstanceOf(HandlerResolver::class, Bref::getContainer());
        $this->assertInstanceOf(
            PostConfirmationHandler::class,
            $this->app->make(PostConfirmationHandler::class),
        );
    }

    public function test_non_signup_post_confirmation_returns_event_without_accessing_dynamodb(): void
    {
        $mockHandler = new MockHandler;
        $handler = $this->handler($mockHandler);
        $event = $this->event('PostConfirmation_ConfirmForgotPassword', 'player-123');

        $result = $handler($event, Context::fake());

        $this->assertSame($event, $result);
        $this->assertCount(0, $mockHandler);
    }

    public function test_confirm_sign_up_rejects_event_without_user_subject(): void
    {
        $handler = $this->handler(new MockHandler);
        $event = [
            'triggerSource' => 'PostConfirmation_ConfirmSignUp',
            'request' => ['userAttributes' => []],
        ];

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The Cognito Post Confirmation event must contain a user sub.');

        $handler($event, Context::fake());
    }

    #[DataProvider('invalidGameConfigurationProvider')]
    public function test_confirm_sign_up_rejects_invalid_game_configuration(
        mixed $mapVersion,
        mixed $startingCoins,
        string $message,
    ): void {
        config()->set('game.map_version', $mapVersion);
        config()->set('game.starting_coins', $startingCoins);
        $handler = $this->handler(new MockHandler);
        $event = $this->event('PostConfirmation_ConfirmSignUp', 'player-123');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $handler($event, Context::fake());
    }

    /** @return array<string, array{mixed, mixed, string}> */
    public static function invalidGameConfigurationProvider(): array
    {
        return [
            'empty map version' => ['', 250, 'The game map version must be configured.'],
            'negative starting coins' => [
                'map-v1',
                -1,
                'The starting coin balance must be a non-negative integer.',
            ],
        ];
    }

    private function handler(MockHandler $mockHandler): PostConfirmationHandler
    {
        $this->app->instance(DynamoDbClient::class, $this->dynamoDb($mockHandler));

        return $this->app->make(PostConfirmationHandler::class);
    }

    private function dynamoDb(MockHandler $mockHandler): DynamoDbClient
    {
        return new DynamoDbClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => new Credentials('test', 'test'),
            'handler' => $mockHandler,
        ]);
    }

    private function configureGame(): void
    {
        config()->set([
            'game.map_version' => 'map-v1',
            'game.starting_coins' => 250,
            'services.aws.dynamodb_tables.players' => 'test-players',
            'services.aws.dynamodb_tables.wallets' => 'test-wallets',
            'services.aws.dynamodb_tables.outbox_events' => 'test-outbox-events',
        ]);
    }

    /** @return array<string, mixed> */
    private function event(string $triggerSource, string $playerId): array
    {
        return [
            'triggerSource' => $triggerSource,
            'request' => [
                'userAttributes' => [
                    'sub' => $playerId,
                ],
            ],
        ];
    }
}
