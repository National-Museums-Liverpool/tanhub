<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\AccessToken;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * API endpoints for auth token lifecycle.
 */
class AuthTokens extends ApiController
{
    private const ACCESS_TTL_SECONDS = 3600;
    private const REFRESH_TTL_SECONDS = 2592000;

    /**
     * Issue access and refresh tokens for valid credentials.
     */
    public function token(): ResponseInterface
    {
        $payload = $this->request->getJSON(true);

        if (! is_array($payload)) {
            return $this->respondProblem(400, 'Invalid request body', 'Expected a JSON object request body.');
        }

        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->respondProblem(400, 'Invalid request body', 'username and password are required.');
        }

        $result = auth('session')->check([
            'email' => $username,
            'password' => $password,
        ]);

        if (! $result->isOK()) {
            return $this->respondProblem(401, 'Authentication failed', 'Invalid credentials.');
        }

        $user = $result->extraInfo();

        $tokens = $this->issueTokenPair($user, $username);

        return $this->response->setJSON($tokens);
    }

    /**
     * Rotate refresh token and issue a new token pair.
     */
    public function refresh(): ResponseInterface
    {
        $payload = $this->request->getJSON(true);

        if (! is_array($payload)) {
            return $this->respondProblem(400, 'Invalid request body', 'Expected a JSON object request body.');
        }

        $refreshTokenRaw = trim((string) ($payload['refresh_token'] ?? ''));

        if ($refreshTokenRaw === '') {
            return $this->respondProblem(400, 'Invalid request body', 'refresh_token is required.');
        }

        /** @var UserIdentityModel $identityModel */
        $identityModel = model(UserIdentityModel::class);

        $refreshToken = $identityModel->getAccessTokenByRawToken($refreshTokenRaw);

        if (! $this->isValidRefreshToken($refreshToken)) {
            return $this->respondProblem(401, 'Authentication failed', 'Invalid or expired refresh token.');
        }

        $user = $refreshToken->user();

        if ($user === null) {
            return $this->respondProblem(401, 'Authentication failed', 'Invalid or expired refresh token.');
        }

        // Rotate refresh token to reduce replay risk.
        $user->revokeAccessToken($refreshTokenRaw);

        $tokens = $this->issueTokenPair($user, 'refresh');

        return $this->response->setJSON($tokens);
    }

    /**
     * Revoke one or more tokens.
     */
    public function revoke(): ResponseInterface
    {
        $payload = $this->request->getJSON(true);
        $payload = is_array($payload) ? $payload : [];

        $accessTokenRaw = trim((string) ($payload['access_token'] ?? ''));
        $refreshTokenRaw = trim((string) ($payload['refresh_token'] ?? ''));

        if ($accessTokenRaw !== '') {
            $this->revokeRawToken($accessTokenRaw);
        }

        if ($refreshTokenRaw !== '') {
            $this->revokeRawToken($refreshTokenRaw);
        }

        if ($accessTokenRaw === '' && $refreshTokenRaw === '') {
            $bearer = $this->bearerTokenFromHeader();
            if ($bearer !== null) {
                $this->revokeRawToken($bearer);
            }
        }

        return $this->response->setStatusCode(204)->setBody('');
    }

    /**
     * @param object $user
     * @return array<string, mixed>
     */
    private function issueTokenPair(object $user, string $tokenNamePrefix): array
    {
        $now = Time::now();

        $accessToken = $user->generateAccessToken(
            $tokenNamePrefix . '-access',
            ['api:read'],
            $now->addSeconds(self::ACCESS_TTL_SECONDS),
        );

        $refreshToken = $user->generateAccessToken(
            $tokenNamePrefix . '-refresh',
            ['refresh'],
            $now->addSeconds(self::REFRESH_TTL_SECONDS),
        );

        return [
            'access_token' => (string) $accessToken->raw_token,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'refresh_token' => (string) $refreshToken->raw_token,
        ];
    }

    private function isValidRefreshToken(?AccessToken $token): bool
    {
        if ($token === null) {
            return false;
        }

        if (! $token->can('refresh')) {
            return false;
        }

        if ($token->expires !== null && Time::parse((string) $token->expires)->isBefore(Time::now())) {
            return false;
        }

        return true;
    }

    private function revokeRawToken(string $rawToken): void
    {
        /** @var UserIdentityModel $identityModel */
        $identityModel = model(UserIdentityModel::class);

        $token = $identityModel->getAccessTokenByRawToken($rawToken);

        if ($token === null) {
            return;
        }

        $user = $token->user();

        if ($user === null) {
            return;
        }

        $user->revokeAccessToken($rawToken);
    }

    private function bearerTokenFromHeader(): ?string
    {
        $header = trim((string) $this->request->getHeaderLine('Authorization'));

        if ($header === '' || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
