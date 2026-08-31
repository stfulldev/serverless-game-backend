<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class ProductionNotReadyException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'PRODUCTION_NOT_READY',
            Response::HTTP_CONFLICT,
            'Production is not ready.',
        );
    }
}
