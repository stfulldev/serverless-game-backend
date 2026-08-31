<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class CellsOccupiedException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'CELLS_OCCUPIED',
            Response::HTTP_CONFLICT,
            'One or more cells are occupied.',
        );
    }
}
