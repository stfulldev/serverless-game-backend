<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class InvalidRecipeException extends GameActionException
{
    public function __construct(string $message = 'Recipe is not available.')
    {
        parent::__construct(
            'INVALID_RECIPE',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $message,
        );
    }
}
