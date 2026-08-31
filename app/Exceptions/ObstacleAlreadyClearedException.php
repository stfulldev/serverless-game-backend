<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class ObstacleAlreadyClearedException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'OBSTACLE_ALREADY_CLEARED',
            Response::HTTP_CONFLICT,
            'Obstacle has already been cleared.',
        );
    }
}
