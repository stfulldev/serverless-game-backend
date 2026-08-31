<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class IdempotencyKeyReusedException extends GameActionException
{
    public function __construct()
    {
        parent::__construct(
            'IDEMPOTENCY_KEY_REUSED',
            Response::HTTP_CONFLICT,
            'The idempotency key has already been used for another request.',
        );
    }
}
