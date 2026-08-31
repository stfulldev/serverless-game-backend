<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

abstract class GameActionException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        private readonly string $errorCode,
        private readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
