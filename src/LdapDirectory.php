<?php

namespace TornadoEngine\AccessMicrosoftAd;

use LdapRecord\Connection as LdapConnection;
use Throwable;

/**
 * Reads Active Directory over LDAPS (or STARTTLS) with the saved read-only service account.
 */
class LdapDirectory implements Directory
{
    private const PEOPLE = '(&(objectCategory=person)(objectClass=user))';

    private const ATTRIBUTES = ['mail', 'userprincipalname', 'displayname', 'distinguishedname', 'useraccountcontrol'];

    private ?LdapConnection $connection = null;

    public function __construct(private ?array $data = null) {}

    public function users(): array
    {
        $users = [];
        foreach ($this->connection()->query()->rawFilter(self::PEOPLE)->select(self::ATTRIBUTES)->paginate(1000) as $entry) {
            if ($user = $this->user($entry)) {
                $users[$user['email']] = $user;
            }
        }

        return $users;
    }

    public function groupMembers(string $groupDn): array
    {
        $entries = $this->connection()->query()->rawFilter(self::PEOPLE)
            ->whereEquals('memberof:1.2.840.113556.1.4.1941:', $groupDn) // in chain: nested groups count
            ->select(self::ATTRIBUTES)->paginate(1000);

        return array_values(array_filter(array_map(fn ($entry) => $this->user($entry)['email'] ?? null, $entries)));
    }

    public function findGroup(string $nameOrDn): ?string
    {
        if ($this->exists($nameOrDn)) {
            return $nameOrDn;
        }

        $found = $this->connection()->query()->rawFilter('(objectClass=group)')
            ->orFilter(fn ($query) => $query->whereEquals('cn', $nameOrDn)->whereEquals('samaccountname', $nameOrDn))
            ->select(['distinguishedname'])->limit(2)->get();
        unset($found['count']);

        return count($found) === 1 ? ($found[0]['dn'] ?? null) : null;
    }

    public function exists(string $dn): bool
    {
        if (! str_contains($dn, '=')) {
            return false;
        }

        try {
            return $this->connection()->query()->find($dn, ['distinguishedname']) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function check(): ?string
    {
        try {
            $connection = $this->connection();
            if (! $connection->query()->find($connection->getConfiguration()->get('base_dn'), ['distinguishedname'])) {
                return __('Connected, but the base DN wasn\'t found.');
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();

            return match (true) {
                str_contains($message, 'Invalid credentials') => __('The domain controller didn\'t accept the service account\'s username and password.'),
                str_contains($message, 'contact LDAP server') => __('Couldn\'t reach the domain controller, or its certificate isn\'t trusted (add your CA certificate).'),
                default => __('Couldn\'t connect: :reason', ['reason' => $message]),
            };
        }

        return null;
    }

    public function authenticate(string $login, string $password): ?string
    {
        if (trim($login) === '' || $password === '') {
            return null; // An empty password would be an anonymous bind.
        }

        $found = $this->connection()->query()->rawFilter(self::PEOPLE)
            ->orFilter(fn ($query) => $query->whereEquals('samaccountname', $login)->whereEquals('userprincipalname', $login)->whereEquals('mail', $login))
            ->select(self::ATTRIBUTES)->limit(2)->get();
        unset($found['count']);

        $user = count($found) === 1 ? $this->user($found[0]) : null;
        if (! $user || $user['disabled']) {
            return null;
        }

        try {
            return $this->connection()->auth()->attempt($user['dn'], $password) ? $user['email'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{email: string, name: string, dn: string, disabled: bool}|null */
    private function user(array $entry): ?array
    {
        $email = strtolower((string) ($entry['mail'][0] ?? $entry['userprincipalname'][0] ?? ''));
        if (! str_contains($email, '@')) {
            return null;
        }

        return [
            'email' => $email,
            'name' => (string) ($entry['displayname'][0] ?? ''),
            'dn' => (string) ($entry['dn'] ?? $entry['distinguishedname'][0] ?? ''),
            'disabled' => ((int) ($entry['useraccountcontrol'][0] ?? 0) & 2) === 2,
        ];
    }

    private function connection(): LdapConnection
    {
        if ($this->connection) {
            return $this->connection;
        }

        $config = Connection::config($this->data);
        $ca = $this->data['ca_cert'] ?? Connection::saved()['ca_cert'];

        // Trust the district's own CA, if it gave one; otherwise the system's.
        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            if ($ca) {
                $file = storage_path('app/access-microsoft-ad/ca.pem');
                @mkdir(dirname($file), 0700, true);
                file_put_contents($file, $ca);
                ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $file);
            }
        }

        $this->connection = new LdapConnection($config);
        $this->connection->connect();

        return $this->connection;
    }
}
