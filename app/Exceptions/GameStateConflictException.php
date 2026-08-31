<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class GameStateConflictException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'GAME_STATE_CONFLICT',
            Response::HTTP_CONFLICT,
            'The game state changed while processing the request. Retry the request.',
        );
    }
}
