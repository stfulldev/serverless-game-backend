<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class InvalidPlacementException extends GameActionException
{
    public function __construct(string $message = 'Building cannot be placed at the requested coordinates.')
    {
        parent::__construct(
            'INVALID_PLACEMENT',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $message,
        );
    }
}
