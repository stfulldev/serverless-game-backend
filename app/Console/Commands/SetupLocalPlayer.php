<?php

namespace App\Console\Commands;

use App\Services\PlayerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature('player:setup-local {playerId} {mapVersion} {mapSeed} {startingCoins}')]
#[Description('Create a local player farm when it does not exist')]
final class SetupLocalPlayer extends Command
{
    /** @throws JsonException */
    public function handle(PlayerService $players): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('This command can only run in the local environment.');

            return self::FAILURE;
        }

        $playerId = $this->argument('playerId');
        $mapVersion = $this->argument('mapVersion');
        $mapSeed = $this->argument('mapSeed');
        $startingCoins = filter_var($this->argument('startingCoins'), FILTER_VALIDATE_INT);

        if (! is_string($playerId) || ! is_string($mapVersion) || ! is_string($mapSeed) || $startingCoins === false) {
            $this->error('Player ID, map version, map seed and an integer starting balance are required.');

            return self::FAILURE;
        }

        $profile = $players->setupPlayer($playerId, $mapVersion, $mapSeed, $startingCoins);

        $this->line(json_encode($profile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
