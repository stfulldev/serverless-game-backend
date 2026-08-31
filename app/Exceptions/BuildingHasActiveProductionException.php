<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class BuildingHasActiveProductionException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'BUILDING_HAS_ACTIVE_PRODUCTION',
            Response::HTTP_CONFLICT,
            'Building already has an active production.',
        );
    }
}
