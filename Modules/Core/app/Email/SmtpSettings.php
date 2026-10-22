<?php

declare(strict_types=1);

namespace Modules\Core\Email;

/** A validated custom SMTP relay credential set. */
final readonly class SmtpSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        #[\SensitiveParameter] public string $password,
    ) {
    }

    /** @param array<string, mixed> $in @return array<string, list<string>> field => messages (empty when valid) */
    public static function errors(array $in): array
    {
        $e = [];
        if (! is_string($in['smtp_host'] ?? null) || trim($in['smtp_host']) === '' || strlen($in['smtp_host']) > 253) {
            $e['smtp_host'][] = 'The SMTP host is required.';
        }
        if (! is_numeric($in['smtp_port'] ?? null) || ! in_array((int) $in['smtp_port'], SmtpHostGuard::ALLOWED_PORTS, true)) {
            $e['smtp_port'][] = 'The SMTP port must be one of '.implode(', ', SmtpHostGuard::ALLOWED_PORTS).'.';
        }
        foreach (['smtp_username' => 255, 'smtp_password' => 500] as $f => $max) {
            $v = $in[$f] ?? null;
            if (! is_string($v) || $v === '' || strlen($v) > $max || preg_match('/[\r\n\x00]/', $v)) {
                $e[$f][] = 'The '.str_replace('_', ' ', $f).' is required.';
            }
        }

        return $e;
    }

    /** @param array<string, mixed> $in */
    public static function fromInput(array $in): self
    {
        return new self(strtolower(trim((string) $in['smtp_host'])), (int) $in['smtp_port'], (string) $in['smtp_username'], (string) $in['smtp_password']);
    }

    /** @return array<string, mixed> */
    public function toStored(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'password' => $this->password];
    }

    /** @param array<string, mixed> $s */
    public static function fromStored(array $s): self
    {
        return new self((string) $s['host'], (int) $s['port'], (string) $s['username'], (string) $s['password']);
    }
}
