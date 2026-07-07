<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * API rate limiting filter with separate anonymous and authenticated limits.
 */
class ApiRateLimit implements FilterInterface
{
    private const DEFAULT_ANON_CAPACITY = 20;
    private const DEFAULT_ANON_SECONDS = 20;
    private const DEFAULT_AUTH_CAPACITY = 60;
    private const DEFAULT_AUTH_SECONDS = 20;

    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $identity = $this->resolveIdentity($request);

        $capacity = $identity['authenticated']
            ? $this->getIntEnv('api.rateLimitAuthenticatedCapacity', self::DEFAULT_AUTH_CAPACITY)
            : $this->getIntEnv('api.rateLimitAnonymousCapacity', self::DEFAULT_ANON_CAPACITY);

        $seconds = $identity['authenticated']
            ? $this->getIntEnv('api.rateLimitAuthenticatedSeconds', self::DEFAULT_AUTH_SECONDS)
            : $this->getIntEnv('api.rateLimitAnonymousSeconds', self::DEFAULT_ANON_SECONDS);

        $bucketKey = $this->buildBucketKey($request->getUri()->getPath(), $identity['key']);

        $throttler = service('throttler');
        $allowed = $throttler->check($bucketKey, $capacity, $seconds);

        $response = service('response');
        $response->setHeader('X-RateLimit-Limit', (string) $capacity);

        if ($allowed) {
            $response->setHeader('X-RateLimit-Reset', (string) $seconds);

            return null;
        }

        $retryAfter = max(1, $throttler->getTokenTime());

        $response->setStatusCode(429)
            ->setHeader('Retry-After', (string) $retryAfter)
            ->setHeader('X-RateLimit-Reset', (string) $retryAfter)
            ->setContentType('application/problem+json')
            ->setBody((string) json_encode([
                'type' => 'https://api.tanhub.example/problems/rate-limit-exceeded',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => 'Rate limit exceeded. Retry after ' . $retryAfter . ' seconds.',
                'instance' => $request->getUri()->getPath(),
            ], JSON_UNESCAPED_SLASHES));

        return $response;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }

    /**
     * @return array{authenticated: bool, key: string}
     */
    private function resolveIdentity(RequestInterface $request): array
    {
        $header = trim((string) $request->getHeaderLine('Authorization'));

        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));

            if ($token !== '') {
                $result = auth('tokens')->check(['token' => $token]);

                if ($result->isOK()) {
                    $user = $result->extraInfo();

                    return [
                        'authenticated' => true,
                        'key' => 'user:' . (string) $user->id,
                    ];
                }
            }
        }

        return [
            'authenticated' => false,
            'key' => 'ip:' . $request->getIPAddress(),
        ];
    }

    private function getIntEnv(string $name, int $default): int
    {
        $value = env($name);

        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : $default;
    }

    private function buildBucketKey(string $path, string $identityKey): string
    {
        return 'api_v1_' . sha1($path . '|' . $identityKey);
    }
}
