<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class InsufficientFundsException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'INSUFFICIENT_FUNDS',
            Response::HTTP_CONFLICT,
            'The wallet does not contain enough coins.',
        );
    }
}
