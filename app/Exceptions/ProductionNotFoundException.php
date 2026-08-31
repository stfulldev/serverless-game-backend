<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class ProductionNotFoundException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'PRODUCTION_NOT_FOUND',
            Response::HTTP_NOT_FOUND,
            'Production not found.',
        );
    }
}
