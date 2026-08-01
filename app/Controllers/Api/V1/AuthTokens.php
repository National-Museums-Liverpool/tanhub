<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\AccessToken;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * API endpoints for issuing, refreshing, and revoking bearer tokens used against `api/v1/*`.
 *
 * Serves `POST api/v1/auth/token`, `auth/token/refresh`, and `auth/token/revoke` (see
 * `app/Config/Routes.php`); these routes are public (protected only by the `cors` and
 * `api-rate-limit` filters, see {@see \App\Filters\ApiRateLimit}), since obtaining a token
 * requires only valid username/password credentials. Tokens issued here do not gate access
 * to read endpoints under {@see ApiResourceController} — they only grant the higher
 * authenticated rate-limit tier. Access and refresh tokens are both implemented as
 * CodeIgniter Shield access tokens ({@see \CodeIgniter\Shield\Entities\AccessToken})
 * distinguished by scope (`api:read` vs `refresh`).
 */
class AuthTokens extends ApiController
{
    /**
     * Lifetime, in seconds, of an issued access token (1 hour).
     */
    private const ACCESS_TTL_SECONDS = 3600;

    /**
     * Lifetime, in seconds, of an issued refresh token (30 days).
     */
    private const REFRESH_TTL_SECONDS = 2592000;

    /**
     * Issue an access/refresh token pair for valid username and password credentials.
     *
     * @return ResponseInterface Token pair JSON body ({@see self::issueTokenPair()}) on success;
     *                           a 400 problem response for a missing/invalid JSON body or missing
     *                           credentials; or a 401 problem response for invalid credentials.
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
     * Rotate a valid refresh token for a new access/refresh token pair.
     *
     * The presented refresh token is revoked immediately after being validated (before the
     * new pair is issued) so that a refresh token can only be used once, reducing the impact
     * of a leaked/replayed token.
     *
     * @return ResponseInterface New token pair JSON body on success; a 400 problem response
     *                           for a missing/invalid JSON body or missing `refresh_token`; or
     *                           a 401 problem response if the token is invalid, expired, or not
     *                           scoped for refresh.
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
     * Revoke one or more tokens: an explicit access/refresh token from the request body,
     * or (if neither is supplied) the bearer token from the `Authorization` header.
     *
     * Unknown/already-revoked tokens are silently ignored (no error is raised) to avoid
     * leaking whether a given token value ever existed; the response is always 204 regardless
     * of whether any token was actually found and revoked.
     *
     * @return ResponseInterface Empty 204 No Content response.
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
     * Generate and persist a new access/refresh token pair for a user.
     *
     * @param object $user             Authenticated Shield user entity to issue tokens for.
     * @param string $tokenNamePrefix  Prefix used to name the generated tokens (e.g. `'refresh'`
     *                                 or the submitted username), for identification in the
     *                                 user's token list; has no bearing on token validity.
     * @return array<string, mixed> Token pair body with keys `access_token`, `token_type`,
     *                              `expires_in`, and `refresh_token`.
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

    /**
     * Determine whether a refresh token is present, scoped for refresh, and not expired.
     *
     * @param AccessToken|null $token Token looked up by raw value, or null if not found.
     * @return bool True if the token exists, has the `refresh` scope, and has not expired.
     */
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

    /**
     * Look up a raw token value and revoke it on its owning user, if found.
     *
     * @param string $rawToken Raw (unhashed) token value as presented by the client.
     */
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

    /**
     * Extract the raw bearer token from the request's `Authorization` header, if present.
     *
     * @return string|null Raw token value, or null if the header is missing or not a `Bearer` scheme.
     */
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
