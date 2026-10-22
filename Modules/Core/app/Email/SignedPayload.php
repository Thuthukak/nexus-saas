<?php

declare(strict_types=1);

namespace Modules\Core\Email;

/**
 * Tamper-proof, expiring state for redirect round trips (HMAC-SHA256, in the style of Laravel signed URLs).
 * Pure: takes a key. The purpose is mixed into the key so a token minted for one flow can never be used in another.
 */
final class SignedPayload
{
    public function __construct(#[\SensitiveParameter] private readonly string $key, private readonly string $purpose)
    {
    }

    public static function fromAppKey(#[\SensitiveParameter] string $appKey, string $purpose): self
    {
        return new self(hash('sha256', 'signed-payload:v1|'.$appKey), $purpose);
    }

    /** @param array<string, scalar> $data */
    public function encode(array $data, int $expiresAt): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($data + ['exp' => $expiresAt], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $body.'.'.$this->sign($body);
    }

    /** @return array<string, mixed>|null null when malformed, forged, or expired */
    public function decode(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || ! isset($data['exp']) || ! is_int($data['exp']) || $data['exp'] < ($now ?? time())) {
            return null;
        }

        return $data;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $this->purpose.'|'.$body, $this->key);
    }
}
