<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class BuildingNotFoundException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'BUILDING_NOT_FOUND',
            Response::HTTP_NOT_FOUND,
            'Building not found.',
        );
    }
}
