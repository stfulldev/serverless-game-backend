<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\PlayerService;
use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PlayerServiceTest extends TestCase
{
    public function test_existing_player_state_is_returned_without_writing_new_state(): void
    {
        $this->configureTables();
        $mockHandler = new MockHandler([
            new Result(['Responses' => $this->profileResponses()]),
        ]);
        $service = new PlayerService($this->dynamoDb($mockHandler));

        $profile = $service->setupPlayer(
            playerId: 'player-123',
            mapVersion: 'ignored-map',
            mapSeed: 'ignored-seed',
            startingCoins: 999,
        );

        $this->assertSame([
            'playerId' => 'player-123',
            'map' => [
                'version' => 'existing-map',
                'seed' => 'existing-seed',
            ],
            'wallet' => [
                'coins' => 75,
                'resources' => ['wheat' => 4],
                'version' => 3,
            ],
            'createdAt' => '2026-08-01T10:00:00.000000Z',
            'updatedAt' => '2026-08-02T10:00:00.000000Z',
        ], $profile);
        $this->assertSame('TransactGetItems', $mockHandler->getLastCommand()->getName());
        $this->assertCount(0, $mockHandler);
    }

    public function test_incomplete_player_state_is_rejected(): void
    {
        $this->configureTables();
        $responses = $this->profileResponses();
        $mockHandler = new MockHandler([
            new Result(['Responses' => [$responses[0], []]]),
        ]);
        $service = new PlayerService($this->dynamoDb($mockHandler));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Player [player-123] has an incomplete farm state.');

        $service->getProfile('player-123');
    }

    #[DataProvider('invalidSetupInputProvider')]
    public function test_invalid_setup_input_is_rejected_before_accessing_dynamodb(
        string $playerId,
        string $mapVersion,
        string $mapSeed,
        int $startingCoins,
        string $message,
    ): void {
        $service = new PlayerService($this->dynamoDb(new MockHandler));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $service->setupPlayer($playerId, $mapVersion, $mapSeed, $startingCoins);
    }

    /** @return array<string, array{string, string, string, int, string}> */
    public static function invalidSetupInputProvider(): array
    {
        return [
            'empty player ID' => ['', 'map-v1', 'seed-v1', 100, 'Player ID cannot be empty.'],
            'empty map version' => ['player-123', '', 'seed-v1', 100, 'Map version cannot be empty.'],
            'empty map seed' => ['player-123', 'map-v1', '', 100, 'Map seed cannot be empty.'],
            'negative starting coins' => ['player-123', 'map-v1', 'seed-v1', -1, 'Starting coins cannot be negative.'],
        ];
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

    private function configureTables(): void
    {
        config()->set([
            'services.aws.dynamodb_tables.players' => 'test-players',
            'services.aws.dynamodb_tables.wallets' => 'test-wallets',
        ]);
    }

    /** @return array{array{Item: array<string, mixed>}, array{Item: array<string, mixed>}} */
    private function profileResponses(): array
    {
        $marshaler = new Marshaler;

        return [
            ['Item' => $marshaler->marshalItem([
                'player_id' => 'player-123',
                'map_version' => 'existing-map',
                'map_seed' => 'existing-seed',
                'created_at' => '2026-08-01T10:00:00.000000Z',
                'updated_at' => '2026-08-02T10:00:00.000000Z',
            ])],
            ['Item' => $marshaler->marshalItem([
                'player_id' => 'player-123',
                'coins' => 75,
                'resources' => ['wheat' => 4],
                'version' => 3,
            ])],
        ];
    }
}
