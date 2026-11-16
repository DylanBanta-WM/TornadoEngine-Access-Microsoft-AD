<?php

namespace TornadoEngine\AccessMicrosoftAd;

/**
 * Active Directory, as the sync and sign-in need it. LdapDirectory reads the real one.
 */
interface Directory
{
    /**
     * Every user account under the base DN, by email (mail, or userPrincipalName without one).
     *
     * @return array<string, array{email: string, name: string, dn: string, disabled: bool}>
     */
    public function users(): array;

    /**
     * The emails of a group's members, including members of groups inside it.
     *
     * @return list<string>
     */
    public function groupMembers(string $groupDn): array;

    /** The DN of a group, given its DN or its name. */
    public function findGroup(string $nameOrDn): ?string;

    public function exists(string $dn): bool;

    /** Null if the connection works, or what's wrong. */
    public function check(): ?string;

    /** The email of the enabled account this username, UPN or email and password sign in as, or null. */
    public function authenticate(string $login, string $password): ?string;
}
