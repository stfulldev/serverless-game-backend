<?php

namespace App\Http\Middleware;

use App\Exceptions\CognitoJwksUnavailableException;
use App\Exceptions\InvalidCognitoTokenException;
use App\Services\Aws\CognitoJwtVerifier;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticatePlayer
{
    public function __construct(
        private readonly Application $application,
        private readonly CognitoJwtVerifier $tokens,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->application->environment('local')) {
            return $this->authenticateLocalRequest($request, $next);
        }

        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->unauthenticated();
        }

        try {
            $claims = $this->tokens->verifyAccessToken($token);
        } catch (InvalidCognitoTokenException) {
            return $this->unauthenticated();
        } catch (CognitoJwksUnavailableException $exception) {
            report($exception);

            return response()->json([
                'error' => [
                    'code' => 'AUTHENTICATION_UNAVAILABLE',
                    'message' => 'Authentication is temporarily unavailable.',
                ],
            ], 503);
        }

        $request->attributes->set('playerId', $claims['sub']);

        return $next($request);
    }

    /** @param  Closure(Request): (Response)  $next */
    private function authenticateLocalRequest(Request $request, Closure $next): Response
    {
        $playerId = $request->header('X-Player-Id');

        if (! is_string($playerId) || trim($playerId) === '') {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'The X-Player-Id header is required for local requests.',
                ],
            ], 401);
        }

        $request->attributes->set('playerId', trim($playerId));

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'A valid Cognito access token is required.',
            ],
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
