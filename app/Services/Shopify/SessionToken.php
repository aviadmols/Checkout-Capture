<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use RuntimeException;

/**
 * App Bridge ID tokens: HS256 JWTs signed with the app's client secret, valid ~1 minute.
 * https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 */
class SessionToken
{
    private const LEEWAY_SECONDS = 10;

    /**
     * @return array{shop: string, claims: array<string, mixed>}
     *
     * @throws RuntimeException when the token is malformed, unsigned by us, expired or for another app
     */
    public static function verify(string $jwt): array
    {
        $secret = (string) config('shopify.api_secret');
        $parts = explode('.', $jwt);

        if ($secret === '' || count($parts) !== 3) {
            throw new RuntimeException('Malformed session token.');
        }

        [$header64, $payload64, $signature64] = $parts;
        $header = json_decode(self::base64UrlDecode($header64), true);
        $claims = json_decode(self::base64UrlDecode($payload64), true);

        if (($header['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            throw new RuntimeException('Unsupported session token.');
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', "{$header64}.{$payload64}", $secret, true));
        if (! hash_equals($expected, $signature64)) {
            throw new RuntimeException('Session token signature mismatch.');
        }

        $now = time();
        if (($claims['exp'] ?? 0) < $now - self::LEEWAY_SECONDS || ($claims['nbf'] ?? 0) > $now + self::LEEWAY_SECONDS) {
            throw new RuntimeException('Session token expired or not yet valid.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array(config('shopify.api_key'), $audience, true)) {
            throw new RuntimeException('Session token is for another app.');
        }

        $dest = parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST);
        $iss = parse_url((string) ($claims['iss'] ?? ''), PHP_URL_HOST);
        if (! $dest || $dest !== $iss || ! str_ends_with($dest, '.myshopify.com')) {
            throw new RuntimeException('Session token shop mismatch.');
        }

        return ['shop' => Shop::normalizeHost($dest), 'claims' => $claims];
    }

    /** Builds a token the way Shopify does — for tests and local tooling. */
    public static function issue(string $shopDomain, int $ttl = 60): string
    {
        $now = time();
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(json_encode([
            'iss' => "https://{$shopDomain}/admin",
            'dest' => "https://{$shopDomain}",
            'aud' => config('shopify.api_key'),
            'sub' => '1',
            'exp' => $now + $ttl,
            'nbf' => $now - 1,
            'iat' => $now,
            'jti' => bin2hex(random_bytes(8)),
            'sid' => bin2hex(random_bytes(8)),
        ]));
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", (string) config('shopify.api_secret'), true));

        return "{$header}.{$payload}.{$signature}";
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
