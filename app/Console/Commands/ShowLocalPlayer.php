<?php

namespace App\Console\Commands;

use App\Services\PlayerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('player:show-local {playerId}')]
#[Description('Show a player profile from local DynamoDB')]
final class ShowLocalPlayer extends Command
{
    /** @throws JsonException */
    public function handle(PlayerService $players): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('This command can only run in the local environment.');

            return self::FAILURE;
        }

        $playerId = $this->argument('playerId');

        if (! is_string($playerId)) {
            $this->error('Player ID is required.');

            return self::FAILURE;
        }

        $profile = $players->getProfile($playerId);

        if ($profile === null) {
            $this->error("Player [{$playerId}] does not exist.");

            return self::FAILURE;
        }

        $this->line(json_encode($profile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
