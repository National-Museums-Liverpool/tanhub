<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;

/**
 * @internal
 */
final class ApiV1AuthTokensTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureAuthTables();
        $this->seedAuthUser();
    }

    public function testTokenEndpointIssuesAccessAndRefreshTokens(): void
    {
        $result = $this->withBody(json_encode([
            'username' => 'api-user@example.com',
            'password' => 'Secret123!',
        ]))->post('api/v1/auth/token');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertIsArray($json);
        $this->assertNotEmpty($json['access_token']);
        $this->assertNotEmpty($json['refresh_token']);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertSame(3600, $json['expires_in']);
    }

    public function testTokenEndpointRejectsInvalidCredentials(): void
    {
        $result = $this->withBody(json_encode([
            'username' => 'api-user@example.com',
            'password' => 'WrongPassword',
        ]))->post('api/v1/auth/token');

        $result->assertStatus(401);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('Authentication failed', $json['title']);
    }

    public function testRefreshEndpointRotatesRefreshTokenAndReturnsNewPair(): void
    {
        $issued = $this->withBody(json_encode([
            'username' => 'api-user@example.com',
            'password' => 'Secret123!',
        ]))->post('api/v1/auth/token');

        $issuedJson = json_decode((string) $issued->response()->getBody(), true);

        $refresh = $this->withBody(json_encode([
            'refresh_token' => $issuedJson['refresh_token'],
        ]))->post('api/v1/auth/token/refresh');

        $refresh->assertStatus(200);

        $refreshJson = json_decode((string) $refresh->response()->getBody(), true);

        $this->assertNotSame($issuedJson['access_token'], $refreshJson['access_token']);
        $this->assertNotSame($issuedJson['refresh_token'], $refreshJson['refresh_token']);
    }

    public function testRefreshEndpointRejectsInvalidRefreshToken(): void
    {
        $result = $this->withBody(json_encode([
            'refresh_token' => 'invalid-token',
        ]))->post('api/v1/auth/token/refresh');

        $result->assertStatus(401);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('Authentication failed', $json['title']);
    }

    public function testRevokeEndpointRevokesAccessTokenFromBody(): void
    {
        $issued = $this->withBody(json_encode([
            'username' => 'api-user@example.com',
            'password' => 'Secret123!',
        ]))->post('api/v1/auth/token');

        $issuedJson = json_decode((string) $issued->response()->getBody(), true);

        $revoke = $this->withBody(json_encode([
            'access_token' => $issuedJson['access_token'],
        ]))->post('api/v1/auth/token/revoke');

        $revoke->assertStatus(204);

        /** @var UserIdentityModel $identityModel */
        $identityModel = model(UserIdentityModel::class);
        $token = $identityModel->getAccessTokenByRawToken($issuedJson['access_token']);

        $this->assertNull($token);
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

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_groups_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            "group" VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'auth_permissions_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            permission VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
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
