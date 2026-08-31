<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class ObstacleNotFoundException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'OBSTACLE_NOT_FOUND',
            Response::HTTP_NOT_FOUND,
            'Obstacle not found.',
        );
    }
}
