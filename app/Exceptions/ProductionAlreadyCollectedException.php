<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class ProductionAlreadyCollectedException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'ALREADY_COLLECTED',
            Response::HTTP_CONFLICT,
            'Production has already been collected.',
        );
    }
}
