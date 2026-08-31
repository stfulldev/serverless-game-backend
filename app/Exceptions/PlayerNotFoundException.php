<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class PlayerNotFoundException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'FARM_NOT_FOUND',
            Response::HTTP_NOT_FOUND,
            'Farm not found.',
        );
    }
}
