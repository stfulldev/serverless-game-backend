<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidCognitoTokenException extends RuntimeException implements ShouldntReport {}
