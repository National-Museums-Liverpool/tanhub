<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Models\UserModel;

/**
 * @internal
 */
final class ApiV1RateLimitTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        \Config\Services::reset();
        $_SESSION = [];
        $_COOKIE = [];

        $this->ensureLookupTables();
        $this->seedLookupData();
        $this->ensureAuthTables();
        $this->seedAuthUser();

        $this->clearRateLimitBuckets('api/v1/data-sources');
    }

    public function testAnonymousRequestsAreRateLimited(): void
    {
        $anonymousLimit = $this->getRateLimitIntEnv('api.rateLimitAnonymousCapacity', 20);

        for ($i = 0; $i < $anonymousLimit; $i++) {
            $result = $this->get('api/v1/data-sources');
            $result->assertStatus(200);
        }

        $blocked = $this->get('api/v1/data-sources');

        $blocked->assertStatus(429);
        $blocked->assertHeader('Content-Type', 'application/problem+json; charset=UTF-8');
        $blocked->assertHeader('X-RateLimit-Limit', (string) $anonymousLimit);
    }

    public function testAuthenticatedRequestsUseHigherLimit(): void
    {
        $authenticatedLimit = $this->getRateLimitIntEnv('api.rateLimitAuthenticatedCapacity', 60);

        $issued = $this->withBody(json_encode([
            'username' => 'api-user@example.com',
            'password' => 'Secret123!',
        ]))->post('api/v1/auth/token');

        $issued->assertStatus(200);
        $json = json_decode((string) $issued->response()->getBody(), true);
        $accessToken = (string) $json['access_token'];

        for ($i = 0; $i < 21; $i++) {
            $result = $this->withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
            ])->get('api/v1/data-sources');

            $result->assertStatus(200);
            $result->assertHeader('X-RateLimit-Limit', (string) $authenticatedLimit);
        }
    }

    private function getRateLimitIntEnv(string $name, int $default): int
    {
        $value = env($name);

        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : $default;
    }

    private function clearRateLimitBucket(string $path, string $identity): void
    {
        service('throttler')->remove('api_v1_' . sha1($path . '|' . $identity));
    }

    private function clearRateLimitBuckets(string $path): void
    {
        $pathVariants = [
            ltrim($path, '/'),
            '/' . ltrim($path, '/'),
        ];

        $identities = [
            'ip:127.0.0.1',
            'ip:::1',
            'ip:0.0.0.0',
            'user:1',
        ];

        foreach ($pathVariants as $pathVariant) {
            foreach ($identities as $identity) {
                $this->clearRateLimitBucket($pathVariant, $identity);
            }
        }
    }

    private function ensureLookupTables(): void
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            abbr VARCHAR(10) NOT NULL,
            title VARCHAR(100) NOT NULL,
            url VARCHAR(100) NOT NULL
        )');
    }

    private function seedLookupData(): void
    {
        $db = db_connect();

        $exists = $db->table('data_sources')
            ->where('abbr', 'NBN')
            ->countAllResults() > 0;
        if (!$exists) {
            $db->table('data_sources')->insert([
                'id' => 1,
                'abbr' => 'NBN',
                'title' => 'NBN Atlas',
                'url' => 'https://nbnatlas.org',
            ]);
        }

    }

    private function ensureAuthTables(): void
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(30) NULL,
            status VARCHAR(255) NULL,
            status_message VARCHAR(255) NULL,
            active INTEGER NOT NULL DEFAULT 1,
            last_active DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_identities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type VARCHAR(255) NOT NULL,
            name VARCHAR(255) NULL,
            secret VARCHAR(255) NOT NULL,
            secret2 VARCHAR(255) NULL,
            expires DATETIME NULL,
            extra TEXT NULL,
            force_reset INTEGER NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_logins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) NULL,
            id_type VARCHAR(255) NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            user_id INTEGER NULL,
            date DATETIME NOT NULL,
            success INTEGER NOT NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_token_logins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) NULL,
            id_type VARCHAR(255) NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            user_id INTEGER NULL,
            date DATETIME NOT NULL,
            success INTEGER NOT NULL
        )');
    }

    private function seedAuthUser(): void
    {
        $db = db_connect();
        $db->table('auth_identities')->emptyTable();
        $db->table('users')->emptyTable();

        /** @var UserModel $users */
        $users = model(UserModel::class);

        $user = $users->createNewUser([
            'username' => 'api-user',
            'email' => 'api-user@example.com',
            'password' => 'Secret123!',
        ]);

        $users->save($user);
    }
}
