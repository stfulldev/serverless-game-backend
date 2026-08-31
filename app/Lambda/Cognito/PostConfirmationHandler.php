<?php

declare(strict_types=1);

namespace App\Lambda\Cognito;

use App\Services\PlayerService;
use Bref\Context\Context;
use Illuminate\Support\Str;

final readonly class PostConfirmationHandler
{
    private const string ConfirmSignUpTrigger = 'PostConfirmation_ConfirmSignUp';

    public function __construct(private PlayerService $playerService) {}

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function __invoke(array $event, Context $context): array
    {
        if (($event['triggerSource'] ?? null) !== self::ConfirmSignUpTrigger) {
            return $event;
        }

        $this->playerService->setupPlayer(
            playerId: $this->playerId($event),
            mapVersion: $this->mapVersion(),
            mapSeed: (string) Str::uuid(),
            startingCoins: $this->startingCoins(),
        );

        return $event;
    }

    /** @param array<string, mixed> $event */
    private function playerId(array $event): string
    {
        $userAttributes = $event['request']['userAttributes'] ?? null;
        $playerId = is_array($userAttributes) ? ($userAttributes['sub'] ?? null) : null;

        if (! is_string($playerId) || $playerId === '') {
            throw new \UnexpectedValueException(
                'The Cognito Post Confirmation event must contain a user sub.',
            );
        }

        return $playerId;
    }

    private function mapVersion(): string
    {
        $mapVersion = config('game.map_version');

        if (! is_string($mapVersion) || $mapVersion === '') {
            throw new \UnexpectedValueException('The game map version must be configured.');
        }

        return $mapVersion;
    }

    private function startingCoins(): int
    {
        $startingCoins = config('game.starting_coins');

        if (! is_int($startingCoins) || $startingCoins < 0) {
            throw new \UnexpectedValueException(
                'The starting coin balance must be a non-negative integer.',
            );
        }

        return $startingCoins;
    }
}
