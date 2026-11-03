<?php

namespace dbAPI\Auth;

/**
 * API clients: public token + hashed secret, static JWT claims.
 * The data plane still receives a normal access JWT.
 */
class ApiClients
{
    public const FILE = 'api_clients.php';
    public const DEFAULT_VALIDITY = 900;
    public const MIN_VALIDITY = 60;
    public const MAX_VALIDITY = 86400;

    public static function assertToken(string $token): string
    {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/', $token)) {
            throw new \InvalidArgumentException('Invalid client token');
        }
        return $token;
    }

    /**
     * @param mixed $value
     */
    public static function normalizeValidity($value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_VALIDITY;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            throw new \InvalidArgumentException('Invalid client validity');
        }
        $validity = (int) $value;
        if ($validity < self::MIN_VALIDITY || $validity > self::MAX_VALIDITY) {
            throw new \InvalidArgumentException('Client validity must be between 60 and 86400 seconds');
        }
        return $validity;
    }

    /**
     * @param array<mixed> $claims
     * @return array<string, scalar>
     */
    public static function normalizeClaims(string $token, array $claims): array
    {
        $reserved = [
            'exp' => true,
            'iat' => true,
            'nbf' => true,
            'secret' => true,
            'secret_hash' => true,
        ];
        $out = [];
        foreach ($claims as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', $key)) {
                throw new \InvalidArgumentException('Invalid claim name');
            }
            if (isset($reserved[$key])) {
                continue;
            }
            if (is_string($value)) {
                if (strlen($value) > 200) {
                    throw new \InvalidArgumentException('Invalid claim value');
                }
                $out[$key] = $value;
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                $out[$key] = $value;
                continue;
            }
            throw new \InvalidArgumentException('Invalid claim value');
        }
        if (count($out) > 20) {
            throw new \InvalidArgumentException('Too many claims');
        }
        $out['client'] = $token;
        if (!isset($out['username']) && !isset($out['unm'])) {
            $out['username'] = $token;
            $out['unm'] = $token;
        } elseif (!isset($out['unm'])) {
            $out['unm'] = (string) $out['username'];
        } elseif (!isset($out['username'])) {
            $out['username'] = (string) $out['unm'];
        }
        $out['login_method'] = 'client';
        return $out;
    }

    /**
     * @param array<string, scalar> $claims
     * @return array{row: array<string, mixed>, secret: string}
     */
    public static function issue(array $claims, int $validity, bool $enabled = true): array
    {
        $secret = bin2hex(random_bytes(32));
        $hash = password_hash($secret, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new \RuntimeException('Could not hash client secret');
        }
        return [
            'row' => [
                'secret_hash' => $hash,
                'claims' => $claims,
                'validity' => $validity,
                'enabled' => $enabled,
            ],
            'secret' => $secret,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $clients
     * @return array<string, mixed>|null
     */
    public static function verify(array $clients, string $token, string $secret): ?array
    {
        $row = $clients[$token] ?? null;
        $hash = is_array($row) ? (string) ($row['secret_hash'] ?? '') : '';
        if (!is_array($row) || $hash === '') {
            password_verify($secret, self::dummyHash());
            return null;
        }
        if (!password_verify($secret, $hash)) {
            return null;
        }
        if (empty($row['enabled'])) {
            return null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, scalar>
     */
    public static function accessClaims(string $token, array $row): array
    {
        $claims = is_array($row['claims'] ?? null) ? $row['claims'] : [];
        unset($claims['exp'], $claims['iat'], $claims['nbf'], $claims['secret'], $claims['secret_hash']);
        $claims['client'] = $token;
        $claims['login_method'] = 'client';
        return $claims;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{token: string, claims: array, validity: int, enabled: bool}
     */
    public static function publicClient(string $token, array $row): array
    {
        return [
            'token' => $token,
            'claims' => is_array($row['claims'] ?? null) ? $row['claims'] : [],
            'validity' => (int) ($row['validity'] ?? self::DEFAULT_VALIDITY),
            'enabled' => !empty($row['enabled']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $clients
     * @return list<array{token: string, claims: array, validity: int, enabled: bool}>
     */
    public static function publicList(array $clients): array
    {
        $out = [];
        foreach ($clients as $token => $row) {
            if (!is_string($token) || !is_array($row)) {
                continue;
            }
            $out[] = self::publicClient($token, $row);
        }
        return $out;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('dbapi-api-client-dummy', PASSWORD_DEFAULT);
        }
        return $hash;
    }
}
