<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Aws;

use App\Exceptions\CognitoJwksUnavailableException;
use App\Exceptions\InvalidCognitoTokenException;
use App\Services\Aws\CognitoJwtVerifier;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

final class CognitoJwtVerifierTest extends TestCase
{
    public function test_valid_cognito_access_token_returns_verified_claims(): void
    {
        $issuer = $this->configureCognito('valid-token-pool');
        [$privateKey, $jwk] = $this->rsaKeyPair('valid-token-key');
        $this->fakeJwks($issuer, $jwk);
        $issuedAt = time();
        $claims = [
            'sub' => 'player-123',
            'client_id' => 'unity-client',
            'iss' => $issuer,
            'token_use' => 'access',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ];
        $token = JWT::encode($claims, $privateKey, 'RS256', 'valid-token-key');

        $verifiedClaims = (new CognitoJwtVerifier)->verifyAccessToken($token);

        $this->assertSame($claims, $verifiedClaims);
        Http::assertSentCount(1);
        Http::assertSent(
            static fn (Request $request): bool => $request->url() === $issuer.'/.well-known/jwks.json',
        );
    }

    public function test_malformed_token_is_rejected_without_requesting_jwks(): void
    {
        $this->configureCognito('malformed-token-pool');
        Http::preventStrayRequests();

        try {
            (new CognitoJwtVerifier)->verifyAccessToken('not-a-jwt');
            $this->fail('Malformed JWT was not rejected.');
        } catch (InvalidCognitoTokenException $exception) {
            $this->assertSame('The JWT has an invalid number of segments.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_access_token_for_another_client_is_rejected(): void
    {
        $issuer = $this->configureCognito('wrong-client-pool');
        [$privateKey, $jwk] = $this->rsaKeyPair('wrong-client-key');
        $this->fakeJwks($issuer, $jwk);
        $issuedAt = time();
        $token = JWT::encode([
            'sub' => 'player-123',
            'client_id' => 'another-client',
            'iss' => $issuer,
            'token_use' => 'access',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], $privateKey, 'RS256', 'wrong-client-key');

        try {
            (new CognitoJwtVerifier)->verifyAccessToken($token);
            $this->fail('JWT for another Cognito client was not rejected.');
        } catch (InvalidCognitoTokenException $exception) {
            $this->assertSame('The JWT client ID is invalid.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_unavailable_jwks_endpoint_raises_authentication_dependency_error(): void
    {
        $issuer = $this->configureCognito('unavailable-jwks-pool');
        [$privateKey] = $this->rsaKeyPair('unavailable-jwks-key');
        Http::preventStrayRequests();
        Http::fake([
            $issuer.'/.well-known/jwks.json' => Http::response(status: 503),
        ]);
        $issuedAt = time();
        $token = JWT::encode([
            'sub' => 'player-123',
            'client_id' => 'unity-client',
            'iss' => $issuer,
            'token_use' => 'access',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], $privateKey, 'RS256', 'unavailable-jwks-key');

        try {
            (new CognitoJwtVerifier)->verifyAccessToken($token);
            $this->fail('Unavailable Cognito JWKS endpoint did not fail verification.');
        } catch (CognitoJwksUnavailableException $exception) {
            $this->assertSame('Unable to load Cognito signing keys.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    private function configureCognito(string $userPoolId): string
    {
        $issuer = "https://cognito-idp.us-east-1.amazonaws.com/{$userPoolId}";

        config()->set([
            'services.cognito.region' => 'us-east-1',
            'services.cognito.user_pool_id' => $userPoolId,
            'services.cognito.client_id' => 'unity-client',
            'services.cognito.issuer' => $issuer,
            'services.cognito.jwks_cache_ttl' => 3600,
        ]);

        return $issuer;
    }

    /**
     * @return array{string, array{kty: string, kid: string, use: string, alg: string, n: string, e: string}}
     */
    private function rsaKeyPair(string $keyId): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Unable to generate an RSA key for the test.');
        }

        $privateKey = '';

        if (! openssl_pkey_export($key, $privateKey)) {
            throw new \RuntimeException('Unable to export the test RSA private key.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! is_array($details['rsa'] ?? null)) {
            throw new \RuntimeException('Unable to read the test RSA public key.');
        }

        return [
            $privateKey,
            [
                'kty' => 'RSA',
                'kid' => $keyId,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
            ],
        ];
    }

    /** @param array{kty: string, kid: string, use: string, alg: string, n: string, e: string} $jwk */
    private function fakeJwks(string $issuer, array $jwk): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $issuer.'/.well-known/jwks.json' => Http::response(['keys' => [$jwk]]),
        ]);
    }
}
