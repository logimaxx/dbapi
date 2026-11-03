<?php

require_once __DIR__ . '/../application/third_party/dbAPI/Autoloader.php';
dbAPI\Autoloader::register();

use dbAPI\Auth\ApiClients;
use PHPUnit\Framework\TestCase;

class TestApiClients extends TestCase
{
    public function testIssueAndVerify(): void
    {
        $claims = ApiClients::normalizeClaims('acme', ['role' => 'integration', 'company_id' => 4]);
        $issued = ApiClients::issue($claims, 900);
        $clients = ['acme' => $issued['row']];

        $row = ApiClients::verify($clients, 'acme', $issued['secret']);
        $this->assertIsArray($row);
        $access = ApiClients::accessClaims('acme', $row);
        $this->assertSame('acme', $access['client']);
        $this->assertSame('client', $access['login_method']);
        $this->assertSame('integration', $access['role']);
        $this->assertSame(4, $access['company_id']);
        $this->assertArrayNotHasKey('secret_hash', $access);
        $this->assertArrayNotHasKey('secret', ApiClients::publicClient('acme', $issued['row']));
    }

    public function testWrongSecretAndUnknownTokenFail(): void
    {
        $issued = ApiClients::issue(ApiClients::normalizeClaims('acme', ['role' => 'integration']), 900);
        $clients = ['acme' => $issued['row']];

        $this->assertNull(ApiClients::verify($clients, 'acme', 'not-the-secret'));
        $this->assertNull(ApiClients::verify($clients, 'other', $issued['secret']));
    }

    public function testDisabledClientFails(): void
    {
        $issued = ApiClients::issue(ApiClients::normalizeClaims('acme', []), 900, false);
        $this->assertNull(ApiClients::verify(['acme' => $issued['row']], 'acme', $issued['secret']));
        $this->assertFalse(ApiClients::publicClient('acme', $issued['row'])['enabled']);
    }

    public function testNewSecretReplacesTheOldOne(): void
    {
        $claims = ApiClients::normalizeClaims('acme', ['role' => 'viewer']);
        $first = ApiClients::issue($claims, 900);
        $second = ApiClients::issue($claims, 900, true);
        $clients = ['acme' => $second['row']];

        $this->assertNull(ApiClients::verify($clients, 'acme', $first['secret']));
        $this->assertIsArray(ApiClients::verify($clients, 'acme', $second['secret']));
    }

    public function testNormalizeClaimsDropsReservedKeysAndFillsIdentity(): void
    {
        $claims = ApiClients::normalizeClaims('acme', [
            'role' => 'integration',
            'exp' => 1,
            'secret' => 'nope',
        ]);
        $this->assertSame('acme', $claims['client']);
        $this->assertSame('acme', $claims['username']);
        $this->assertSame('acme', $claims['unm']);
        $this->assertSame('client', $claims['login_method']);
        $this->assertArrayNotHasKey('exp', $claims);
        $this->assertArrayNotHasKey('secret', $claims);
    }

    public function testRejectsNestedClaimsAndBadTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiClients::normalizeClaims('acme', ['role' => ['admin']]);
    }

    public function testRejectsBadToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiClients::assertToken('has space');
    }

    public function testValidityBounds(): void
    {
        $this->assertSame(900, ApiClients::normalizeValidity(null));
        $this->assertSame(60, ApiClients::normalizeValidity(60));
        $this->expectException(InvalidArgumentException::class);
        ApiClients::normalizeValidity(59);
    }
}
