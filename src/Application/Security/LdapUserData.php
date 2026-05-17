<?php

declare(strict_types=1);

namespace App\Application\Security;

/**
 * Carries the data extracted from an LDAP entry after successful bind.
 * Created in Infrastructure (LdapAuthenticator), consumed in Application (LdapUserResolver).
 */
final class LdapUserData
{
    /**
     * @param list<string> $memberOf DN strings of all groups the user belongs to
     */
    public function __construct(
        public readonly string $distinguishedName,
        public readonly string $email,
        public readonly ?string $displayName,
        public readonly array $memberOf,
    ) {
    }
}
