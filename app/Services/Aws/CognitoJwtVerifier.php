<?php

namespace App\Services\Aws;

use App\Exceptions\CognitoJwksUnavailableException;
use App\Exceptions\InvalidCognitoTokenException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use JsonException;
use LogicException;
use Throwable;

final class CognitoJwtVerifier
{
    private const string Algorithm = 'RS256';

    /**
     * @var array<string, array{expires_at: int, keys: array<string, Key>}>
     */
    private static array $cachedKeySets = [];

    /**
     * @return array{sub: string, client_id: string, iss: string, token_use: string, exp: int, iat: int, ...}
     */
    public function verifyAccessToken(string $token): array
    {
        $issuer = $this->issuer();
        $clientId = $this->requiredConfiguration('client_id');
        $jwksUrl = $issuer.'/.well-known/jwks.json';

        try {
            $header = $this->decodeHeader($token);
            $keyId = $header['kid'] ?? null;
            $algorithm = $header['alg'] ?? null;

            if (! is_string($keyId) || $keyId === '' || $algorithm !== self::Algorithm) {
                throw new InvalidCognitoTokenException('The JWT header is invalid.');
            }

            $keys = $this->keySet($jwksUrl);

            if (! array_key_exists($keyId, $keys)) {
                $keys = $this->keySet($jwksUrl, refresh: true);
            }

            if (! array_key_exists($keyId, $keys)) {
                throw new InvalidCognitoTokenException('The JWT signing key is unknown.');
            }

            /** @var array<string, mixed> $claims */
            $claims = (array) JWT::decode($token, $keys);
            $this->validateClaims($claims, $issuer, $clientId);

            /** @var array{sub: string, client_id: string, iss: string, token_use: string, exp: int, iat: int, ...} $claims */
            return $claims;
        } catch (CognitoJwksUnavailableException|InvalidCognitoTokenException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidCognitoTokenException('The Cognito access token is invalid.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decodeHeader(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new InvalidCognitoTokenException('The JWT has an invalid number of segments.');
        }

        try {
            $header = json_decode(
                JWT::urlsafeB64Decode($segments[0]),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidCognitoTokenException('The JWT header cannot be decoded.', previous: $exception);
        }

        if (! is_array($header)) {
            throw new InvalidCognitoTokenException('The JWT header must be an object.');
        }

        return $header;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(array $claims, string $issuer, string $clientId): void
    {
        if (($claims['iss'] ?? null) !== $issuer) {
            throw new InvalidCognitoTokenException('The JWT issuer is invalid.');
        }

        if (($claims['client_id'] ?? null) !== $clientId) {
            throw new InvalidCognitoTokenException('The JWT client ID is invalid.');
        }

        if (($claims['token_use'] ?? null) !== 'access') {
            throw new InvalidCognitoTokenException('The JWT is not a Cognito access token.');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new InvalidCognitoTokenException('The JWT subject is missing.');
        }

        if (! is_int($claims['exp'] ?? null) || ! is_int($claims['iat'] ?? null)) {
            throw new InvalidCognitoTokenException('The JWT timestamps are invalid.');
        }
    }

    /** @return array<string, Key> */
    private function keySet(string $jwksUrl, bool $refresh = false): array
    {
        $cachedKeySet = self::$cachedKeySets[$jwksUrl] ?? null;

        if (! $refresh && $cachedKeySet !== null && $cachedKeySet['expires_at'] > time()) {
            return $cachedKeySet['keys'];
        }

        try {
            $jwks = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(5)
                ->get($jwksUrl)
                ->throw()
                ->json();

            if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
                throw new LogicException('Cognito returned an invalid JWKS document.');
            }

            /** @var array<string, Key> $keys */
            $keys = JWK::parseKeySet($jwks, self::Algorithm);
        } catch (Throwable $exception) {
            throw new CognitoJwksUnavailableException('Unable to load Cognito signing keys.', previous: $exception);
        }

        self::$cachedKeySets[$jwksUrl] = [
            'expires_at' => time() + $this->keySetCacheTtl(),
            'keys' => $keys,
        ];

        return $keys;
    }

    private function issuer(): string
    {
        $configuredIssuer = config('services.cognito.issuer');

        if (is_string($configuredIssuer) && $configuredIssuer !== '') {
            return rtrim($configuredIssuer, '/');
        }

        $region = $this->requiredConfiguration('region');
        $userPoolId = $this->requiredConfiguration('user_pool_id');

        return "https://cognito-idp.{$region}.amazonaws.com/{$userPoolId}";
    }

    private function requiredConfiguration(string $key): string
    {
        $value = config("services.cognito.{$key}");

        if (! is_string($value) || $value === '') {
            throw new LogicException("Cognito configuration [{$key}] is required.");
        }

        return $value;
    }

    private function keySetCacheTtl(): int
    {
        $ttl = filter_var(config('services.cognito.jwks_cache_ttl'), FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 60) {
            throw new LogicException('Cognito JWKS cache TTL must be at least 60 seconds.');
        }

        return $ttl;
    }
}
