<?php

declare(strict_types=1);

namespace Modules\Core\Email;

/**
 * Signed Reply-To local part:  reply+{tenant}.{type}-{id}_{signature}
 *
 *  tenant     the tenant UUID as 26 base32 characters (so the verifier can compute the HMAC without any lookup)
 *  type, id   the originating record, e.g. ticket-4821 (type 2-6 letters, id 1-7 letters/digits)
 *  signature  first 80 bits of HMAC-SHA256("v1|tenant|type-id") under a key derived from the application key
 *
 * Total length is at most 64 characters (the SMTP local-part limit). Everything is lower-case because mail
 * systems may change case. Pure: needs only a key.
 */
final class ReplyTokenSigner
{
    public const PREFIX = 'reply+';
    private const SIG_BYTES = 10;
    private const PATTERN = '/^reply\+([a-z2-7]{26})\.([a-z]{2,6})-([a-z0-9]{1,7})_([a-z2-7]{16})$/';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    public static function fromAppKey(#[\SensitiveParameter] string $appKey): self
    {
        return new self(hash('sha256', 'reply-to:v1|'.$appKey));
    }

    public static function isValidRef(string $type, string $id): bool
    {
        return preg_match('/^[a-z]{2,6}$/', $type) === 1 && preg_match('/^[a-z0-9]{1,7}$/', $id) === 1;
    }

    public function issue(string $tenantId, string $type, string $id): string
    {
        $tenantId = strtolower($tenantId);
        if (preg_match(self::UUID, $tenantId) !== 1 || ! self::isValidRef($type, $id)) {
            throw new \InvalidArgumentException('Invalid tenant or record reference for a reply address.');
        }

        return self::PREFIX.Base32::encode(hex2bin(str_replace('-', '', $tenantId))).".{$type}-{$id}_".Base32::encode($this->mac($tenantId, $type, $id));
    }

    /** @return array{tenant_id:string, record_type:string, record_id:string}|null null for anything not genuinely issued by this application */
    public function verify(string $localPart): ?array
    {
        if (strlen($localPart) > 64 || preg_match(self::PATTERN, strtolower($localPart), $m) !== 1) {
            return null;
        }
        $tenantBytes = Base32::decode($m[1]);
        $sig = Base32::decode($m[4]);
        if ($tenantBytes === null || strlen($tenantBytes) !== 16 || $sig === null || strlen($sig) !== self::SIG_BYTES) {
            return null;
        }
        $hex = bin2hex($tenantBytes);
        $tenantId = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);

        if (! hash_equals($this->mac($tenantId, $m[2], $m[3]), $sig)) {
            return null;
        }

        return ['tenant_id' => $tenantId, 'record_type' => $m[2], 'record_id' => $m[3]];
    }

    private function mac(string $tenantId, string $type, string $id): string
    {
        return substr(hash_hmac('sha256', "v1|{$tenantId}|{$type}-{$id}", $this->key, true), 0, self::SIG_BYTES);
    }
}
