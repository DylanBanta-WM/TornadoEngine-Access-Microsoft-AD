<?php

namespace TornadoEngine\AccessMicrosoftAd;

use App\Models\Setting;

/**
 * The domain controller connection, saved on the Active Directory tab.
 */
class Connection
{
    public const FIELDS = ['hosts', 'encryption', 'port', 'base_dn', 'username'];

    public static function configured(): bool
    {
        return Setting::get('ad.hosts') !== null;
    }

    /** @return array{hosts: string, encryption: string, port: string, base_dn: string, username: string, ca_cert: string} */
    public static function saved(): array
    {
        $saved = ['ca_cert' => (string) Setting::get('ad.ca_cert')];
        foreach (self::FIELDS as $field) {
            $saved[$field] = (string) Setting::get("ad.{$field}");
        }

        return $saved;
    }

    public static function rules(): array
    {
        return [
            'hosts' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.\-]+(\s*,\s*[a-z0-9.\-]+)*$/i'],
            'encryption' => ['required', 'in:ldaps,starttls'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'base_dn' => ['required', 'string', 'max:500', 'regex:/^[a-z]+=.+/i'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'ca_cert' => ['nullable', 'string', 'max:20000', 'regex:/-----BEGIN CERTIFICATE-----/'],
        ];
    }

    /** LdapRecord's settings, for what's saved (or for $data being checked). */
    public static function config(?array $data = null): array
    {
        $data ??= self::saved() + ['password' => Setting::getSecret('ad.password')];
        $ldaps = $data['encryption'] === 'ldaps';

        return [
            'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) $data['hosts'])))),
            'port' => (int) (($data['port'] ?? null) ?: ($ldaps ? 636 : 389)),
            'base_dn' => trim((string) $data['base_dn']),
            'username' => trim((string) $data['username']),
            'password' => (string) $data['password'],
            'use_tls' => $ldaps,
            'use_starttls' => ! $ldaps,
            'timeout' => 5,
            'follow_referrals' => false,
        ];
    }

    public static function save(array $data): void
    {
        foreach (self::FIELDS as $field) {
            Setting::put("ad.{$field}", trim((string) ($data[$field] ?? '')) ?: null);
        }
        Setting::put('ad.ca_cert', trim((string) ($data['ca_cert'] ?? '')) ?: null);
        Setting::putSecret('ad.password', (string) $data['password']);
    }

    public static function signInEnabled(): bool
    {
        return self::configured() && Setting::get('ad.signin') === '1';
    }
}
